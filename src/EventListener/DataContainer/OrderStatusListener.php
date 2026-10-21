<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Contao\BackendUser;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\Order\CancellationInvoice;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderStatusRepository;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderCancellation;
use Webwerkwien\ContaoConshoBundle\Order\StatusRequirement;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The status field of an order (D-1, decision A1).
 *
 * Options: only the statuses of the order's own shop. Until D-1 the field
 * listed every status of every shop through its foreignKey — a second shop's
 * "Bezahlt" could be set on an order of the first.
 *
 * Save: a status whose flags the balance does not bear out is refused, with
 * what is still open. A save callback, not onsubmit: the exception is shown
 * at the field and the form stays (the pattern of ArchiveFolderListener).
 *
 * Only a CHANGE is checked. An order whose balance moved after its status was
 * set — a refund booked after "Abgeschlossen" — must stay editable in its
 * other fields; the operator sees the disagreement in the list signals.
 */
class OrderStatusListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DbalOrderStatusRepository $statuses,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly MoneyFormatter $money,
        // What a cancellation undoes (K6). Optional for the tests built by
        // hand; the container wires it.
        private readonly OrderCancellation|null $cancellation = null,
        // Who cancelled, for the stock movement (Abnahme G-8); optional likewise.
        private readonly Security|null $security = null,
        // dev#1: whether the open invoice can be cancelled with the order.
        private readonly CancellationInvoice|null $invoice = null,
    ) {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_order', target: 'fields.orderStatus.options')]
    public function options(DataContainer|null $dc = null): array
    {
        $shop = null !== $dc && (int) $dc->id > 0 ? ($dc->getCurrentRecord()['shop'] ?? null) : null;

        // The filter panel asks without a record: every status, as the
        // foreignKey listed them.
        if (null === $shop) {
            return array_map(strval(...), $this->connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_order_status ORDER BY pid, sorting, id'));
        }

        return $this->statuses->titlesOfShop((int) $shop);
    }

    #[AsCallback(table: 'tl_consho_order', target: 'fields.orderStatus.save')]
    public function save(mixed $value, DataContainer $dc): mixed
    {
        $orderId = (int) $dc->id;
        $statusId = (int) $value;
        $current = $this->connection->fetchAssociative('SELECT orderStatus, currency, language FROM tl_consho_order WHERE id = ?', [$orderId]);

        if (false === $current || $statusId === (int) $current['orderStatus']) {
            return $value;
        }

        $flags = $this->statuses->flags($statusId);
        $balance = null === $flags ? null : $this->balances->of($orderId, $statusId);

        if (null === $flags || null === $balance) {
            return $value;
        }

        $unmet = StatusRequirement::unmet($flags, $balance);

        // dev#1: a cancellation reverses the whole invoice — refused where that
        // would credit a corrected part twice, or where it cannot be numbered.
        $refusal = $flags->isCancelled && [] === $unmet ? $this->invoice?->refusal($orderId) : null;

        if (null !== $refusal) {
            throw new \RuntimeException(\sprintf(
                $GLOBALS['TL_LANG']['tl_consho_order']['statusRefused'] ?? '%s: %s',
                $flags->title,
                $GLOBALS['TL_LANG']['tl_consho_order']['cancelInvoiceRefused'][$refusal->value] ?? $refusal->value,
            ));
        }

        if ([] === $unmet) {
            return $value;
        }

        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? $current['language']);
        $reasons = array_map(
            fn (StatusRequirement $r): string => $this->reason($r, $orderId, $balance, (string) $current['currency'], $language),
            $unmet,
        );

        throw new \RuntimeException(\sprintf(
            $GLOBALS['TL_LANG']['tl_consho_order']['statusRefused'] ?? '%s: %s',
            $flags->title,
            implode('; ', $reasons),
        ));
    }

    /**
     * Records the cancellation on the order once a cancelling status is saved
     * (tl_consho_order.cancelledAt). onsubmit, not the save callback: only a
     * form that was actually stored may leave the mark.
     */
    #[AsCallback(table: 'tl_consho_order', target: 'config.onsubmit')]
    public function recordCancellation(DataContainer $dc): void
    {
        $now = time();
        $orderId = (int) $dc->id;
        $user = $this->security?->getUser();
        $userId = $user instanceof BackendUser ? (int) $user->id : 0;

        $cancelling = false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_order o JOIN tl_consho_order_status s ON s.id = o.orderStatus AND s.isCancelled = 1 WHERE o.id = ?',
            [$orderId],
        );

        if ($cancelling) {
            // Without the service (tests built by hand) only `cancelledAt` is set.
            ($this->cancellation ?? new OrderCancellation($this->connection))->record($orderId, $now, $userId);
        }
    }

    private function reason(StatusRequirement $requirement, int $orderId, OrderBalance $balance, string $currency, string $language): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_order']['statusUnmet'] ?? [];

        return match ($requirement) {
            StatusRequirement::PaymentSettled => $this->paymentReason($balance, $currency, $language, $lang),
            StatusRequirement::DeliverySettled => \sprintf($lang['deliverySettled'] ?? '%s', $this->openGoods($orderId, $balance, $language)),
            StatusRequirement::NothingShipped => $lang['nothingShipped'] ?? 'nothingShipped',
        };
    }

    /**
     * @param array<string, string> $lang
     */
    private function paymentReason(OrderBalance $balance, string $currency, string $language, array $lang): string
    {
        $outstanding = BigDecimal::of($balance->outstanding());
        $amount = $this->money->format((string) $outstanding->abs(), $currency, $language);

        return $outstanding->isNegative()
            ? \sprintf($lang['refundDue'] ?? '%s', $amount)
            : \sprintf($lang['paymentSettled'] ?? '%s', $amount);
    }

    /**
     * "2 × Wanderstock, 1 × Rucksack" — what has not been reported shipped.
     */
    private function openGoods(int $orderId, OrderBalance $balance, string $language): string
    {
        $names = $this->connection->fetchAllKeyValue('SELECT id, productName FROM tl_consho_order_item WHERE pid = ?', [$orderId]);
        $parts = [];

        foreach ($balance->openGoods as $itemId => $quantity) {
            $parts[] = \sprintf('%s × %s', $this->money->quantity($quantity, $language), (string) ($names[$itemId] ?? '#'.$itemId));
        }

        return implode(', ', $parts);
    }
}
