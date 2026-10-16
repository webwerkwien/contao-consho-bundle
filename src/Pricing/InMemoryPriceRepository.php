<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * Price rows already in hand, handed to PriceCalculator as a repository.
 *
 * For a check that has to ask "what would this cost?" many times over — once
 * per threshold, once with the group and once without. Going through
 * DbalPriceRepository would be one query per question; here the rows are
 * fetched once and the calculator still decides, which is the point:
 *
 * ⚠️ A check that worked out the winning row in SQL would be a SECOND copy of
 * the resolution order, and the day the two disagree the back end reports
 * something the shop does not do. The rule stays in PriceCalculator; this
 * class only moves the rows to it.
 *
 * The validity window is NOT filtered here. The rows are loaded for one moment
 * in time and the timestamp is passed through unused, so the caller has to
 * select what is valid — see DbalGroupPriceAboveListIssueSource, which does.
 */
final class InMemoryPriceRepository implements PriceRepositoryInterface
{
    /**
     * @param array<int, array<string, list<PriceRow>>> $rows product id => currency => rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function findCandidates(int $productId, string $currency, array $customerGroupIds, int $timestamp, int $subscriptionRule = 0): array
    {
        // The rows handed in are one-time rows — every caller loads them with
        // subscriptionRule = 0 (DbalGroupPriceAboveListIssueSource). Asked for
        // a contract, the honest answer is none, not those rows.
        if (0 !== $subscriptionRule) {
            return [];
        }

        $groups = array_values(array_filter($customerGroupIds, static fn (int $id): bool => $id > 0));

        // Same selection as the SQL: rows for everyone, plus the rows of the
        // groups asked for. Written out rather than shared, because sharing it
        // would mean one of the two calling the other — and then a broken
        // query would still pass its own test.
        return array_values(array_filter(
            $this->rows[$productId][$currency] ?? [],
            static fn (PriceRow $row): bool => null === $row->customerGroup || \in_array($row->customerGroup, $groups, true),
        ));
    }

    public function findProductIdsWithPrices(): array
    {
        return array_map('intval', array_keys($this->rows));
    }

    public function findCurrencies(): array
    {
        $currencies = [];

        foreach ($this->rows as $byCurrency) {
            foreach (array_keys($byCurrency) as $currency) {
                $currencies[$currency] = true;
            }
        }

        return array_map('strval', array_keys($currencies));
    }
}
