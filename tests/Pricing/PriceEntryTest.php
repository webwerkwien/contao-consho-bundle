<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceEntry;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInputMode;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class PriceEntryTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_800_000_000;

    public function testEineNettoEingabeWirdAufVierStellenGespeichert(): void
    {
        self::assertSame('10.5000', $this->entry()->toNet(PriceInputMode::Net, '10.5', 1, 'AT', self::NOW));
    }

    public function testEineBruttoEingabeErgibtDasNettoMitDemSatzDesBezugslands(): void
    {
        self::assertSame('10.0000', $this->entry()->toNet(PriceInputMode::Gross, '12.00', 1, 'AT', self::NOW));
        self::assertSame('10.0000', $this->entry()->toNet(PriceInputMode::Gross, '11.90', 1, 'DE', self::NOW));
    }

    public function testEinKrummesBruttoWirdAufVierStellenGerundet(): void
    {
        // 19.99 / 1.2 = 16.658333…
        self::assertSame('16.6583', $this->entry()->toNet(PriceInputMode::Gross, '19.99', 1, 'AT', self::NOW));
    }

    public function testEineBruttoZeileZeigtBeimOeffnenDasBruttoAusNetto(): void
    {
        self::assertSame('19.99', $this->entry()->toAmount(PriceInputMode::Gross, '16.6583', 1, 'AT', self::NOW, 'EUR'));
    }

    public function testEineNettoZeileZeigtBeimOeffnenIhrNetto(): void
    {
        self::assertSame('16.6583', $this->entry()->toAmount(PriceInputMode::Net, '16.6583', 1, 'AT', self::NOW, 'EUR'));
    }

    public function testEinBruttoAufDenCentKommtNachDemSpeichernUnveraendertZurueck(): void
    {
        // The promise to the operator: whoever types 19.99 gross sees
        // 19.99 again the next time they open it — at every rate, not only at 20 %.
        foreach (['20.0000', '19.0000', '10.0000', '7.0000', '8.1000', '5.5000', '27.0000'] as $rate) {
            $entry = new PriceEntry($this->grossCalculator(['AT' => $rate]));

            for ($cents = 1; $cents <= 20_000; $cents += 37) {
                $gross = \sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
                $net = $entry->toNet(PriceInputMode::Gross, $gross, 1, 'AT', self::NOW);
                $back = $entry->toAmount(PriceInputMode::Gross, $net, 1, 'AT', self::NOW, 'EUR');

                self::assertSame($gross, $back, \sprintf('Satz %s, Brutto %s, Netto %s', $rate, $gross, $net));
                self::assertSame($net, $entry->toNet(PriceInputMode::Gross, $back, 1, 'AT', self::NOW), \sprintf('Satz %s, Brutto %s, Netto %s', $rate, $gross, $net));
            }
        }
    }

    public function testOhneSatzImBezugslandScheitertDieUmrechnung(): void
    {
        $this->expectException(TaxRateNotFound::class);

        $this->entry()->toNet(PriceInputMode::Gross, '12.00', 1, 'CH', self::NOW);
    }

    public function testEineNettoEingabeBrauchtKeinenSatz(): void
    {
        // A net row can also be saved for a country without a rate —
        // the form has nothing to convert.
        self::assertSame('12.0000', $this->entry()->toNet(PriceInputMode::Net, '12', 1, 'CH', self::NOW));
    }

    public function testEinCentbetragBei19ProzentKommtOhneVierteStelleZurueck(): void
    {
        // 0.13 gross gives net 0.1092 — and from that again 0.1299 gross to
        // four decimals. Rounded to the cent that is 0.13 again.
        self::assertSame('0.13', $this->entry()->toAmount(PriceInputMode::Gross, '0.1092', 1, 'DE', self::NOW, 'EUR'));
    }

    public function testEinBruttoMitMehrNachkommastellenBleibtVierstellig(): void
    {
        // 0.0013 net gives gross 0.0016 — rounded to the cent 0.00, and
        // on saving that would become net 0. So it stays at four decimals.
        self::assertSame('0.0016', $this->entry()->toAmount(PriceInputMode::Gross, '0.0013', 1, 'AT', self::NOW, 'EUR'));
    }

    public function testEineWaehrungOhneNachkommastellenZeigtGanzeBetraege(): void
    {
        self::assertSame('1000', $this->entry()->toAmount(PriceInputMode::Gross, '833.3333', 1, 'AT', self::NOW, 'JPY'));
    }

    public function testEineWaehrungMitDreiNachkommastellenZeigtDreiStellen(): void
    {
        // Bahraini dinar: 1 dinar = 1000 fils.
        self::assertSame('1.000', $this->entry()->toAmount(PriceInputMode::Gross, '0.8333', 1, 'AT', self::NOW, 'BHD'));
    }

    public function testEineNettoEingabeRundetKaufmaennisch(): void
    {
        self::assertSame('10.0001', $this->entry()->toNet(PriceInputMode::Net, '10.00005', 1, 'AT', self::NOW));
    }

    private function entry(): PriceEntry
    {
        return new PriceEntry($this->grossCalculator(['AT' => '20.0000', 'DE' => '19.0000']));
    }
}
