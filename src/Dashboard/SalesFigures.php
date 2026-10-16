<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dashboard;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The figures on the dashboard (DB5): orders and order value today, in the
 * last seven days and in the running month, per shop and currency.
 *
 * Counted live, in one query over an index on `createdAt` — "nur billig"
 * (Michael, 2026-10-05). The order value is the gross `grandTotal` of orders
 * not cancelled — by `cancelledAt` or by a status that cancels; withdrawals
 * do not lower it. It is labelled so.
 *
 * The bounds are bound as integers, the column's type. Here they meet a
 * column, which SQLite converts; against an expression it would not
 * (Prüfungsnotiz Fall 64) — bound typed so that a later CASE stays right.
 */
final class SalesFigures
{
    public const TODAY = 'today';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, array<string, array<string, array{orders: int, total: string}>>> shop => currency => period => figures
     */
    public function byShop(int $now): array
    {
        $today = (int) strtotime('today', $now);
        $week = (int) strtotime('-6 days', $today);
        $month = (int) strtotime('first day of this month midnight', $now);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT shop, currency,
                    SUM(CASE WHEN createdAt >= :today THEN 1 ELSE 0 END) AS ordersToday,
                    SUM(CASE WHEN createdAt >= :today THEN grandTotal ELSE 0 END) AS totalToday,
                    SUM(CASE WHEN createdAt >= :week THEN 1 ELSE 0 END) AS ordersWeek,
                    SUM(CASE WHEN createdAt >= :week THEN grandTotal ELSE 0 END) AS totalWeek,
                    SUM(CASE WHEN createdAt >= :month THEN 1 ELSE 0 END) AS ordersMonth,
                    SUM(CASE WHEN createdAt >= :month THEN grandTotal ELSE 0 END) AS totalMonth
               FROM tl_consho_order o
              WHERE createdAt >= :since AND cancelledAt IS NULL
                -- Cancelled by its status as well, the rule of OrderBalanceReader (review A3).
                AND NOT EXISTS (SELECT 1 FROM tl_consho_order_status s WHERE s.id = o.orderStatus AND s.isCancelled = 1)
              GROUP BY shop, currency
              ORDER BY shop, currency',
            ['today' => $today, 'week' => $week, 'month' => $month, 'since' => min($week, $month)],
            ['today' => ParameterType::INTEGER, 'week' => ParameterType::INTEGER, 'month' => ParameterType::INTEGER, 'since' => ParameterType::INTEGER],
        );

        $figures = [];

        foreach ($rows as $row) {
            $figures[(int) $row['shop']][(string) $row['currency']] = [
                self::TODAY => ['orders' => (int) $row['ordersToday'], 'total' => (string) $row['totalToday']],
                self::WEEK => ['orders' => (int) $row['ordersWeek'], 'total' => (string) $row['totalWeek']],
                self::MONTH => ['orders' => (int) $row['ordersMonth'], 'total' => (string) $row['totalMonth']],
            ];
        }

        return $figures;
    }
}
