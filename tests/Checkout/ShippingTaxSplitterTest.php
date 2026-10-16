<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingShare;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingTaxSplitter;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;

class ShippingTaxSplitterTest extends TestCase
{
    public function testEineFesteSteuerklasseGibtEineEinzigeZeile(): void
    {
        $shares = (new ShippingTaxSplitter())->split('5.0000', ShippingTaxMode::Fixed, 3, [
            $this->line('100.0000', taxClass: 1),
            $this->line('100.0000', taxClass: 2),
        ]);

        self::assertEquals([new ShippingShare(3, '5.0000')], $shares);
    }

    public function testAnteiligWirdNachWarenwertAufgeteilt(): void
    {
        // 75,00 Bücher (Klasse 1) und 25,00 Wein (Klasse 2): der Versand von
        // 8,00 folgt dem Warenwert, also 6,00 zu 2,00.
        $shares = (new ShippingTaxSplitter())->split('8.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('75.0000', taxClass: 1),
            $this->line('25.0000', taxClass: 2),
        ]);

        self::assertEquals(
            [new ShippingShare(1, '6.0000'), new ShippingShare(2, '2.0000')],
            $shares,
        );
    }

    /**
     * WG8: Ein Gutschein ist keine Ware — der Versand folgt ihm nicht, sonst
     * fiele ein Teil seiner Steuer auf „nicht steuerbar". Nur Gutscheine:
     * die Klasse der Versandart, wie ohne Waren.
     */
    public function testEinGutscheinTraegtKeinenAnteil(): void
    {
        $voucher = new CartLine(2, 8, 8, '1.0000', '50.0000', '50.0000', '50.0000', 7, kind: ProductKind::Voucher);

        $shares = (new ShippingTaxSplitter())->split('8.0000', ShippingTaxMode::Proportional, 9, [$this->line('75.0000', taxClass: 1), $voucher]);
        self::assertEquals([new ShippingShare(1, '8.0000')], $shares);

        self::assertEquals([new ShippingShare(9, '8.0000')], (new ShippingTaxSplitter())->split('8.0000', ShippingTaxMode::Proportional, 9, [$voucher]));
    }

    public function testMehrereZeilenDerselbenKlasseZaehlenZusammen(): void
    {
        // 30 + 20 in Klasse 1 wiegen so viel wie die 50 in Klasse 2, sonst
        // ginge die Aufteilung 3:5 statt 1:1 aus. Bei Gleichstand steht die
        // kleinere Klasse vorn (siehe unten).
        $shares = (new ShippingTaxSplitter())->split('10.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('30.0000', taxClass: 1),
            $this->line('20.0000', taxClass: 1),
            $this->line('50.0000', taxClass: 2),
        ]);

        self::assertEquals(
            [new ShippingShare(1, '5.0000'), new ShippingShare(2, '5.0000')],
            $shares,
        );
    }

    public function testDreiZeilenZweiKlassenWerdenNichtStueckweiseGewichtet(): void
    {
        // Die Gegenprobe zum Test darüber: würde nach ZEILEN statt nach WERT
        // gewichtet, stünde hier 2:1 statt 3:1.
        $shares = (new ShippingTaxSplitter())->split('8.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('30.0000', taxClass: 1),
            $this->line('45.0000', taxClass: 1),
            $this->line('25.0000', taxClass: 2),
        ]);

        self::assertEquals(
            [new ShippingShare(1, '6.0000'), new ShippingShare(2, '2.0000')],
            $shares,
        );
    }

    /**
     * 🔴 Der Punkt, an dem eine Rechnung sonst nicht aufgeht: Drittel lassen
     * sich nicht auf vier Stellen aufteilen. Die Summe der Zeilen muss
     * trotzdem genau der Betrag sein, der dem Besucher genannt wurde.
     */
    public function testDieAufteilungSummiertSichExaktAufDenVersandbetrag(): void
    {
        $shares = (new ShippingTaxSplitter())->split('10.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('10.0000', taxClass: 1),
            $this->line('10.0000', taxClass: 2),
            $this->line('10.0000', taxClass: 3),
        ]);

        self::assertCount(3, $shares);

        $sum = BigDecimal::zero();

        foreach ($shares as $share) {
            $sum = $sum->plus($share->net);
        }

        self::assertSame(0, $sum->compareTo(BigDecimal::of('10.0000')), 'Die Zeilen ergeben zusammen den Versandbetrag.');
    }

    /**
     * Bei Gleichstand entscheidet die kleinere Steuerklasse, nicht die
     * Reihenfolge im Warenkorb — sonst bekämen zwei gleiche Warenkörbe je nach
     * Einlegereihenfolge Rechnungen, die sich um einen Hundertstelcent
     * unterscheiden.
     */
    public function testBeiGleichemWarenwertEntscheidetNichtDieReihenfolge(): void
    {
        $splitter = new ShippingTaxSplitter();

        $vorwaerts = $splitter->split('10.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('10.0000', taxClass: 1),
            $this->line('10.0000', taxClass: 2),
            $this->line('10.0000', taxClass: 3),
        ]);

        $rueckwaerts = $splitter->split('10.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('10.0000', taxClass: 3),
            $this->line('10.0000', taxClass: 2),
            $this->line('10.0000', taxClass: 1),
        ]);

        self::assertEquals($vorwaerts, $rueckwaerts);
        self::assertSame(1, $vorwaerts[0]->taxClassId);
    }

    public function testOhneVersandkostenEntstehtKeineZeile(): void
    {
        // Kein Betrag, keine Steuer, keine Zeile auf der Rechnung.
        self::assertSame([], (new ShippingTaxSplitter())->split('0.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('10.0000', taxClass: 1),
        ]));
    }

    public function testOhneBepreisteWareFaelltDerVersandAufSeineEigeneKlasse(): void
    {
        // Es gibt nichts, dem er folgen könnte. Die eigene Klasse ist keine
        // erfundene — sie ist in beiden Modi Pflicht.
        $shares = (new ShippingTaxSplitter())->split('5.0000', ShippingTaxMode::Proportional, 9, []);

        self::assertEquals([new ShippingShare(9, '5.0000')], $shares);
    }

    public function testBeiKostenloserWareFaelltDerVersandAufSeineEigeneKlasse(): void
    {
        // Gewichten nach Wert geht nicht, wenn der Wert null ist.
        $shares = (new ShippingTaxSplitter())->split('5.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('0.0000', taxClass: 1),
            $this->line('0.0000', taxClass: 2),
        ]);

        self::assertEquals([new ShippingShare(9, '5.0000')], $shares);
    }

    /**
     * Auf zwei Stellen geteilt geht 4,95 zu gleichen Teilen nicht auf — die
     * Summe muss trotzdem genau 4,95 sein, sonst widerspricht die Rechnung
     * dem Angebot.
     */
    public function testAufWaehrungsstellenGeteiltBleibtDieSummeGenau(): void
    {
        $shares = (new ShippingTaxSplitter())->split('4.9500', ShippingTaxMode::Proportional, 9, [
            $this->line('50.0000', taxClass: 1),
            $this->line('50.0000', taxClass: 2),
        ], digits: 2);

        /*
         * 2,475 rundet zweimal auf 2,48, zusammen 4,96 — der Rest ist also
         * NEGATIV und wird abgezogen, nicht aufgeschlagen. Er geht an
         * dieselbe Stelle wie ein positiver: an den ersten Anteil, hier die
         * kleinere Klasse. Der Cent landet damit beim niedrigeren Satz, was
         * eine Entscheidung ist und keine Zufälligkeit — sie steht hier, damit
         * niemand sie später für einen Rundungsfehler hält.
         *
         * Der erste Anteil trägt VIER Stellen, weil der Rest exakt landen
         * muss; die Summen runden ihn danach, wie sie es mit jeder Warenzeile
         * tun.
         */
        self::assertEquals(
            [new ShippingShare(1, '2.4700'), new ShippingShare(2, '2.48')],
            $shares,
        );

        $sum = BigDecimal::zero();

        foreach ($shares as $share) {
            $sum = $sum->plus($share->net);
        }

        self::assertSame(0, $sum->compareTo(BigDecimal::of('4.95')));
    }

    /**
     * 🔴 Im ZWEITEN Review vom 2026-09-22 gefunden, und es war meine eigene
     * Korrektur, die ihn erzeugte.
     *
     * Ein Versandpreis, den der Betreiber als **4,95 brutto** eintippt, liegt
     * als 4,1250 netto in der Spalte (PriceEntry, 5.1). Die erste Fassung der
     * Korrektur rundete den BETRAG auf Währungsstellen — 4,13 — und zeigte
     * dem Kunden 4,96. Derselbe falsche Cent wie vorher, nur von der anderen
     * Seite.
     *
     * Der Betrag behält also vier Stellen; gerundet werden die ANTEILE.
     */
    public function testEinBruttoEingegebenerVersandpreisBleibtBrutto(): void
    {
        $shares = (new ShippingTaxSplitter())->split('4.1250', ShippingTaxMode::Fixed, 1, [
            $this->line('50.0000', taxClass: 1),
        ], digits: 2);

        self::assertEquals([new ShippingShare(1, '4.1250')], $shares);

        // 4,1250 × 1,20 = 4,9500 — genau der eingetippte Bruttopreis.
        self::assertSame(
            '4.9500',
            (string) BigDecimal::of($shares[0]->net)->multipliedBy('1.20')->toScale(4, RoundingMode::HalfUp),
        );
    }

    public function testAuchAnteiligBleibtDerBruttopreisErhalten(): void
    {
        // 4,1250 zu gleichen Teilen: 2,06 + 2,06 = 4,12, Rest 0,0050 auf den
        // ersten. Brutto 2,478 + 2,472 = 4,95.
        $shares = (new ShippingTaxSplitter())->split('4.1250', ShippingTaxMode::Proportional, 9, [
            $this->line('50.0000', taxClass: 1),
            $this->line('50.0000', taxClass: 2),
        ], digits: 2);

        $sum = BigDecimal::zero();

        foreach ($shares as $share) {
            $sum = $sum->plus($share->net);
        }

        self::assertSame(0, $sum->compareTo(BigDecimal::of('4.1250')));
    }

    /**
     * Review H, S1 (zweites Review, P-1): ein Nachlass je Klasse UND Land,
     * jeder Anteil mit seinem Land — auch der, auf den der Rest fällt. Bei
     * gleichem Wert entscheidet der Schlüssel, nicht die Reihenfolge im Korb.
     */
    public function testEinNachlassTeiltNachKlasseUndLand(): void
    {
        $shares = (new ShippingTaxSplitter())->split('1.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('33.3300', taxClass: 1, country: 'DE'),
            $this->line('33.3300', taxClass: 1, country: 'AT'),
            $this->line('33.3400', taxClass: 2, country: 'DE'),
        ], digits: 2, byCountry: true);

        self::assertEquals(
            [new ShippingShare(2, '0.3400', '0.0000', 'DE'), new ShippingShare(1, '0.33', '0.0000', 'AT'), new ShippingShare(1, '0.33', '0.0000', 'DE')],
            $shares,
        );
    }

    public function testOhneLandBleibtEsJeKlasse(): void
    {
        // Versand und Gebühren folgen der Lieferung: eine Zeile je Klasse, ohne Land.
        $shares = (new ShippingTaxSplitter())->split('1.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('33.3300', taxClass: 1, country: 'DE'),
            $this->line('33.3300', taxClass: 1, country: 'AT'),
        ], digits: 2);

        self::assertEquals([new ShippingShare(1, '1.00')], $shares);
    }

    private function line(string $net, int $taxClass, string $country = ''): CartLine
    {
        return new CartLine(1, 7, 7, '1.0000', $net, $net, $net, $taxClass, taxCountry: $country);
    }
}
