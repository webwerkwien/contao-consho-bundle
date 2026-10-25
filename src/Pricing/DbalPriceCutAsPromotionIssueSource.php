<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Articles where a cheaper row with a start and no end sits next to the
 * standing price.
 *
 * PriceCalculator::winnerOf() reads that shape as a promotion (B): a row with
 * a window, cheaper than the standing row it beats. With no end the struck
 * price stays for good. Usually the operator meant a lasting price cut — the
 * way to enter one is to end the old row ("Gültig bis") and let the new one
 * start without an end, and then no struck price appears.
 *
 * A note, not a warning: a promotion "until further notice" can be meant, and
 * the shop sells correctly either way. Rows that start later count too — a cut
 * planned for next month is the same shape, found before it shows.
 *
 * Rank as in DbalPriceTieIssueSource: same currency, customer group, threshold
 * and subscription rule; rows never saved (tstamp 0) do not count.
 *
 * Only the row that wins for good counts, so the note never advises a change
 * where no struck price stays: among the rows without an end the one with the
 * latest start (beats()), and only without a tie on that start — a tie is
 * DbalPriceTieIssueSource's. It must be cheaper than every standing row.
 * That is deliberately stricter than winnerOf(), which compares with the
 * first standing row only: which one is first is open with two of them, and
 * two standing rows are a tie as well. A gap there is cheaper than a note
 * advising a change where no struck price shows.
 */
final class DbalPriceCutAsPromotionIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT cut.pid)
             FROM tl_consho_price cut
             JOIN tl_consho_price standing
               ON standing.pid = cut.pid AND standing.currency = cut.currency
              AND standing.customerGroup = cut.customerGroup AND standing.minQuantity = cut.minQuantity
              AND standing.subscriptionRule = cut.subscriptionRule
              AND standing.id <> cut.id
             WHERE cut.tstamp > 0 AND standing.tstamp > 0
               AND cut.validFrom IS NOT NULL AND cut.validTo IS NULL
               AND standing.validFrom IS NULL AND standing.validTo IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM tl_consho_price later
                    WHERE later.pid = cut.pid AND later.currency = cut.currency
                      AND later.customerGroup = cut.customerGroup AND later.minQuantity = cut.minQuantity
                      AND later.subscriptionRule = cut.subscriptionRule
                      AND later.id <> cut.id AND later.tstamp > 0
                      AND later.validTo IS NULL AND later.validFrom >= cut.validFrom)
               AND NOT EXISTS (
                   SELECT 1 FROM tl_consho_price other
                    WHERE other.pid = cut.pid AND other.currency = cut.currency
                      AND other.customerGroup = cut.customerGroup AND other.minQuantity = cut.minQuantity
                      AND other.subscriptionRule = cut.subscriptionRule
                      AND other.tstamp > 0 AND other.validFrom IS NULL AND other.validTo IS NULL
                      AND other.net <= cut.net)',
        );

        if ($count < 1) {
            return [];
        }

        return [new ConfigurationIssue(ConfigurationFinding::PriceCutAsPromotion, ['count' => (string) $count])];
    }
}
