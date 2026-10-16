<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A customer group that pays MORE than everyone else (C-4).
 *
 * ── Why this is allowed, and still worth saying ───────────────────────────
 *
 * "Darf der Betreiber einen teureren Gruppenpreis angeben? Ich denke ja,
 * aber vielleicht sollte es einen Hinweis geben" — Michael, 2026-09-21.
 *
 * It follows from the resolution order, which is deliberate: a group price
 * beats a list price whatever the amounts, so a bulk discount cannot silently
 * override the price a customer was promised (block A, 5.1). Turn that around
 * and a group price of 8.00 also wins against a list price of 7.42 — the
 * member pays more than a guest, and the shop is doing exactly what it was
 * told to.
 *
 * Which is why this is a hint and not a warning, and why it does not name a
 * culprit. Sometimes it is intended: a group buying single units where the
 * public price only exists as a pallet rate. Usually it is a typo, or a list
 * price lowered later while the group kept its old one.
 *
 * ── The comparison asks the calculator, it does not re-derive it ──────────
 *
 * ⚠️ Working out the winning row in SQL would be a SECOND copy of the
 * resolution order. It would agree with the shop today and drift tomorrow,
 * and a finding that disagrees with the shop is worse than none. So every
 * amount here comes out of PriceCalculator — twice per threshold, once with
 * the group and once without — over rows loaded in a single query.
 *
 * Thresholds are the only quantities worth asking about: between two of them
 * nothing changes, so a price that is higher anywhere is higher at one of
 * them.
 */
final class DbalGroupPriceAboveListIssueSource implements ConfigurationIssueSource
{
    private const EXAMPLES = 3;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $now = time();

        /*
         * Only products that have a group price at all, and only rows valid
         * now — an expired group price is nobody's price. tstamp > 0: see
         * DbalPriceRepository::findCandidates(), a price mask opened and never
         * saved is not a price. subscriptionRule = 0 on both sides: a
         * subscription price is another contract, not a dearer list price
         * (Abo-1).
         */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.pid, p.currency, p.net, p.customerGroup, p.minQuantity, p.validFrom, p.validTo
             FROM tl_consho_price p
             WHERE p.tstamp > 0
               AND p.subscriptionRule = 0
               AND (p.validFrom IS NULL OR p.validFrom <= :now)
               AND (p.validTo IS NULL OR p.validTo > :now)
               AND EXISTS (
                   SELECT 1 FROM tl_consho_price g
                   WHERE g.pid = p.pid AND g.currency = p.currency AND g.customerGroup > 0 AND g.tstamp > 0 AND g.subscriptionRule = 0
                     AND (g.validFrom IS NULL OR g.validFrom <= :now)
                     AND (g.validTo IS NULL OR g.validTo > :now)
               )
             ORDER BY p.pid, p.currency',
            ['now' => $now],
        );

        if ([] === $rows) {
            return [];
        }

        [$byProduct, $groupsOf, $thresholdsOf] = self::sort($rows);

        $calculator = new PriceCalculator(new InMemoryPriceRepository($byProduct));

        $affected = [];

        foreach ($groupsOf as $key => $groups) {
            [$productId, $currency] = explode('|', (string) $key, 2);

            foreach ($groups as $group) {
                foreach ($thresholdsOf[$key] as $threshold) {
                    if (self::costsMore($calculator, (int) $productId, $currency, $group, $threshold, $now)) {
                        $affected[$productId.'|'.$group] = [(int) $productId, $group, $threshold];

                        // One report per product and group: the operator opens
                        // the price list and sees every threshold at once.
                        continue 2;
                    }
                }
            }
        }

        if ([] === $affected) {
            return [];
        }

        return [new ConfigurationIssue(
            ConfigurationFinding::GroupPriceAboveList,
            [
                'count' => (string) \count($affected),
                'examples' => $this->examplesOf($affected),
            ],
        )];
    }

    private static function costsMore(PriceCalculator $calculator, int $productId, string $currency, int $group, string $threshold, int $now): bool
    {
        try {
            $withGroup = $calculator->calculate($productId, $currency, [$group], $threshold, $now);
            $without = $calculator->calculate($productId, $currency, [], $threshold, $now);
        } catch (PriceNotFound) {
            // No price for everyone at this quantity: there is nothing the
            // group could be more expensive THAN.
            return false;
        }

        return BigDecimal::of($withGroup->net)->isGreaterThan($without->net);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{array<int, array<string, list<PriceRow>>>, array<string, list<int>>, array<string, list<string>>}
     */
    private static function sort(array $rows): array
    {
        $byProduct = [];
        $groupsOf = [];
        $thresholdsOf = [];

        foreach ($rows as $row) {
            $productId = (int) $row['pid'];
            $currency = (string) $row['currency'];
            $group = (int) $row['customerGroup'];
            $key = $productId.'|'.$currency;

            $byProduct[$productId][$currency][] = new PriceRow(
                (string) $row['net'],
                $currency,
                $group > 0 ? $group : null,
                (string) $row['minQuantity'],
                // The window decides ties and promotions since block H —
                // without it the check would compare against another price.
                null === $row['validFrom'] ? null : (int) $row['validFrom'],
                null === $row['validTo'] ? null : (int) $row['validTo'],
            );

            if ($group > 0 && !\in_array($group, $groupsOf[$key] ?? [], true)) {
                $groupsOf[$key][] = $group;
            }

            if (!\in_array((string) $row['minQuantity'], $thresholdsOf[$key] ?? [], true)) {
                $thresholdsOf[$key][] = (string) $row['minQuantity'];
            }
        }

        return [$byProduct, $groupsOf, $thresholdsOf];
    }

    /**
     * @param array<string, array{int, int, string}> $affected
     */
    private function examplesOf(array $affected): string
    {
        $shown = \array_slice($affected, 0, self::EXAMPLES);

        $titles = $this->connection->fetchAllKeyValue(
            'SELECT id, title FROM tl_consho_product WHERE id IN ('.implode(',', array_map(
                static fn (array $one): int => $one[0],
                $shown,
            )).')',
        );

        $names = $this->connection->fetchAllKeyValue(
            'SELECT id, name FROM tl_member_group WHERE id IN ('.implode(',', array_map(
                static fn (array $one): int => $one[1],
                $shown,
            )).')',
        );

        $examples = [];

        foreach ($shown as [$productId, $group, $threshold]) {
            $quantity = (string) BigDecimal::of($threshold)->strippedOfTrailingZeros();

            $examples[] = \sprintf(
                '%s / %s (ab %s)',
                $titles[$productId] ?? '#'.$productId,
                // A deleted group keeps its price rows; the id is all that is
                // left of it, and DbalNetPriceGroupIssueSource says the rest.
                $names[$group] ?? '#'.$group,
                $quantity,
            );
        }

        return implode('; ', $examples).(\count($affected) > self::EXAMPLES ? ' …' : '');
    }
}
