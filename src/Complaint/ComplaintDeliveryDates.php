<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Complaint;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * When the goods of a complaint line were delivered (block Reklamation,
 * RK-6, A6; R9): the reported deliveries that held its order item, without
 * replacement deliveries. The page shows them with the gap to the
 * complaint's receipt; no deadline is drawn from it.
 *
 * "Widerruf entscheiden" shows the same column per order item, with the gap
 * to the withdrawal's receipt.
 */
final class ComplaintDeliveryDates
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, list<int>> complaint item id => delivery days, oldest first; a line not delivered is missing
     */
    public function forComplaint(int $complaintId): array
    {
        $dates = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT DISTINCT c.id AS line, d.id AS delivery, d.shippedAt, d.reportedAt
               FROM tl_consho_complaint_item c
               JOIN tl_consho_delivery_item i ON i.orderItem = c.orderItem
               JOIN tl_consho_delivery d ON d.id = i.pid
              WHERE c.pid = ? AND d.reportedAt IS NOT NULL AND d.complaint = 0
              ORDER BY d.id',
            [$complaintId],
        ) as $row) {
            // The shipping date the operator entered, else the report.
            $day = (int) $row['shippedAt'] > 0 ? (int) $row['shippedAt'] : (int) $row['reportedAt'];
            $dates[(int) $row['line']][] = $day;
        }

        return self::sorted($dates);
    }

    /**
     * @return array<int, list<int>> order item id => delivery days, oldest first; an item not delivered is missing
     */
    public function forOrder(int $orderId): array
    {
        $dates = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT DISTINCT i.orderItem AS line, d.id AS delivery, d.shippedAt, d.reportedAt
               FROM tl_consho_delivery d
               JOIN tl_consho_delivery_item i ON i.pid = d.id
              WHERE d.pid = ? AND d.reportedAt IS NOT NULL AND d.complaint = 0
              ORDER BY d.id',
            [$orderId],
        ) as $row) {
            $day = (int) $row['shippedAt'] > 0 ? (int) $row['shippedAt'] : (int) $row['reportedAt'];
            $dates[(int) $row['line']][] = $day;
        }

        return self::sorted($dates);
    }

    /**
     * "2 Tage vor Eingang" from the page's texts (`same`, `oneBefore`,
     * `before`, `oneAfter`, `after`); empty without a receipt date.
     *
     * @param array<string, string> $texts
     */
    public static function gap(int $delivered, int $received, array $texts): string
    {
        if ($received < 1) {
            return '';
        }

        $days = self::daysBefore($delivered, $received);

        return match (true) {
            0 === $days => (string) ($texts['same'] ?? ''),
            1 === $days => (string) ($texts['oneBefore'] ?? ''),
            $days > 1 => \sprintf((string) ($texts['before'] ?? '%d'), $days),
            -1 === $days => (string) ($texts['oneAfter'] ?? ''),
            default => \sprintf((string) ($texts['after'] ?? '%d'), -$days),
        };
    }

    /**
     * The column's words for a line, by its product kind: goods are shipped,
     * digital content and services are released, vouchers are issued. Both
     * go through a delivery, so the dates come from the same place.
     *
     * @param array<string, mixed> $texts the page's language array
     *
     * @return array{done: string, pending: string}
     */
    public static function words(string $kind, array $texts): array
    {
        [$done, $pending] = match (ProductKind::tryFrom($kind)) {
            ProductKind::Digital, ProductKind::Service => ['releasedOn', 'notReleased'],
            ProductKind::Voucher => ['issuedOn', 'notIssued'],
            default => ['shippedOn', 'notDelivered'],
        };

        return ['done' => (string) ($texts[$done] ?? $done), 'pending' => (string) ($texts[$pending] ?? $pending)];
    }

    /**
     * @param array<int, list<int>> $dates
     *
     * @return array<int, list<int>>
     */
    private static function sorted(array $dates): array
    {
        return array_map(static function (array $days): array {
            sort($days);

            return $days;
        }, $dates);
    }

    /**
     * Calendar days from the delivery to the receipt of the complaint, in the
     * server's time zone: positive when delivered before, negative after.
     */
    public static function daysBefore(int $delivered, int $received): int
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        $from = (new \DateTimeImmutable('@'.$delivered))->setTimezone($zone)->setTime(0, 0);
        $to = (new \DateTimeImmutable('@'.$received))->setTimezone($zone)->setTime(0, 0);

        return (int) $from->diff($to)->format('%r%a');
    }
}
