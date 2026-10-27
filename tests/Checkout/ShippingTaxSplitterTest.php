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
        // 75.00 books (class 1) and 25.00 wine (class 2): the shipping of
        // 8.00 follows the value of the goods, so 6.00 to 2.00.
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
     * WG8: a gift card is not goods — shipping does not follow it, otherwise
     * part of its tax would fall to "not taxable". Gift cards only: the class
     * of the shipping method, as without goods.
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
        // 30 + 20 in class 1 weigh as much as the 50 in class 2, otherwise
        // the split would come out 3:5 instead of 1:1. On a tie the
        // smaller class comes first (see below).
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
        // The control case for the test above: if weighted by LINES instead of
        // by VALUE, this would read 2:1 instead of 3:1.
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
     * 🔴 The point where an invoice would otherwise not add up: thirds cannot
     * be split onto four decimal places. The sum of the lines must still be
     * exactly the amount the visitor was told.
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
     * On a tie the smaller tax class decides, not the order in the basket —
     * otherwise two identical baskets would get invoices differing by a
     * hundredth of a cent depending on the order things were put in.
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
        // No amount, no tax, no line on the invoice.
        self::assertSame([], (new ShippingTaxSplitter())->split('0.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('10.0000', taxClass: 1),
        ]));
    }

    public function testOhneBepreisteWareFaelltDerVersandAufSeineEigeneKlasse(): void
    {
        // There is nothing it could follow. Its own class is not an
        // invented one — it is required in both modes.
        $shares = (new ShippingTaxSplitter())->split('5.0000', ShippingTaxMode::Proportional, 9, []);

        self::assertEquals([new ShippingShare(9, '5.0000')], $shares);
    }

    public function testBeiKostenloserWareFaelltDerVersandAufSeineEigeneKlasse(): void
    {
        // Weighting by value does not work when the value is zero.
        $shares = (new ShippingTaxSplitter())->split('5.0000', ShippingTaxMode::Proportional, 9, [
            $this->line('0.0000', taxClass: 1),
            $this->line('0.0000', taxClass: 2),
        ]);

        self::assertEquals([new ShippingShare(9, '5.0000')], $shares);
    }

    /**
     * Split onto two decimal places, 4.95 does not divide into equal parts —
     * the sum must still be exactly 4.95, otherwise the invoice contradicts
     * the offer.
     */
    public function testAufWaehrungsstellenGeteiltBleibtDieSummeGenau(): void
    {
        $shares = (new ShippingTaxSplitter())->split('4.9500', ShippingTaxMode::Proportional, 9, [
            $this->line('50.0000', taxClass: 1),
            $this->line('50.0000', taxClass: 2),
        ], digits: 2);

        /*
         * 2.475 rounds twice to 2.48, together 4.96 — so the remainder is
         * NEGATIVE and is subtracted, not added. It goes to the same place
         * as a positive one: to the first share, here the smaller class. The
         * cent thus lands at the lower rate, which is a decision and not a
         * coincidence — it stands here so that nobody later takes it for a
         * rounding error.
         *
         * The first share carries FOUR decimal places, because the remainder
         * has to land exactly; the totals round it afterwards, as they do
         * with every goods line.
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
     * 🔴 This case was introduced by an earlier fix of the rounding.
     *
     * A shipping price the operator types in as **4.95 gross** is stored as
     * 4.1250 net in the column (PriceEntry, 5.1). The first version of the fix
     * rounded the AMOUNT to currency places — 4.13 — and showed the customer
     * 4.96. The same wrong cent as before, just from the other side.
     *
     * So the amount keeps four decimal places; the SHARES are rounded.
     */
    public function testEinBruttoEingegebenerVersandpreisBleibtBrutto(): void
    {
        $shares = (new ShippingTaxSplitter())->split('4.1250', ShippingTaxMode::Fixed, 1, [
            $this->line('50.0000', taxClass: 1),
        ], digits: 2);

        self::assertEquals([new ShippingShare(1, '4.1250')], $shares);

        // 4.1250 × 1.20 = 4.9500 — exactly the gross price typed in.
        self::assertSame(
            '4.9500',
            (string) BigDecimal::of($shares[0]->net)->multipliedBy('1.20')->toScale(4, RoundingMode::HalfUp),
        );
    }

    public function testAuchAnteiligBleibtDerBruttopreisErhalten(): void
    {
        // 4.1250 in equal parts: 2.06 + 2.06 = 4.12, remainder 0.0050 on the
        // first. Gross 2.478 + 2.472 = 4.95.
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
     * H: one discount per class AND country, each share with its country —
     * also the one the remainder falls on. With equal value the key decides,
     * not the order in the basket.
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
        // Shipping and fees follow the delivery: one line per class, without a country.
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
