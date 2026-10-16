<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\BackendUser;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ProcessingResult;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ValueCompensation;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalProcessing;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSignals;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;

/**
 * The page "Widerruf bearbeiten" (D-3, spec 6.3): the declaration as it
 * arrived, the dates that matter, and the operator's actions — accept or
 * reject, record the return, book the refund.
 *
 * A page of its own rather than an editable mask: the declaration must never
 * change (it is the proof of the confirmation of receipt), and a DC_Table mask
 * half readonly and half editable, per state, would be a lock to get right in
 * every corner (versions, "Mehrere bearbeiten", copy). Here nothing edits the
 * declaration, because nothing on the page can.
 *
 * Like "Testmail senden": GET renders inside the back end frame, POST acts,
 * reports through Message and redirects to the same page (POST, redirect,
 * GET). Contao's RequestTokenListener checks the POST; the order's
 * UpdateAction guards every action.
 *
 * Operator input that reaches a message is escaped — Contao prints messages as
 * HTML (see MailTransportTestController).
 */
#[Autoconfigure(public: true)]
final class WithdrawalBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_withdrawal_process.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly WithdrawalProcessing $processing,
        private readonly WithdrawalWindowReader $windows,
        private readonly MoneyFormatter $money,
        private readonly Environment $twig,
        private readonly WithdrawalSignals $signals,
        private readonly UrlGeneratorInterface $router,
        private readonly ValueCompensation $compensations,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        $id = (int) $dc->id;
        $withdrawal = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_withdrawal WHERE id = ?', [$id]) : false;
        $order = false === $withdrawal ? false : $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [(int) $withdrawal['pid']]);

        if (false === $withdrawal || false === $order) {
            throw new NotFoundHttpException();
        }

        // Every action changes the order's balance — an update of the order.
        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_withdrawal'] ?? [];

        if (null !== $request && $request->isMethod('POST')) {
            $post = $request->request;
            $now = time();

            $result = match ($post->getString('consho_action')) {
                'accept' => $this->processing->accept($id, self::strings($post->all('accepted')), $now),
                'reject' => $this->processing->reject($id, $post->getString('reason'), $now),
                'return' => $this->processing->recordReturn($id, self::date($post->getString('returnReceivedAt')), self::strings($post->all('returned')), $now, self::restock($post->all('restockShown'), $post->all('restock')), $this->userId()),
                'refund' => $this->processing->refund($id, $post->getString('shipping'), $now, $post->has('compensation') ? $post->getString('compensation') : null, $post->has('vouchers') ? $post->getString('vouchers') : null),
                default => ProcessingResult::refused('unknown'),
            };

            $params = $result->params;

            // The range as the mask names it ("Rechnungskorrektur-Nummer"),
            // not its key.
            if ('numberRangeMissing' === $result->key) {
                $params = array_map(static fn (string $type): string => (string) ($GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'][$type] ?? $type), $params);
            }

            $text = \sprintf(
                (string) ($lang['result'][$result->key] ?? $result->key),
                ...array_map(static fn (string $p): string => StringUtil::specialchars($p), $params),
            );

            $message = $this->framework->getAdapter(Message::class);
            $result->done ? $message->addConfirmation($text) : $message->addError($text);

            throw new RedirectResponseException($request->getUri());
        }

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
            ...$this->view($withdrawal, $order),
        ]);
    }

    /**
     * @param array<string, mixed> $withdrawal
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function view(array $withdrawal, array $order): array
    {
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $currency = (string) $order['currency'];
        $orderId = (int) $order['id'];
        $withdrawalId = (int) $withdrawal['id'];

        // Block G: stockBooked says whether "ins Lager" applies to a line at all.
        $items = $this->connection->fetchAllAssociative('SELECT w.*, (SELECT i.stockBooked FROM tl_consho_order_item i WHERE i.id = w.orderItem) AS stockBooked FROM tl_consho_withdrawal_item w WHERE w.pid = ? ORDER BY w.id', [$withdrawalId]);
        $declared = [];

        foreach ($items as $item) {
            if ((bool) $item['declared']) {
                $declared[(int) $item['orderItem']] = (string) $item['quantity'];
            }
        }

        $elsewhere = array_map(strval(...), $this->connection->fetchAllKeyValue(
            "SELECT wi.orderItem, SUM(wi.acceptedQuantity) FROM tl_consho_withdrawal_item wi
               JOIN tl_consho_withdrawal w ON w.id = wi.pid
              WHERE w.pid = ? AND w.id != ? AND w.state = 'accepted'
              GROUP BY wi.orderItem",
            [$orderId, $withdrawalId],
        ));

        $goods = [];
        $names = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, productName, quantity, lineType FROM tl_consho_order_item WHERE pid = ? ORDER BY id', [$orderId]) as $row) {
            $names[(int) $row['id']] = (string) $row['productName'];

            if (!OrderLineType::stored((string) $row['lineType'])->isGoods()) {
                continue;
            }

            $itemId = (int) $row['id'];
            $left = BigDecimal::of((string) $row['quantity'])->minus($elsewhere[$itemId] ?? '0');

            // Prefilled with what the customer named; with everything left for
            // "the whole order"; with nothing for a part in free text — there
            // the operator reads the text and decides.
            $proposed = match ((string) $withdrawal['scope']) {
                'items' => $declared[$itemId] ?? '0',
                'all' => (string) $left,
                default => '0',
            };

            $goods[] = [
                'id' => $itemId,
                'name' => (string) $row['productName'],
                'ordered' => $this->money->quantity((string) $row['quantity'], $language),
                'declared' => isset($declared[$itemId]) ? $this->money->quantity($declared[$itemId], $language) : '',
                'left' => $this->money->quantity((string) BigDecimal::max(BigDecimal::zero(), $left), $language),
                'proposed' => (string) BigDecimal::of($proposed)->strippedOfTrailingZeros(),
            ];
        }

        $rows = array_map(fn (array $item): array => [
            'id' => (int) $item['id'],
            'name' => $names[(int) $item['orderItem']] ?? '',
            'accepted' => $this->money->quantity((string) $item['acceptedQuantity'], $language),
            'returned' => (string) BigDecimal::of((string) $item['returnedQuantity'])->strippedOfTrailingZeros(),
            // Block G (G9): only a line the purchase took out of stock can go back.
            'restockable' => BigDecimal::of((string) ($item['stockBooked'] ?? '0'))->isPositive(),
            'restock' => (bool) ($item['restock'] ?? true),
        ], array_values(array_filter($items, static fn (array $item): bool => BigDecimal::of((string) $item['acceptedQuantity'])->isPositive())));

        $window = $this->windows->of($orderId);
        $signals = $this->signals->of($withdrawal, time());
        $received = (int) $withdrawal['receivedAt'];
        $proposal = $this->processing->proposal($withdrawalId);
        $state = (string) $withdrawal['state'];

        // Abo-8, P80: the subscriptions bought with this order, and the
        // calculation behind the value compensation (P79).
        $this->framework->getAdapter(System::class)->loadLanguageFile('tl_consho_subscription');
        $subscriptions = array_map(
            fn (array $row): array => [
                'number' => (string) $row['subscriptionNumber'],
                'state' => (string) ($GLOBALS['TL_LANG']['tl_consho_subscription']['states'][$row['state']] ?? $row['state']),
                'url' => $this->router->generate('contao_backend', ['do' => 'consho_subscription', 'act' => 'show', 'id' => (int) $row['id']]),
            ],
            $this->connection->fetchAllAssociative('SELECT id, subscriptionNumber, state FROM tl_consho_subscription WHERE firstOrder = ? ORDER BY id', [$orderId]),
        );

        $compensation = $this->compensations->proposal($withdrawalId);
        $compensationLines = array_map(
            fn (array $line): array => [
                'name' => $line['name'],
                'text' => '' !== $line['reason']
                    ? (string) ($GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationReasons'][$line['reason']] ?? $line['reason'])
                    : \sprintf(
                        (string) ($GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationLine'] ?? '%1$s × %2$d / %3$d = %4$s'),
                        $this->money->format($line['gross'], $currency, $language),
                        $line['days'],
                        $line['periodDays'],
                        $this->money->format($line['amount'], $currency, $language),
                    ),
            ],
            $compensation['lines'],
        );

        return [
            'withdrawal' => [
                'number' => (string) $withdrawal['number'],
                'orderNumber' => (string) $order['orderNumber'],
                'receivedAt' => $this->moment($received, $language),
                'source' => (string) ($GLOBALS['TL_LANG']['tl_consho_withdrawal']['sources'][$withdrawal['source']] ?? $withdrawal['source']),
                'name' => (string) $withdrawal['name'],
                'email' => (string) $withdrawal['email'],
                'scope' => (string) $withdrawal['scope'],
                'part' => (string) ($withdrawal['part'] ?? ''),
                'message' => (string) ($withdrawal['message'] ?? ''),
                'state' => $state,
                'reason' => (string) ($withdrawal['reason'] ?? ''),
                'decidedAt' => null === $withdrawal['decidedAt'] ? '' : $this->moment((int) $withdrawal['decidedAt'], $language),
                'returnReceivedAt' => null === $withdrawal['returnReceivedAt'] ? '' : date('Y-m-d', (int) $withdrawal['returnReceivedAt']),
                'returnReceivedAtText' => null === $withdrawal['returnReceivedAt'] ? '' : $this->day((int) $withdrawal['returnReceivedAt'], $language),
                'refunded' => null !== $withdrawal['refundedAt'],
                'refundedAt' => null === $withdrawal['refundedAt'] ? '' : $this->moment((int) $withdrawal['refundedAt'], $language),
                'refundAmount' => null === $withdrawal['refundAmount'] ? '' : $this->money->format((string) $withdrawal['refundAmount'], $currency, $language),
                'refundDocument' => (int) $withdrawal['refundDocument'] > 0 ? (string) $this->connection->fetchOne('SELECT documentNumber FROM tl_consho_document WHERE id = ?', [(int) $withdrawal['refundDocument']]) : '',
            ],
            'dates' => [
                // A hint, not a judgement (spec 6.3): the operator weighs it.
                'windowClosesAt' => null === $window?->closesAt ? '' : $this->moment($window->closesAt, $language),
                // Rejected, nothing falls due — like the list (abnahme D-3).
                'refundDueAt' => 'rejected' === $state ? '' : $this->day(WithdrawalSignals::refundDue($received), $language),
                'overdue' => 'attention' === $signals['state'][0]->value,
            ],
            'signals' => WithdrawalSignals::html($signals),
            'orderUrl' => $this->router->generate('contao_backend', ['do' => 'consho_order', 'act' => 'edit', 'id' => $orderId]),
            'subscriptions' => $subscriptions,
            'goods' => $goods,
            'rows' => $rows,
            'refund' => [
                'goods' => $this->money->format($proposal['goods'], $currency, $language),
                'shipping' => null === $withdrawal['refundShipping'] ? '0' : (string) BigDecimal::of((string) $withdrawal['refundShipping'])->toScale(2),
                'shippingLeft' => $this->money->format($proposal['shippingLeft'], $currency, $language),
                'returned' => null !== $withdrawal['returnReceivedAt'],
                // DL4 (acceptance Abo-8): only goods come back — the hint on
                // holding back the refund only where a physical line is accepted.
                'physical' => false !== $this->connection->fetchOne(
                    "SELECT 1 FROM tl_consho_withdrawal_item wi
                       JOIN tl_consho_order_item i ON i.id = wi.orderItem
                      WHERE wi.pid = ? AND wi.acceptedQuantity > 0 AND i.kind = 'physical' LIMIT 1",
                    [$withdrawalId],
                ),
                // Shown where a service is withdrawn or an amount is set.
                'compensation' => [] !== $compensationLines || BigDecimal::of((string) ($withdrawal['valueCompensation'] ?? '0'))->isPositive()
                    ? (string) BigDecimal::of((string) ($withdrawal['valueCompensation'] ?? '0'))->toScale(2, RoundingMode::HalfUp)
                    : null,
                'compensationLines' => $compensationLines,
                // WG11: shown where vouchers paid; at most that goes back onto them.
                'vouchers' => BigDecimal::of($proposal['vouchers'])->isPositive() ? (string) BigDecimal::of($proposal['vouchers'])->toScale(2, RoundingMode::Down) : null,
                'vouchersMax' => $this->money->format($proposal['vouchers'], $currency, $language),
            ],
        ];
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<int, string>
     */
    /**
     * "ins Lager" per line (Block G, G9). A checkbox that is not ticked is not
     * sent at all, so the form names every line it showed one for in
     * `restockShown` — only those are decided here, the others keep theirs.
     *
     * @param array<mixed> $shown
     * @param array<mixed> $ticked
     *
     * @return array<int, bool>
     *
     * @internal public for the test only
     */
    public static function restock(array $shown, array $ticked): array
    {
        $restock = [];

        foreach (array_keys($shown) as $id) {
            $restock[(int) $id] = isset($ticked[$id]);
        }

        return $restock;
    }

    private function userId(): int
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? (int) $user->id : 0;
    }

    private static function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $key => $value) {
            if (\is_scalar($value)) {
                $strings[(int) $key] = (string) $value;
            }
        }

        return $strings;
    }

    private static function date(string $value): int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? 0 : $date->getTimestamp();
    }

    private function moment(int $timestamp, string $language): string
    {
        return (string) (new \IntlDateFormatter(str_replace('-', '_', $language), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, date_default_timezone_get()))->format($timestamp);
    }

    private function day(int $timestamp, string $language): string
    {
        return (string) (new \IntlDateFormatter(str_replace('-', '_', $language), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($timestamp);
    }
}
