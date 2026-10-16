<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Articles with two price rows of equal rank at the same time (plan B-8).
 *
 * Found in the B-5 research: two rows with the same currency, customer group
 * and threshold whose validity overlaps are a tie in PriceCalculator — the row
 * the database happens to return first wins. Whoever enters a "special offer"
 * as a limited list price row next to the regular one gets a random price.
 *
 * Since block H (H2) PriceCalculator decides most of these: a row with a
 * validity window beats one without, and of two with a window the later start
 * wins. What is left — both without a window, or both with the same start —
 * is still a tie, and still reported here, so the operator can close one row.
 *
 * Overlap is the price repository's own rule: from inclusive, to exclusive,
 * NULL for open — [a, b) and [b, c) do not overlap.
 */
final class DbalPriceTieIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $count = (int) $this->connection->fetchOne(
            // a.tstamp > 0 AND b.tstamp > 0: see DbalPriceRepository::findCandidates()
            // — a price mask opened and never saved is not a tie. The same
            // subscription rule: one-time sale, monthly and yearly subscription
            // are three contracts, each with its own price (Abo-1).
            'SELECT COUNT(DISTINCT a.pid)
             FROM tl_consho_price a
             JOIN tl_consho_price b
               ON b.pid = a.pid AND b.currency = a.currency
              AND b.customerGroup = a.customerGroup AND b.minQuantity = a.minQuantity
              AND b.subscriptionRule = a.subscriptionRule
              AND b.id > a.id
             WHERE a.tstamp > 0 AND b.tstamp > 0
               AND (a.validFrom IS NULL OR b.validTo IS NULL OR a.validFrom < b.validTo)
               AND (b.validFrom IS NULL OR a.validTo IS NULL OR b.validFrom < a.validTo)
               AND ((a.validFrom IS NULL AND a.validTo IS NULL) = (b.validFrom IS NULL AND b.validTo IS NULL))
               AND (a.validFrom = b.validFrom OR (a.validFrom IS NULL AND b.validFrom IS NULL))',
        );

        if ($count < 1) {
            return [];
        }

        return [new ConfigurationIssue(ConfigurationFinding::PriceTie, ['count' => (string) $count])];
    }
}
