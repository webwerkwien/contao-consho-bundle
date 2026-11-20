<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Cancellations whose confirmation did not leave (Abo-9, P129), per shop and
 * from any source — the customer's, the operator's, a price change's. Until
 * then that stood only in the log (CancellationRecorder).
 *
 * There is no "send again": the operator tells the customer by hand. So the
 * notice lasts while it can still matter — until the cancellation is
 * effective; one still waiting for a decision has no end yet and counts, a
 * rejected one never gets an end and does not. An ended subscription has
 * nothing left to tell.
 */
final class CancellationNotConfirmedIssueSource implements ConfigurationIssueSource
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function issues(): array
    {
        $now = null === $this->clock ? time() : ($this->clock)();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.shop, sh.title, c.number
               FROM tl_consho_cancellation c
               JOIN tl_consho_subscription s ON s.id = c.pid
               JOIN tl_consho_shop sh ON sh.id = s.shop
              WHERE c.confirmationSentAt IS NULL
                AND s.state <> \'ended\'
                AND ((c.effectiveAt IS NULL AND c.decidedAt IS NULL) OR c.effectiveAt > ?)
              ORDER BY s.shop, c.receivedAt, c.id',
            [$now],
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']][] = $row;
        }

        $issues = [];

        foreach ($byShop as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::CancellationNotConfirmed,
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
