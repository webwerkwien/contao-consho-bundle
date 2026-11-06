<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Complaint;

use Doctrine\DBAL\Connection;

/**
 * When the goods of a complaint line were delivered (block Reklamation,
 * RK-6, A6; R9): the reported deliveries that held its order item, without
 * replacement deliveries. The page shows them with the gap to the
 * complaint's receipt; no deadline is drawn from it.
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
