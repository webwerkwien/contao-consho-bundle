<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\HomeCountries;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceEntry;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInput;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInputMode;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInputProblem;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInputRefused;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

/**
 * Connect Stufe 2, CW-2: die Regeln der gekoppelten Preismaske (C22) ohne
 * Maske — Betrag, Eingabeart, Währung und Land hinein, Netto oder ein
 * benannter Grund heraus.
 */
class PriceInputTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_800_000_000;

    public function testNettoWirdUebernommen(): void
    {
        $result = $this->input()->convert('10', PriceInputMode::Net, 'EUR', '', 1, 0, self::NOW);

        self::assertSame('10.0000', $result->net);
        self::assertSame(PriceInputMode::Net, $result->mode);
        self::assertSame('AT', $result->country);
    }

    public function testBruttoWirdMitDemSatzDesHeimatlandsUmgerechnet(): void
    {
        $result = $this->input()->convert('12', PriceInputMode::Gross, 'EUR', '', 1, 0, self::NOW);

        self::assertSame('10.0000', $result->net);
        self::assertSame('AT', $result->country);
    }

    public function testBeiZweiHeimatlaendernZaehltDasGewaehlte(): void
    {
        $result = $this->input([['EUR', 'AT'], ['EUR', 'DE']])->convert('11.90', PriceInputMode::Gross, 'EUR', 'DE', 1, 0, self::NOW);

        self::assertSame('10.0000', $result->net);
        self::assertSame('DE', $result->country);
    }

    /**
     * Netto braucht kein Land; ohne eindeutiges bleibt es leer.
     */
    public function testNettoOhneEindeutigesLand(): void
    {
        $result = $this->input([['EUR', 'AT'], ['EUR', 'DE']])->convert('10', PriceInputMode::Net, 'EUR', '', 0, 0, self::NOW);

        self::assertSame('10.0000', $result->net);
        self::assertNull($result->country);
    }

    public function testBruttoOhneEindeutigesLandWirdAbgelehnt(): void
    {
        $refused = $this->refused(fn () => $this->input([['EUR', 'AT'], ['EUR', 'DE']])->convert('12', PriceInputMode::Gross, 'EUR', '', 1, 0, self::NOW));

        self::assertSame(PriceInputProblem::CountryMissing, $refused->problem);
        self::assertSame('EUR', $refused->currency);
        self::assertSame(['AT', 'DE'], $refused->countries);
    }

    public function testBruttoOhneSteuerklasseWirdAbgelehnt(): void
    {
        $refused = $this->refused(fn () => $this->input()->convert('12', PriceInputMode::Gross, 'EUR', '', 0, 0, self::NOW));

        self::assertSame(PriceInputProblem::TaxClassMissing, $refused->problem);
    }

    public function testOhneSatzImLandWirdAbgelehnt(): void
    {
        $refused = $this->refused(fn () => $this->input([['CHF', 'CH']])->convert('12', PriceInputMode::Gross, 'CHF', '', 1, 0, self::NOW));

        self::assertSame(PriceInputProblem::TaxRateMissing, $refused->problem);
        self::assertSame('CH', $refused->country);
        self::assertSame(self::NOW, $refused->timestamp);
    }

    /**
     * Umgerechnet wird zum Beginn des Preises, frühestens heute.
     */
    public function testUmgerechnetWirdZumBeginnFruehestensHeute(): void
    {
        self::assertSame(self::NOW + 50, PriceInput::conversionTime(self::NOW + 50, self::NOW));
        self::assertSame(self::NOW, PriceInput::conversionTime(self::NOW - 50, self::NOW));

        $refused = $this->refused(fn () => $this->input([['CHF', 'CH']])->convert('12', PriceInputMode::Gross, 'CHF', '', 1, self::NOW + 50, self::NOW));
        self::assertSame(self::NOW + 50, $refused->timestamp);
    }

    public function testNegativWirdAbgelehnt(): void
    {
        self::assertSame(PriceInputProblem::Negative, $this->refused(fn () => $this->input()->convert('-1', PriceInputMode::Net, 'EUR', '', 1, 0, self::NOW))->problem);
    }

    public function testKeineZahlWirdAbgelehnt(): void
    {
        self::assertSame(PriceInputProblem::InvalidAmount, $this->refused(fn () => $this->input()->convert('zehn', PriceInputMode::Net, 'EUR', '', 1, 0, self::NOW))->problem);
    }

    /**
     * net ist decimal(12,4) — geprüft am Netto, nicht am Eingegebenen.
     */
    public function testZuGrossWirdAbgelehnt(): void
    {
        self::assertSame('99999999.9999', $this->input()->convert('99999999.9999', PriceInputMode::Net, 'EUR', '', 1, 0, self::NOW)->net);
        self::assertSame(PriceInputProblem::TooLarge, $this->refused(fn () => $this->input()->convert('100000000', PriceInputMode::Net, 'EUR', '', 1, 0, self::NOW))->problem);
        self::assertSame(PriceInputProblem::TooLarge, $this->refused(fn () => $this->input()->convert('120000000', PriceInputMode::Gross, 'EUR', '', 1, 0, self::NOW))->problem);
    }

    public function testWaehrungOhneShopWirdAbgelehnt(): void
    {
        $this->input()->assertCurrency('EUR');
        $this->input()->assertCurrency('');

        $refused = $this->refused(fn () => $this->input()->assertCurrency('USD'));
        self::assertSame(PriceInputProblem::CurrencyWithoutShop, $refused->problem);
        self::assertSame('USD', $refused->currency);
    }

    private function refused(\Closure $call): PriceInputRefused
    {
        try {
            $call();
        } catch (PriceInputRefused $refused) {
            return $refused;
        }

        self::fail('Keine Ablehnung.');
    }

    /**
     * @param list<array{string, string}> $markets currency, country
     */
    private function input(array $markets = [['EUR', 'AT']]): PriceInput
    {
        $repository = $this->createMock(MarketRepositoryInterface::class);
        $repository->method('findMarkets')->willReturn(array_map(static fn (array $m): Market => new Market($m[0], $m[1]), $markets));

        return new PriceInput(new HomeCountries($repository), new PriceEntry($this->grossCalculator(['AT' => '20.0000', 'DE' => '19.0000'])));
    }
}
