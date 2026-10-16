<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * The guest price over time, and the price struck through next to a
 * promotion (block H, H-2; docs/block-h-preise.md, 6.2).
 *
 * § 9a Abs. 1 PrAG: "den vorherigen niedrigsten Preis …, der zumindest einmal
 * innerhalb eines Zeitraums von 30 Tagen vor der Anwendung der
 * Preisermäßigung in demselben Vertriebskanal verlangt wurde"; § 11 Abs. 1
 * PAngV alike. So:
 *
 * - The window ends where the reduction BEGAN, not now. Counted back from now,
 *   the promotion itself would soon be the lowest price, and the shop would
 *   strike its own sale price through.
 * - Where it began: the first of the uninterrupted run of promotion entries
 *   that ends with the current one. That also covers "schrittweise
 *   ansteigende Preisermäßigung" (§ 9a Abs. 1 Satz 2): the price before the
 *   FIRST step.
 * - Less than 30 days of history: what there is (§ 9a Abs. 2 for goods
 *   shorter on the market; a shop whose history began later answers the same
 *   way). No history before the run: no reference, no strike-through.
 *
 * Keyed by article and market (currency and home country, as the projection);
 * the plan is an attribute. Only one-time prices count: a monthly amount is
 * not a lower price of the same thing.
 */
class PriceHistory
{
    private const WINDOW = 30 * 86400;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Writes an entry when the price differs from the last one — net, gross,
     * plan or whether it is a promotion.
     */
    public function record(int $article, string $currency, string $country, ListPrice $price, int $at): void
    {
        $last = $this->connection->fetchAssociative(
            'SELECT net, gross, subscriptionRule, promotion FROM tl_consho_price_history
              WHERE article = ? AND currency = ? AND country = ?
              ORDER BY validFrom DESC, id DESC LIMIT 1',
            [$article, $currency, $country],
        );

        if (false !== $last
            && BigDecimal::of((string) $last['net'])->isEqualTo($price->net)
            && BigDecimal::of((string) $last['gross'])->isEqualTo($price->gross)
            && (int) $last['subscriptionRule'] === $price->subscriptionRule
            && (bool) $last['promotion'] === $price->promotion) {
            return;
        }

        $this->connection->insert('tl_consho_price_history', [
            'tstamp' => $at,
            'article' => $article,
            'currency' => $currency,
            'country' => $country,
            'subscriptionRule' => $price->subscriptionRule,
            'net' => $price->net,
            'gross' => $price->gross,
            'promotion' => $price->promotion ? 1 : 0,
            'validFrom' => $at,
        ]);
    }

    /**
     * The lowest one-time price of the 30 days before the current promotion
     * began — or null: no promotion now, or nothing known before it.
     *
     * "Began" is the start of the run of promotion steps that led here
     * WITHOUT the price going up: only a step-wise INCREASING reduction keeps
     * the price before its first step (§ 9a Abs. 1 Satz 2 PrAG, § 11 Abs. 2
     * PAngV: "schrittweisen, ohne Unterbrechung ansteigenden
     * Preisermäßigung"). A step dearer than the one before is a new
     * reduction under the general rule (review H, S3; Michael, 2026-10-05:
     * "wie empfohlen"): 100 → 70 → 80 compares 80 with 70, and shows no
     * strike-through price at all (B).
     *
     * @return array{string, string}|null net and gross of that price
     */
    public function reference(int $article, string $currency, string $country, int $at): array|null
    {
        $entries = $this->connection->fetchAllAssociative(
            'SELECT net, gross, subscriptionRule, promotion, validFrom FROM tl_consho_price_history
              WHERE article = ? AND currency = ? AND country = ? AND validFrom <= ?
              ORDER BY validFrom, id',
            [$article, $currency, $country, $at],
        );

        $last = \count($entries) - 1;

        if ($last < 0 || !self::isOneTimePromotion($entries[$last])) {
            return null;
        }

        $runStart = $last;

        while ($runStart > 0 && self::isOneTimePromotion($entries[$runStart - 1])
            && BigDecimal::of((string) $entries[$runStart - 1]['gross'])->isGreaterThanOrEqualTo((string) $entries[$runStart]['gross'])) {
            --$runStart;
        }

        $began = (int) $entries[$runStart]['validFrom'];
        $windowStart = $began - self::WINDOW;
        $lowest = null;

        // Every entry asked at some point of [began − 30 days, began): it
        // started before `began` (it is before the run) and its successor
        // started after the window opened — including the one already standing
        // when the window opened.
        for ($i = 0; $i < $runStart; ++$i) {
            if ((int) $entries[$i + 1]['validFrom'] <= $windowStart || 0 !== (int) $entries[$i]['subscriptionRule']) {
                continue;
            }

            if (null === $lowest || BigDecimal::of((string) $entries[$i]['gross'])->isLessThan((string) $lowest['gross'])) {
                $lowest = $entries[$i];
            }
        }

        return null === $lowest ? null : [(string) $lowest['net'], (string) $lowest['gross']];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function isOneTimePromotion(array $entry): bool
    {
        return (bool) $entry['promotion'] && 0 === (int) $entry['subscriptionRule'];
    }
}
