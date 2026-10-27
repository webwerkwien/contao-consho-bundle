<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Contao\CoreBundle\Intl\Countries;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

/**
 * Builds the real GrossCalculator via FixedTaxRates.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait TaxFixture
{
    /**
     * @param array<string, string> $rates ISO code => rate
     */
    protected function grossCalculator(array $rates = ['AT' => '20.0000']): GrossCalculator
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(array_keys($rates));

        return new GrossCalculator(new TaxResolver(new FixedTaxRates($rates), new CountryProvider($countries)));
    }
}
