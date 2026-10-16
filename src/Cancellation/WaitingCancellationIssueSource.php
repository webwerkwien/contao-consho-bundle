<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\BackendDate;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Extraordinary cancellations waiting for the operator's decision (Abo-7,
 * P57; Michael, 2026-09-30: „Befund für wartende Kündigungen auch"), per shop,
 * like the open withdrawals (F11). An extraordinary cancellation takes effect
 * only once decided; the copy of the confirmation is the only other sign of
 * it, and a shop without an operator address sends none.
 */
final class WaitingCancellationIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.shop, sh.title, c.number, c.receivedAt
               FROM tl_consho_cancellation c
               JOIN tl_consho_subscription s ON s.id = c.pid
               JOIN tl_consho_shop sh ON sh.id = s.shop
              WHERE c.kind = 'extraordinary' AND c.decidedAt IS NULL
                -- An ended subscription has nothing left to end (review
                -- Abo-Nachlese, L2: a cancelled purchase ends it, P72).
                AND s.state <> 'ended'
              ORDER BY s.shop, c.receivedAt, c.id",
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']][] = $row;
        }

        $issues = [];

        foreach ($byShop as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::CancellationsWaiting,
                [
                    'shop' => (string) $entries[0]['title'],
                    'count' => (string) \count($entries),
                    'numbers' => implode(', ', array_map(static fn (array $e): string => (string) $e['number'], $entries)),
                    'since' => BackendDate::format((int) $entries[0]['receivedAt']),
                ],
                $shopId,
            );
        }

        return $issues;
    }
}
