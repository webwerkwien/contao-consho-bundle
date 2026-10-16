<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Privacy;

use Doctrine\DBAL\Connection;

/**
 * When an order's retention period ends (D-5, L2, L6).
 *
 * The period starts at the END of the calendar year — § 132 Abs. 1 BAO ("vom
 * Schluß des Kalenderjahres, auf das sie sich beziehen"), § 147 Abs. 4 AO and
 * § 257 Abs. 5 HGB ("mit dem Schluss des Kalenderjahrs, in dem … der
 * Buchungsbeleg entstanden ist"). An invoice of 2026 kept 7 years is kept
 * until 31.12.2033.
 *
 * The year is that of the order's LATEST document (L6): a cancellation or a
 * correction in a later year is a document of its own, and the order behind
 * it is the one record of the sale. Without any document, the order date.
 *
 * The number of years is the shop's (retentionYears); 0 means "not set", and
 * such a shop has nothing due — never "keep nothing".
 */
class RetentionPolicy
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The last second of the period: 31.12. of (year of $latest) + $years.
     */
    public static function endOf(int $latest, int $years): int
    {
        return (int) mktime(23, 59, 59, 12, 31, (int) date('Y', $latest) + $years);
    }

    /**
     * The end of one order's period, or null where its shop has none.
     */
    public function endOfOrder(int $orderId): int|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT o.createdAt, s.retentionYears, MAX(d.issuedAt) AS lastDocument
             FROM tl_consho_order o
             JOIN tl_consho_shop s ON s.id = o.shop
             LEFT JOIN tl_consho_document d ON d.pid = o.id
             WHERE o.id = ?
             GROUP BY o.id, o.createdAt, s.retentionYears',
            [$orderId],
        );

        if (false === $row || (int) $row['retentionYears'] < 1) {
            return null;
        }

        return self::endOf(max((int) $row['createdAt'], (int) $row['lastDocument']), (int) $row['retentionYears']);
    }

    /**
     * Orders whose period has ended by $now and whose data is still there.
     *
     * @param bool $onlyAutomatic true: only where the shop anonymises
     *                            automatically or erasure was requested (the
     *                            cron); false: every order past its period
     *                            (an erasure request, L3)
     *
     * @return list<array{id: int, shop: int, orderNumber: string, end: int}>
     */
    public function due(int $now, bool $onlyAutomatic = true): array
    {
        return array_values(array_filter(
            $this->candidates($now, $onlyAutomatic),
            static fn (array $order): bool => $order['end'] < $now,
        ));
    }

    /**
     * Orders whose period ends in ($from, $until] — the announcement (L5).
     *
     * @return list<array{id: int, shop: int, orderNumber: string, end: int}>
     */
    public function dueBetween(int $from, int $until): array
    {
        return array_values(array_filter(
            $this->candidates($until, true),
            static fn (array $order): bool => $order['end'] >= $from && $order['end'] < $until,
        ));
    }

    /**
     * Orders that COULD be due by $until: per shop, only those created before
     * the first year that cannot be — the period ends at the earliest on
     * 31.12. of (order year + N). A cheap prefilter on an indexed column; the
     * exact end is computed per order.
     *
     * @return list<array{id: int, shop: int, orderNumber: string, end: int}>
     */
    private function candidates(int $until, bool $onlyAutomatic): array
    {
        $orders = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, retentionYears, autoAnonymize FROM tl_consho_shop WHERE retentionYears > 0') as $shop) {
            $years = (int) $shop['retentionYears'];
            $bound = (int) mktime(0, 0, 0, 1, 1, (int) date('Y', $until) - $years + 1);

            $rows = $this->connection->fetchAllAssociative(
                'SELECT o.id, o.shop, o.orderNumber, o.createdAt, o.erasureRequestedAt, MAX(d.issuedAt) AS lastDocument
                 FROM tl_consho_order o
                 LEFT JOIN tl_consho_document d ON d.pid = o.id
                 WHERE o.shop = ? AND o.anonymizedAt IS NULL AND o.createdAt < ?
                 GROUP BY o.id, o.shop, o.orderNumber, o.createdAt, o.erasureRequestedAt
                 ORDER BY o.id',
                [(int) $shop['id'], $bound],
            );

            foreach ($rows as $row) {
                if ($onlyAutomatic && !$shop['autoAnonymize'] && null === $row['erasureRequestedAt']) {
                    continue;
                }

                $orders[] = [
                    'id' => (int) $row['id'],
                    'shop' => (int) $row['shop'],
                    'orderNumber' => (string) $row['orderNumber'],
                    'end' => self::endOf(max((int) $row['createdAt'], (int) $row['lastDocument']), $years),
                ];
            }
        }

        usort($orders, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $orders;
    }
}
