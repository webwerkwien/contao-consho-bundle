<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Decisions on cancellations whose mail did not leave (E10), per shop. Until then that stood only in
 * the log. `decisionSentAt` NULL after a decision says it; 0 is a decision
 * from before the field and says nothing (DecisionSentMigration).
 *
 * "Entscheidung erneut senden" at the cancellation clears it.
 */
final class DecisionNotSentIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.shop, sh.title, c.number
               FROM tl_consho_cancellation c
               JOIN tl_consho_subscription s ON s.id = c.pid
               JOIN tl_consho_shop sh ON sh.id = s.shop
              WHERE c.decidedAt IS NOT NULL AND c.decisionSentAt IS NULL
              ORDER BY s.shop, c.decidedAt, c.id',
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']][] = $row;
        }

        $issues = [];

        foreach ($byShop as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::DecisionNotSent,
                [
                    'shop' => (string) $entries[0]['title'],
                    'count' => (string) \count($entries),
                    'numbers' => implode(', ', array_map(static fn (array $e): string => (string) $e['number'], $entries)),
                ],
                $shopId,
            );
        }

        return $issues;
    }
}
