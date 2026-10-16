<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalPriceRepository implements PriceRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findCandidates(int $productId, string $currency, array $customerGroupIds, int $timestamp, int $subscriptionRule = 0): array
    {
        /*
         * NULL means "no boundary" — the validity columns are NULL-able so an
         * open-ended price is empty in the back end rather than 01.01.1970.
         * See tl_consho_price for what `default 0` costs.
         *
         * From inclusive, to exclusive — Contao's rule for start/stop
         * (ContentModel: `start<=$time`, `stop>$time`), so a price valid to
         * 12:00 is gone at 12:00, as a content element would be.
         *
         * tstamp > 0: Contao inserts the row when the price mask opens
         * (DC_Table::create()) and sets tstamp on the first save. Until then
         * it holds net 0 in the default currency — counted, it would be a live
         * price of 0, or a tie with the real row, while the operator is still
         * typing. "Back" deletes it (reviseTable()).
         *
         * `customerGroup IN (…)` since C-4, and 0 is never in that list: it is
         * the column's way of saying "everyone", not a group id. The zero row
         * comes in through the first condition, the member's groups through
         * the second — and an empty list leaves only the first, which is the
         * guest.
         *
         * subscriptionRule = 0 since Abo-1: a row with a subscription rule is
         * the price of another contract, not a cheaper offer for the same one
         * (docs/block-abo.md, A2). Counted here, the monthly amount would win
         * as the cheapest and become the list price of a product sold for
         * good at twenty times that. Since Abo-2 the rule is asked for rather
         * than fixed: a subscription line gets the rows of its own contract.
         */
        $groups = array_values(array_filter($customerGroupIds, static fn (int $id): bool => $id > 0));

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT net, currency, customerGroup, minQuantity, validFrom, validTo
                 FROM tl_consho_price
                 WHERE pid = :pid AND subscriptionRule = :rule AND currency = :currency AND tstamp > 0
                   AND (customerGroup = 0%s)
                   AND (validFrom IS NULL OR validFrom <= :ts)
                   AND (validTo IS NULL OR validTo > :ts)',
                [] === $groups ? '' : ' OR customerGroup IN (:groups)',
            ),
            [
                'pid' => $productId,
                'rule' => $subscriptionRule,
                'currency' => $currency,
                'ts' => $timestamp,
                ...([] === $groups ? [] : ['groups' => $groups]),
            ],
            [] === $groups ? [] : ['groups' => ArrayParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): PriceRow => new PriceRow(
                (string) $row['net'],
                (string) $row['currency'],
                ((int) $row['customerGroup']) > 0 ? (int) $row['customerGroup'] : null,
                (string) $row['minQuantity'],
                null === $row['validFrom'] ? null : (int) $row['validFrom'],
                null === $row['validTo'] ? null : (int) $row['validTo'],
            ),
            $rows,
        );
    }

    /**
     * Every rule since Abo-2b: a product not sold once is listed with its
     * first plan (P88), and the projection only ever looks at products and
     * currencies found here. Which row is the list price decides
     * ArticlePriceResolver::listPrice(), never this list.
     */
    public function findProductIdsWithPrices(): array
    {
        // tstamp > 0: see the comment in findCandidates().
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT pid FROM tl_consho_price WHERE tstamp > 0',
        ));

        return $ids;
    }

    public function findCurrencies(): array
    {
        // tstamp > 0: see the comment in findCandidates(); every rule, see findProductIdsWithPrices().
        /** @var list<string> $currencies */
        $currencies = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT currency FROM tl_consho_price WHERE tstamp > 0',
        );

        return $currencies;
    }
}
