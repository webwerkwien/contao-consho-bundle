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
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Complaint\ComplaintDeliveryDates;
use Webwerkwien\ContaoConshoBundle\Complaint\ComplaintProcessing;
use Webwerkwien\ContaoConshoBundle\Complaint\ReplacementDelivery;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ProcessingResult;

/**
 * The page "Reklamation entscheiden" (block Reklamation, RK-4, L8): the
 * complaint and its lines, and the operator's actions — decide a rescission,
 * price reduction, replacement (RK-5) or repair, reject, record the return,
 * book the refund, close (RK-6).
 *
 * Built like "Widerruf bearbeiten" (WithdrawalBackendController): GET renders
 * inside the back end frame, POST acts, reports through Message and redirects
 * to the same page. Every action changes the order's balance, so the order's
 * UpdateAction guards the page. Operator input that reaches a message is
 * escaped — Contao prints messages as HTML.
 */
#[Autoconfigure(public: true)]
final class ComplaintBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_complaint_process.html.twig';

    /** What the decision form's tiles may post. */
    private const CHOICES = ['rescission', 'reduction', 'replacement', 'repair', 'reject', 'close'];

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly ComplaintProcessing $processing,
        private readonly MoneyFormatter $money,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly ReplacementDelivery $replacements,
        private readonly DeliveryContents $contents,
        private readonly ComplaintDeliveryDates $deliveryDates,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        $id = (int) $dc->id;
        $complaint = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_complaint WHERE id = ?', [$id]) : false;
        $order = false === $complaint ? false : $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [(int) $complaint['pid']]);

        if (false === $complaint || false === $order) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_complaint'] ?? [];

        if (null !== $request && $request->isMethod('POST')) {
            $post = $request->request;
            $now = time();
            $action = $post->getString('consho_action');
            $close = false;

            // The decision form: one choice, saved with Contao's own buttons.
            if ('' === $action && ($post->has('save') || $post->has('saveNclose'))) {
                $choice = $post->getString('consho_choice');
                $action = \in_array($choice, self::CHOICES, true) ? $choice : 'choiceMissing';
                $close = $post->has('saveNclose');
            }

            $result = match ($action) {
                'rescission' => $this->processing->decide($id, 'rescission', self::strings($post->all('rescinded')), $now),
                'reduction' => $this->processing->decide($id, 'reduction', self::strings($post->all('reduction')), $now),
                'replacement' => $this->replace($id, self::strings($post->all('replacement')), $now),
                'withdrawDecision' => $this->processing->withdraw($id, $now),
                'repair' => $this->processing->decide($id, 'repair', [], $now),
                'reject' => $this->processing->reject($id, $post->getString('rejectReason'), $now, $this->userId()),
                'close' => $this->processing->close($id, $now),
                'replacementDelivery' => $this->replacements->create($id, $now),
                'return' => $this->processing->recordReturn($id, self::date($post->getString('returnReceivedAt')), self::strings($post->all('returned')), $now, WithdrawalBackendController::restock($post->all('restockShown'), $post->all('restock')), $this->userId()),
                'refund' => $this->processing->refund($id, $post->getString('shipping'), $now, $post->has('throughProvider'), $post->has('consho_confirmed') ? $post->getString('consho_confirmed') : null, self::ids($post->all()['foreign'] ?? []), $this->userName()),
                'choiceMissing' => ProcessingResult::refused('choiceMissing'),
                default => ProcessingResult::refused('unknown'),
            };

            // R5: through a provider, the amount is confirmed before anything
            // is booked — the same inputs again, with the amount shown.
            if ('confirmPayout' === $result->key) {
                return $this->confirmation($complaint, $order, $result, $request->getUri(), [
                    'shipping' => $post->getString('shipping'),
                ],self::ids($post->all()['foreign'] ?? []));
            }

            $params = $result->params;

            if ('numberRangeMissing' === $result->key) {
                $params = array_map(static fn (string $type): string => (string) ($GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'][$type] ?? $type), $params);
            }

            $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');

            foreach ($result->kinds as $position => $kind) {
                if (isset($params[$position])) {
                    $params[$position] = ProcessingResult::MONEY === $kind
                        ? $this->money->format($params[$position], (string) $order['currency'], $language)
                        : $this->money->quantity($params[$position], $language);
                }
            }

            $text = \sprintf(
                (string) ($lang['result'][$result->key] ?? $result->key),
                ...array_map(static fn (string $p): string => StringUtil::specialchars($p), $params),
            );

            $message = $this->framework->getAdapter(Message::class);
            $result->done ? $message->addConfirmation($text) : $message->addError($text);

            // RK-5, S2: the new replacement delivery opens in the delivery mask.
            if ('replacementCreated' === $result->key) {
                throw new RedirectResponseException($this->deliveryUrl((int) $order['id'], (int) $result->params[1], true));
            }

            throw new RedirectResponseException($close && $result->done ? $this->framework->getAdapter(System::class)->getReferer() : $request->getUri());
        }

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
            ...$this->view($complaint, $order),
        ]);
    }

    /**
     * @param array<string, mixed>  $complaint
     * @param array<string, mixed>  $order
     * @param array<string, string> $inputs
     * @param list<int>             $foreign
     */
    private function confirmation(array $complaint, array $order, ProcessingResult $result, string $action, array $inputs, array $foreign): string
    {
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $currency = (string) $order['currency'];
        [$provider, $manual, $recorded] = $result->params + [2 => '0'];

        return $this->twig->render(self::TEMPLATE, [
            'messages' => '',
            'action' => $action,
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $GLOBALS['TL_LANG']['tl_consho_complaint'] ?? [],
            ...$this->view($complaint, $order),
            'confirm' => [
                'amount' => $provider,
                'amountLabel' => $this->money->format($provider, $currency, $language),
                'manualLabel' => BigDecimal::of($manual)->isPositive() ? $this->money->format($manual, $currency, $language) : '',
                'recordedLabel' => BigDecimal::of($recorded)->isPositive() ? $this->money->format($recorded, $currency, $language) : '',
                'inputs' => $inputs,
                'foreign' => $foreign,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $complaint
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function view(array $complaint, array $order): array
    {
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $currency = (string) $order['currency'];
        $complaintId = (int) $complaint['id'];
        $proposal = $this->processing->proposal($complaintId);
        $remedy = (string) $complaint['remedy'];
        $decided = null !== $complaint['decidedAt'] && \in_array($remedy, ['rescission', 'reduction', 'replacement', 'repair'], true);
        $refunded = null !== $complaint['refundedAt'];
        $state = (string) $complaint['state'];
        // RK-5, S1: a replacement delivery binds the decision while it exists.
        $replaced = false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_delivery WHERE complaint = ?', [$complaintId]);

        $rows = [];
        $delivered = $this->deliveryDates->forComplaint($complaintId);
        $received = (int) $complaint['receivedAt'];

        // The replacement deliveries per order item, for the decision's table
        // (U10): shipped with its date, or still a draft.
        $replacements = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT di.orderItem, d.id, d.shippedAt, d.reportedAt FROM tl_consho_delivery d
               JOIN tl_consho_delivery_item di ON di.pid = d.id
              WHERE d.complaint = ? ORDER BY d.id',
            [$complaintId],
        ) as $delivery) {
            $replacements[(int) $delivery['orderItem']][] = [
                'shippedAt' => null === $delivery['reportedAt'] || (int) $delivery['shippedAt'] < 1 ? '' : $this->day((int) $delivery['shippedAt'], $language),
                'url' => $this->deliveryUrl((int) $order['id'], (int) $delivery['id'], null === $delivery['reportedAt']),
            ];
        }

        foreach ($this->connection->fetchAllAssociative(
            'SELECT c.*, i.productName, i.stockBooked, i.kind FROM tl_consho_complaint_item c
               JOIN tl_consho_order_item i ON i.id = c.orderItem
              WHERE c.pid = ? ORDER BY c.id',
            [$complaintId],
        ) as $item) {
            $rowId = (int) $item['id'];
            $line = $proposal['lines'][$rowId] ?? ['quantity' => '0', 'worth' => '0'];

            $rows[] = [
                'id' => $rowId,
                'name' => (string) $item['productName'],
                'quantity' => $this->money->quantity((string) $item['quantity'], $language),
                'defect' => (string) ($item['defect'] ?? ''),
                // RK-6, A6 (R9): when it was delivered, and how long before the complaint.
                'delivered' => array_map(fn (int $day): string => $this->deliveredLabel($day, $received, $language), $delivered[$rowId] ?? []),
                'most' => $this->money->quantity($line['quantity'], $language),
                'worth' => $this->money->format($line['worth'], $currency, $language),
                'rescinded' => (string) BigDecimal::of((string) $item['rescindedQuantity'])->strippedOfTrailingZeros(),
                'replaced' => (string) BigDecimal::of((string) $item['replacementQuantity'])->strippedOfTrailingZeros(),
                'replacements' => $replacements[(int) $item['orderItem']] ?? [],
                // The form proposes the complained quantity (U8).
                'quantityPlain' => (string) BigDecimal::of((string) $item['quantity'])->strippedOfTrailingZeros(),
                'reduction' => null === $item['reduction'] ? '' : (string) BigDecimal::of((string) $item['reduction'])->toScale(2, RoundingMode::HalfUp),
                // The decided reduction, for the decision's summary.
                'reductionLabel' => null === $item['reduction'] ? '' : $this->money->format((string) $item['reduction'], $currency, $language),
                'returned' => (string) BigDecimal::of((string) $item['returnedQuantity'])->strippedOfTrailingZeros(),
                // Only goods come back: no return for a service, digital
                // content or a voucher.
                'physical' => ProductKind::Physical->value === (string) ($item['kind'] ?? ''),
                // Block G (G9): only a line the purchase took out of stock can go
                // back; goods in for repair go back to the customer (RK-6, A1).
                'restockable' => 'repair' !== $remedy && BigDecimal::of((string) ($item['stockBooked'] ?? '0'))->isPositive(),
                // R6: defective goods, so off unless ticked.
                'restock' => (bool) $item['restock'],
            ];
        }

        return [
            'complaint' => [
                'number' => (string) $complaint['number'],
                'orderNumber' => (string) $order['orderNumber'],
                'receivedAt' => (int) $complaint['receivedAt'] > 0 ? $this->day((int) $complaint['receivedAt'], $language) : '',
                'note' => (string) ($complaint['note'] ?? ''),
                'rejectReason' => 'rejected' === $state ? (string) ($complaint['rejectReason'] ?? '') : '',
                'state' => $state,
                'remedy' => $remedy,
                'decided' => $decided,
                // L1: decided anew until the refund; never once resolved by hand or
                // rejected, nor while a replacement delivery exists (RK-5).
                'decidable' => !$refunded && !$replaced && !\in_array($state, ['resolved', 'rejected'], true),
                'decidedAt' => null === $complaint['decidedAt'] ? '' : $this->moment((int) $complaint['decidedAt'], $language),
                // U9: "Entscheidung zurücknehmen" while nothing was carried out.
                'withdrawable' => $decided && $this->processing->withdrawable($complaintId),
                // RK-6, A4: closed by hand unless a refund or, for a
                // replacement, the last reported delivery closes it (U8).
                'closable' => !\in_array($state, ['resolved', 'rejected'], true) && !\in_array($remedy, ['rescission', 'reduction', 'replacement'], true),
                'returnReceivedAt' => null === $complaint['returnReceivedAt'] ? '' : date('Y-m-d', (int) $complaint['returnReceivedAt']),
                'returnReceivedAtText' => null === $complaint['returnReceivedAt'] ? '' : $this->day((int) $complaint['returnReceivedAt'], $language),
                'refunded' => $refunded,
                'refundedAt' => $refunded ? $this->moment((int) $complaint['refundedAt'], $language) : '',
                'refundAmount' => null === $complaint['refundAmount'] ? '' : $this->money->format((string) $complaint['refundAmount'], $currency, $language),
                'refundDocument' => (int) $complaint['refundDocument'] > 0 ? (string) $this->connection->fetchOne('SELECT documentNumber FROM tl_consho_document WHERE id = ?', [(int) $complaint['refundDocument']]) : '',
            ],
            'orderUrl' => $this->router->generate('contao_backend', ['do' => 'consho_order', 'act' => 'edit', 'id' => (int) $order['id']]),
            // Where the shipping dates of the column "Lieferinformationen" come from.
            'deliveriesUrl' => $this->deliveryUrl((int) $order['id'], 0, false),
            // The complaint's items, in the module the page was opened from.
            'itemsUrl' => $this->router->generate('contao_backend', [
                'do' => 'consho_order' === $this->requests->getCurrentRequest()?->query->get('do') ? 'consho_order' : 'consho_complaint',
                'table' => 'tl_consho_complaint_item',
                'id' => $complaintId,
            ]),
            'rows' => $rows,
            // RK-5: whether another replacement delivery has anything left to
            // carry. The deliveries themselves are listed per row (U10).
            'replacement' => [
                'creatable' => 'replacement' === $remedy && null !== $complaint['decidedAt'] && !\in_array($state, ['resolved', 'rejected'], true) && [] !== $this->contents->replaceable($complaintId, 0),
            ],
            'refund' => [
                'goods' => $this->money->format($proposal['goods'], $currency, $language),
                'rescission' => 'rescission' === $remedy,
                // Goods come back with a rescission, a replacement (RK-5, S7)
                // and a repair (RK-6, A1); money only with a rescission or
                // reduction. Only with a line of physical goods.
                'returnable' => \in_array($remedy, ['rescission', 'replacement', 'repair'], true)
                    && [] !== array_filter($rows, static fn (array $row): bool => $row['physical']),
                'refundable' => \in_array($remedy, ['rescission', 'reduction'], true),
                'shipping' => (string) BigDecimal::of((string) $complaint['refundShipping'])->toScale(2, RoundingMode::HalfUp),
                'shippingLeft' => $this->money->format($proposal['shippingLeft'], $currency, $language),
                'vouchers' => BigDecimal::of($proposal['vouchers'])->isPositive() ? $this->money->format($proposal['vouchers'], $currency, $language) : null,
                'provider' => BigDecimal::of($proposal['provider'])->isPositive() ? $this->money->format($proposal['provider'], $currency, $language) : null,
                'providerMethods' => implode(', ', $proposal['providerMethods']),
                // Coming from "Geldflüsse", the refund chosen there is ticked
                // (Geldflüsse H1) — through the decision too, as the page
                // posts back to its own address.
                'outside' => array_map(fn (array $refund): array => [
                    'id' => $refund['id'],
                    'label' => \sprintf('%s (%s)', $this->money->format($refund['amount'], $refund['currency'], $language), $refund['reference']),
                    'chosen' => $refund['id'] === $this->requests->getCurrentRequest()?->query->getInt(WithdrawalBackendController::FOREIGN),
                ], $proposal['outside']),
            ],
        ];
    }

    /**
     * A draft opens in the delivery mask, a reported delivery in the order's
     * list of deliveries — it is locked.
     */
    /**
     * Deciding a replacement creates its delivery at once: like the other
     * remedies, the decision is one step, and the delivery is its detail.
     * Should the delivery be refused, the decision stands and the page offers
     * "Ersatzlieferung anlegen" with the reason.
     *
     * @param array<int, string> $quantities complaint item id => quantity to replace
     */
    private function replace(int $complaintId, array $quantities, int $now): ProcessingResult
    {
        // An empty form still names every line; [] would mean "all complained".
        $decided = $this->processing->decide($complaintId, 'replacement', [] === $quantities ? [0 => '0'] : $quantities, $now);

        return $decided->done ? $this->replacements->create($complaintId, $now) : $decided;
    }

    private function deliveryUrl(int $orderId, int $deliveryId, bool $draft): string
    {
        return $draft
            ? $this->router->generate('contao_backend', ['do' => 'consho_order', 'table' => 'tl_consho_delivery', 'act' => 'edit', 'id' => $deliveryId])
            : $this->router->generate('contao_backend', ['do' => 'consho_order', 'table' => 'tl_consho_delivery', 'id' => $orderId]);
    }

    private function userId(): int
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? (int) $user->id : 0;
    }

    private function userName(): string
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? (string) $user->username : '';
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<int, string>
     */
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

    /**
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn (mixed $value): int => \is_scalar($value) ? (int) $value : 0, $values), static fn (int $id): bool => $id > 0)));
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

    /**
     * "03.11.2026, 2 Tage vor Eingang" — the date alone where the complaint
     * has no receipt date.
     */
    private function deliveredLabel(int $day, int $received, string $language): string
    {
        $date = $this->day($day, $language);

        if ($received < 1) {
            return $date;
        }

        $days = ComplaintDeliveryDates::daysBefore($day, $received);
        $gap = $GLOBALS['TL_LANG']['tl_consho_complaint']['deliveredGap'] ?? [];
        $text = match (true) {
            0 === $days => (string) ($gap['same'] ?? ''),
            1 === $days => (string) ($gap['oneBefore'] ?? ''),
            $days > 1 => \sprintf((string) ($gap['before'] ?? '%d'), $days),
            -1 === $days => (string) ($gap['oneAfter'] ?? ''),
            default => \sprintf((string) ($gap['after'] ?? '%d'), -$days),
        };

        return '' === $text ? $date : $date.', '.$text;
    }

    private function day(int $timestamp, string $language): string
    {
        return (string) (new \IntlDateFormatter(str_replace('-', '_', $language), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($timestamp);
    }
}
