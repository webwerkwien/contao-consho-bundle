<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;

/**
 * The withdrawal window of a stored order: the shop's period and grace, the
 * balance, and the last shipment.
 *
 * "Last shipment" is the later of the typed shipping date and the moment the
 * delivery was reported, over REPORTED deliveries only. Later of the two on
 * purpose: an operator who reports a parcel a day after it left, or types a
 * date ahead, should move the window out, never in (see WithdrawalWindow — a
 * day too early is the breach).
 */
final class WithdrawalWindowReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    public function of(int $orderId): WithdrawalWindow|null
    {
        $shop = $this->connection->fetchAssociative(
            'SELECT s.withdrawalDays, s.withdrawalGraceDays, o.subscription
               FROM tl_consho_order o
               JOIN tl_consho_shop s ON s.id = o.shop
              WHERE o.id = ?',
            [$orderId],
        );

        $balance = $this->balances->of($orderId);

        if (false === $shop || null === $balance) {
            return null;
        }

        /*
         * A subscription's follow-up order opens no withdrawal period (Abo-4,
         * A12): the contract was concluded with the purchase, and its window
         * is the purchase's. Found in the review of Abo-4: the follow-up has
         * no entitlement row of its own (P27), so the check below never fired,
         * and the reported delivery opened a fresh 14 days on the order page.
         */
        if ((int) ($shop['subscription'] ?? 0) > 0) {
            return WithdrawalWindow::of(0, 0, false, false, false, null, new \DateTimeZone(date_default_timezone_get()));
        }

        /*
         * Digital content released with the consumer's consent, for a
         * business customer or after the period (F-4): nothing is left to
         * withdraw from (§ 18 Abs. 1 Z 11 FAGG, § 356 Abs. 6 BGB). Found at
         * the acceptance of F on c5 (2026-09-29): WEB-00081, an e-book with
         * consent, delivered, still offered "Vertrag widerrufen" for the
         * whole order. When every goods line is used up by releases and
         * earlier withdrawals, the function is off; a mixed order keeps its
         * window for the parcel.
         */
        if ($this->allReleased($orderId)) {
            return WithdrawalWindow::of(0, 0, false, false, false, null, new \DateTimeZone(date_default_timezone_get()));
        }

        $services = $this->serviceLines($orderId);

        if ([] !== $services) {
            return $this->withServices($orderId, $shop, $balance, $services);
        }

        $last = null;

        foreach ($this->connection->fetchAllAssociative('SELECT shippedAt, reportedAt FROM tl_consho_delivery WHERE pid = ? AND reportedAt IS NOT NULL', [$orderId]) as $row) {
            $last = max($last ?? 0, (int) $row['shippedAt'], (int) $row['reportedAt']);
        }

        return WithdrawalWindow::of(
            (int) $shop['withdrawalDays'],
            (int) $shop['withdrawalGraceDays'],
            $balance->cancelled,
            $balance->anyShipped,
            $balance->deliverySettled(),
            $last,
            new \DateTimeZone(date_default_timezone_get()),
        );
    }

    /**
     * Abo-8, P76: for a service the period runs from the conclusion of the
     * contract (§ 355 Abs. 2 BGB, § 11 Abs. 2 Z 1 FAGG) — the purchase, not a
     * delivery. Its release is reported as a delivery (F-4), so it must not
     * move the window, and before the release the window must not stand open
     * without an end. Other goods in the same order keep their own rule; the
     * later of the two ends counts, since the function serves the whole order.
     * The release of digital content in the same order counts as a shipment
     * there, too, although it used that content up: a later end, never an
     * earlier one (review Abo-8, L3; WithdrawalWindow: a day too late costs
     * nothing).
     *
     * @param array<string, mixed> $shop
     * @param array<int, true>     $services
     */
    private function withServices(int $orderId, array $shop, OrderBalance $balance, array $services): WithdrawalWindow
    {
        $last = (int) $this->connection->fetchOne('SELECT createdAt FROM tl_consho_order WHERE id = ?', [$orderId]);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.shippedAt, d.reportedAt, di.orderItem FROM tl_consho_delivery d
             INNER JOIN tl_consho_delivery_item di ON di.pid = d.id
             WHERE d.pid = ? AND d.reportedAt IS NOT NULL',
            [$orderId],
        );

        foreach ($rows as $row) {
            if (!isset($services[(int) $row['orderItem']])) {
                $last = max($last, (int) $row['shippedAt'], (int) $row['reportedAt']);
            }
        }

        return WithdrawalWindow::of(
            (int) $shop['withdrawalDays'],
            (int) $shop['withdrawalGraceDays'],
            $balance->cancelled,
            $balance->anyShipped,
            [] === array_diff_key($balance->openGoods, $services),
            $last,
            new \DateTimeZone(date_default_timezone_get()),
        );
    }

    /**
     * @return array<int, true> the order's goods lines withdrawn from as a service
     */
    private function serviceLines(int $orderId): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_order_item WHERE pid = ? AND lineType = \'product\' AND kind = ?',
            [$orderId, ProductKind::Service->value],
        );

        return array_fill_keys(array_map(intval(...), $ids), true);
    }

    private function allReleased(int $orderId): bool
    {
        if (0 === (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_entitlement WHERE pid = ?', [$orderId])) {
            return false;
        }

        $goods = (new DbalWithdrawalRepository($this->connection))->goods($orderId);

        return [] !== $goods && [] === array_filter($goods, static fn (array $good): bool => (float) $good['left'] > 0);
    }
}
