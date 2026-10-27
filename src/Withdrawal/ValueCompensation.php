<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Order\DiscountShares;

/**
 * The value compensation PROPOSED for a withdrawn service (Abo-8, P79).
 *
 * § 357a Abs. 2 BGB: the consumer owes the value of the services rendered
 * until the withdrawal where they asked for the start within the period and
 * were informed; it is calculated on the agreed total price. Abs. 3: for
 * digital content there is none. So only lines withdrawn as a service count,
 * and only with the consent of the order (`digitalConsentAt`) and a release.
 *
 * The proposal per line: what the line cost (its gross amount less its part
 * of the order's reductions, H-5) × days used / days of the
 * period paid with the purchase. Used are the days from the release — or the
 * subscription's start, for a renewal that begins later — to the receipt of
 * the withdrawal, every 24 hours begun counted as a day (not calendar days),
 * never more than the period.
 *
 * A proposal, nothing more: the operator sees the calculation and sets the
 * amount (Consho decides nothing, A12). A line without a subscription has no
 * period to divide by, and is proposed at 0 with that reason.
 */
class ValueCompensation
{
    private const DAY = 86_400;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{amount: string, lines: list<array{item: int, name: string, gross: string, days: int, periodDays: int, amount: string, reason: string}>}
     */
    public function proposal(int $withdrawalId): array
    {
        $withdrawal = $this->connection->fetchAssociative(
            'SELECT w.receivedAt, o.id AS orderId, o.digitalConsentAt, o.currency
               FROM tl_consho_withdrawal w
               JOIN tl_consho_order o ON o.id = w.pid
              WHERE w.id = ?',
            [$withdrawalId],
        );

        if (false === $withdrawal) {
            return ['amount' => '0', 'lines' => []];
        }

        $currency = (string) ($withdrawal['currency'] ?? '');
        $digits = '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
        $consent = null !== $withdrawal['digitalConsentAt'] && (int) $withdrawal['digitalConsentAt'] > 0;
        $receivedAt = (int) $withdrawal['receivedAt'];
        $orderId = (int) $withdrawal['orderId'];

        $total = BigDecimal::zero();
        $lines = [];
        // H-5 (R1): what the line cost, its part of the order's reductions out.
        $items = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_order_item WHERE pid = ? ORDER BY id', [$orderId]) as $row) {
            $items[(int) $row['id']] = array_map(static fn ($v): string => (string) $v, $row);
        }

        $parts = DiscountShares::of($items);

        foreach ($this->connection->fetchAllAssociative(
            'SELECT i.id, i.productName, i.quantity, i.lineTotal, wi.acceptedQuantity
               FROM tl_consho_withdrawal_item wi
               JOIN tl_consho_order_item i ON i.id = wi.orderItem AND i.pid = ?
              WHERE wi.pid = ? AND wi.acceptedQuantity > 0 AND i.kind = ?
              ORDER BY i.id',
            [$orderId, $withdrawalId, ProductKind::Service->value],
        ) as $row) {
            $quantity = BigDecimal::of((string) $row['quantity']);

            if (!$quantity->isPositive()) {
                continue;
            }

            $itemId = (int) $row['id'];
            $gross = isset($items[$itemId]) ? DiscountShares::credit($items[$itemId], $parts[$itemId] ?? [], (string) $row['acceptedQuantity']) : BigDecimal::zero();
            [$days, $periodDays, $reason] = $consent ? $this->usage($orderId, $itemId, $receivedAt) : [0, 0, 'noConsent'];

            $amount = $periodDays > 0
                ? $gross->multipliedBy($days)->dividedBy($periodDays, $digits, RoundingMode::HalfUp)
                : BigDecimal::zero()->toScale($digits);

            $total = $total->plus($amount);
            $lines[] = [
                'item' => $itemId,
                'name' => (string) $row['productName'],
                'gross' => (string) $gross->toScale($digits, RoundingMode::HalfUp),
                'days' => $days,
                'periodDays' => $periodDays,
                'amount' => (string) $amount,
                'reason' => $reason,
            ];
        }

        return ['amount' => (string) $total->toScale($digits, RoundingMode::HalfUp), 'lines' => $lines];
    }

    /**
     * @return array{int, int, string} days used, days of the period, why 0 where it is
     */
    private function usage(int $orderId, int $itemId, int $receivedAt): array
    {
        // The release is reported as a delivery of the line (DigitalRelease,
        // also where it takes a renewal's licence over). Read there, not at
        // the entitlement: an accepted withdrawal of a renewal hands that
        // back to the old order (P78), and the mask asks again after it
        // (Abo-8).
        $released = $this->connection->fetchOne(
            'SELECT MIN(d.shippedAt) FROM tl_consho_delivery d
               JOIN tl_consho_delivery_item di ON di.pid = d.id
              WHERE d.pid = ? AND di.orderItem = ? AND d.reportedAt IS NOT NULL',
            [$orderId, $itemId],
        );

        if (null === $released || false === $released || (int) $released < 1) {
            return [0, 0, 'notReleased'];
        }

        $subscription = $this->connection->fetchAssociative(
            'SELECT startedAt, periodFrom, periodUntil FROM tl_consho_subscription WHERE firstOrder = ? AND orderItem = ?',
            [$orderId, $itemId],
        );

        if (false === $subscription || (int) $subscription['periodUntil'] <= (int) $subscription['periodFrom']) {
            return [0, 0, 'noPeriod'];
        }

        $periodDays = (int) round(((int) $subscription['periodUntil'] - (int) $subscription['periodFrom']) / self::DAY);
        $from = max((int) $released, (int) $subscription['startedAt']);
        $days = $receivedAt > $from ? (int) ceil(($receivedAt - $from) / self::DAY) : 0;

        return [min($days, $periodDays), $periodDays, ''];
    }
}
