<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

/**
 * Database access for tax resolution.
 *
 * An interface rather than direct model calls, so TaxResolver and
 * DeliveryCountryProvider can be tested without a database. The Contao
 * implementation sits next to it.
 */
interface TaxRateRepositoryInterface
{
    /**
     * @return string|null the rate as a decimal string, e.g. "20.0000"
     */
    public function findRate(int $taxClassId, string $countryCode, int $timestamp): ?string;

    /**
     * @return list<string> ISO codes that have a valid rate for this class at this time
     */
    public function findConfiguredCountries(int $taxClassId, int $timestamp): array;

    /**
     * @return list<int> tax class ids used by products visible in this shop
     */
    public function findTaxClassIdsUsedByShop(int $shopId): array;

    /**
     * Tax classes with a rate that begins or ends in (after, until] — an end
     * as the law reads it: at the start of the day after validTo.
     *
     * @return list<int> sorted ascending
     */
    public function findClassesWithBoundaryIn(int $after, int $until): array;
}
