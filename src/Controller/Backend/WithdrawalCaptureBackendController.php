<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Withdrawal\DbalWithdrawalRepository;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalDeclaration;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalOutcome;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalRecorder;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSource;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;

/**
 * The page "Widerruf erfassen": a withdrawal that reached the operator by
 * mail, letter or phone, recorded at the order it concerns.
 *
 * A withdrawal needs no particular form — the shop's function is one way, not
 * the only one. What came in another way is recorded here, once, through the
 * same recorder as the shop's: the same number, the same check of what is
 * left, the same confirmation of receipt if the operator wants it sent.
 *
 * Two steps, because the record cannot be changed afterwards (it is the proof
 * of what arrived): the form, then a summary to confirm. Nothing is stored
 * before the second POST. The moment of receipt is the operator's — when the
 * letter arrived, not when it was typed — and may not lie in the future or
 * before the order.
 *
 * Opened as `do=consho_order&key=captureWithdrawal&id=<order>`: on the order
 * table, so the id is the order's. Recording changes the order's balance-to-be,
 * so it needs the order's UpdateAction, like every action on "Widerruf
 * bearbeiten"; afterwards that page opens for the new withdrawal.
 */
#[Autoconfigure(public: true)]
final class WithdrawalCaptureBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_withdrawal_capture.html.twig';

    /** A clock a minute ahead of the server's is not "in the future". */
    private const CLOCK_SLACK = 60;

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly WithdrawalRecorder $recorder,
        private readonly DbalWithdrawalRepository $withdrawals,
        private readonly WithdrawalWindowReader $windows,
        private readonly MoneyFormatter $money,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        $orderId = (int) $dc->id;
        $order = $orderId > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]) : false;

        if (false === $order) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            throw new AccessDeniedHttpException();
        }

        // The page runs under the order's data container: the withdrawal's
        // labels are not loaded unless asked for.
        $this->framework->getAdapter(System::class)->loadLanguageFile('tl_consho_withdrawal');

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_withdrawal'] ?? [];

        // D-5: no customer left to record for, and the confirmation of
        // receipt would go nowhere.
        if (null !== ($order['anonymizedAt'] ?? null)) {
            $this->framework->getAdapter(Message::class)->addError(\sprintf((string) ($lang['result']['captureAnonymized'] ?? '%s'), StringUtil::specialchars((string) $order['orderNumber'])));

            throw new RedirectResponseException($this->listOf($orderId));
        }

        // The newest withdrawal of the order when the summary was shown. A
        // second POST of the same summary (a double click) — or a withdrawal
        // through the shop in between — finds a newer one and records nothing.
        $latest = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM tl_consho_withdrawal WHERE pid = ?', [$orderId]);
        $goods = array_filter($this->withdrawals->goods($orderId), static fn (array $good): bool => BigDecimal::of($good['left'])->isPositive());
        $now = time();

        $input = self::defaults($order, $now);
        $errors = [];
        $step = 'form';

        if (null !== $request && $request->isMethod('POST')) {
            $input = self::read($request->request);
            $errors = $this->errors($input, $order, $goods, $now);
            $action = $request->request->getString('consho_action');

            if ([] === $errors && 'record' === $action) {
                if ($request->request->getInt('latest', -1) !== $latest) {
                    $this->framework->getAdapter(Message::class)->addError(\sprintf((string) ($lang['result']['captureChanged'] ?? '%s'), StringUtil::specialchars((string) $order['orderNumber'])));

                    throw new RedirectResponseException($this->listOf($orderId));
                }

                $this->record($order, $input, $lang);
            }

            if ([] === $errors && 'review' === $action) {
                $step = 'review';
            }
        }

        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $receivedAt = self::timestamp($input['receivedAt']);
        $window = $this->windows->of($orderId);

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
            'step' => $step,
            'order' => ['number' => (string) $order['orderNumber'], 'email' => (string) $order['email']],
            'input' => $input,
            'latest' => $latest,
            'errors' => $errors,
            'sources' => array_map(static fn (WithdrawalSource $source): string => $source->value, WithdrawalSource::byHand()),
            'goods' => array_map(
                fn (int $id, array $good): array => [
                    'id' => $id,
                    'name' => $good['name'],
                    'left' => $this->money->quantity($good['left'], $language),
                    'max' => (string) BigDecimal::of($good['left'])->strippedOfTrailingZeros(),
                    'whole' => $good['whole'],
                    'quantity' => $input['items'][$id] ?? '',
                ],
                array_keys($goods),
                $goods,
            ),
            'summary' => 'review' === $step ? [
                'receivedAt' => null === $receivedAt ? '' : (string) (new \IntlDateFormatter(str_replace('-', '_', $language), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, date_default_timezone_get()))->format($receivedAt),
                // Only the scope "items" validates the quantities (errors()):
                // under another scope a typed "2 Stk" stays in the input but
                // is neither recorded nor shown.
                'items' => 'items' === $input['scope'] ? array_map(
                    fn (int $id, string $quantity): array => ['name' => $goods[$id]['name'], 'quantity' => $this->money->quantity($quantity, $language)],
                    array_keys($input['items']),
                    $input['items'],
                ) : [],
                // M5: recorded all the same — the operator decides.
                'closed' => null === $window,
                'late' => null !== $window && null !== $receivedAt && $window->isLateAt($receivedAt),
            ] : null,
        ]);
    }

    /**
     * @param array<string, mixed>  $order
     * @param array<string, mixed>  $input
     * @param array<string, mixed>  $lang
     */
    private function record(array $order, array $input, array $lang): never
    {
        $scope = $input['scope'];

        $result = $this->recorder->recordByHand(new WithdrawalDeclaration(
            (int) $order['id'],
            WithdrawalSource::from($input['source']),
            $input['name'],
            'items' === $scope ? $input['items'] : [],
            'part' === $scope ? $input['part'] : '',
            $input['message'],
        ), (int) self::timestamp($input['receivedAt']), $input['confirm']);

        $message = $this->framework->getAdapter(Message::class);

        if (WithdrawalOutcome::Recorded !== $result->outcome || null === $result->id) {
            // Only what is left changed between the summary and the click.
            $message->addError((string) ($lang['captureErrors']['items'] ?? 'items'));

            throw new RedirectResponseException((string) $this->requests->getCurrentRequest()?->getUri());
        }

        $number = StringUtil::specialchars($result->number);

        match (true) {
            !$input['confirm'] => $message->addConfirmation(\sprintf((string) ($lang['result']['captured'] ?? '%s'), $number)),
            $result->confirmed => $message->addConfirmation(\sprintf((string) ($lang['result']['capturedConfirmed'] ?? '%s %s'), $number, StringUtil::specialchars((string) $order['email']))),
            default => $message->addError(\sprintf((string) ($lang['result']['capturedNotConfirmed'] ?? '%s'), $number)),
        };

        throw new RedirectResponseException($this->router->generate('contao_backend', [
            'do' => 'consho_order',
            'table' => 'tl_consho_withdrawal',
            'key' => 'processWithdrawal',
            'id' => $result->id,
        ]));
    }

    /**
     * @param array<string, mixed>                                                         $order
     * @param array<string, mixed>                                                         $input
     * @param array<int, array{name: string, quantity: string, left: string, whole: bool}> $goods
     *
     * @return array<string, string> field => message
     */
    private function errors(array $input, array $order, array $goods, int $now): array
    {
        $texts = $GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureErrors'] ?? [];
        $text = static fn (string $key): string => (string) ($texts[$key] ?? $key);
        $errors = [];

        $receivedAt = self::timestamp($input['receivedAt']);

        if (null === $receivedAt) {
            $errors['receivedAt'] = $text('receivedAt');
        } elseif ($receivedAt > $now + self::CLOCK_SLACK) {
            $errors['receivedAt'] = $text('future');
        } elseif ($receivedAt < (int) ($order['createdAt'] ?? 0)) {
            $errors['receivedAt'] = $text('beforeOrder');
        }

        if (null === WithdrawalSource::tryFrom($input['source']) || !\in_array(WithdrawalSource::from($input['source']), WithdrawalSource::byHand(), true)) {
            $errors['source'] = $text('source');
        }

        if ('' === $input['name']) {
            $errors['name'] = $text('name');
        }

        if ('part' === $input['scope'] && '' === $input['part']) {
            $errors['part'] = $text('part');
        }

        if ('items' === $input['scope']) {
            $valid = [] !== $input['items'];
            $partOfWhole = [];

            foreach ($input['items'] as $id => $quantity) {
                try {
                    $amount = BigDecimal::of($quantity);
                } catch (MathException) {
                    $valid = false;

                    break;
                }

                if (!isset($goods[$id]) || !$amount->isPositive() || $amount->isGreaterThan($goods[$id]['left'])) {
                    $valid = false;
                } elseif ($goods[$id]['whole'] && !$amount->isEqualTo($goods[$id]['left'])) {
                    // A subscription only whole (Abo-8): its own message,
                    // naming the line — the recorder would refuse it too, but
                    // only as "items". Plain text: the template escapes.
                    $partOfWhole[] = $goods[$id]['name'];
                }
            }

            if (!$valid) {
                $errors['items'] = $text('items');
            } elseif ([] !== $partOfWhole) {
                $errors['items'] = \sprintf($text('whole'), implode(', ', $partOfWhole));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array{receivedAt: string, source: string, name: string, scope: string, items: array<int, string>, part: string, message: string, confirm: bool}
     */
    private static function defaults(array $order, int $now): array
    {
        return [
            'receivedAt' => date('Y-m-d\TH:i', $now),
            'source' => WithdrawalSource::Email->value,
            'name' => trim(((string) ($order['firstname'] ?? '')).' '.((string) ($order['lastname'] ?? ''))),
            'scope' => 'all',
            'items' => [],
            'part' => '',
            'message' => '',
            'confirm' => true,
        ];
    }

    /**
     * @param InputBag<string> $post
     *
     * @return array{receivedAt: string, source: string, name: string, scope: string, items: array<int, string>, part: string, message: string, confirm: bool}
     */
    private static function read(InputBag $post): array
    {
        $scope = $post->getString('scope');
        $items = [];

        foreach ($post->all()['items'] ?? [] as $id => $quantity) {
            $quantity = str_replace(',', '.', trim((string) $quantity));

            // An empty field or a 0 names nothing.
            if ('' !== $quantity && !\in_array($quantity, ['0', '0.0'], true)) {
                $items[(int) $id] = $quantity;
            }
        }

        return [
            'receivedAt' => trim($post->getString('receivedAt')),
            'source' => $post->getString('source'),
            'name' => trim($post->getString('name')),
            'scope' => \in_array($scope, ['all', 'items', 'part'], true) ? $scope : 'all',
            'items' => $items,
            'part' => trim($post->getString('part')),
            'message' => trim($post->getString('message')),
            'confirm' => $post->has('confirm'),
        ];
    }

    /**
     * The order's list of withdrawals, where a refused capture leads.
     */
    private function listOf(int $orderId): string
    {
        return $this->router->generate('contao_backend', [
            'do' => 'consho_order',
            'table' => 'tl_consho_withdrawal',
            'id' => $orderId,
        ]);
    }

    /**
     * "2026-11-07T10:04" from the date-time field, in the server's zone.
     */
    private static function timestamp(string $value): int|null
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone(date_default_timezone_get()));

        return false === $date || $date->format('Y-m-d\TH:i') !== $value ? null : $date->getTimestamp();
    }
}
