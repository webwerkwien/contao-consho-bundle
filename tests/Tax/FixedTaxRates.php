<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;

/**
 * One fixed rate per country — whichever tax class is asked.
 *
 * For tests in which tax is not the subject: the catalogue fixtures create
 * products without a tax class (0), and no table of tax rates. Which class
 * counts is checked by DbalArticleTaxClassesTest; how the rate is found, by
 * ContaoTaxRateRepositoryTest.
 */
final class FixedTaxRates implements TaxRateRepositoryInterface
{
    /**
     * @param array<string, string> $rates ISO code => rate, e.g. ['AT' => '20.0000']
     */
    public function __construct(private readonly array $rates)
    {
    }

    public function findRate(int $taxClassId, string $countryCode, int $timestamp): ?string
    {
        return $this->rates[$countryCode] ?? null;
    }

    public function findConfiguredCountries(int $taxClassId, int $timestamp): array
    {
        return array_keys($this->rates);
    }

    public function findTaxClassIdsUsedByShop(int $shopId): array
    {
        return [];
    }

    public function findClassesWithBoundaryIn(int $after, int $until): array
    {
        return [];
    }
}
