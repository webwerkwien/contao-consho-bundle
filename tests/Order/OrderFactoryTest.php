<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeAllocator;
use Webwerkwien\ContaoConshoBundle\Order\OrderDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderFactory;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderProductRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderWriterInterface;
use Webwerkwien\ContaoConshoBundle\Order\ProductGoneFromCatalogue;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;
use Webwerkwien\ContaoConshoBundle\Order\TotalDisagreesWithCheckout;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRow;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

class OrderFactoryTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $writtenOrders = [];

    /** @var list<array<string, mixed>> */
    private array $writtenItems = [];

    protected function setUp(): void
    {
        $this->writtenOrders = [];
        $this->writtenItems = [];
    }

    public function testFreezesTheOrderNumberNameAndPriceOntoTheOrder(): void
    {
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(1, '2')], 1_757_000_000);

        self::assertSame('WEB-00001', $this->writtenOrders[0]['orderNumber']);
        self::assertSame('Schwedenbitter', $this->writtenItems[0]['productName']);
        self::assertSame('SB-250', $this->writtenItems[0]['sku']);
        self::assertSame('10.0000', $this->writtenItems[0]['unitPrice']);
        self::assertSame(PriceSource::List->value, $this->writtenItems[0]['priceSource']);
    }

    /**
     * F-1, F13: whether a line is physical is frozen at purchase — withdrawal
     * and delivery depend on it, the product type can change. A cost line is
     * not goods.
     */
    public function testFriertKoerperlichOderDigitalEin(): void
    {
        $this->factory()->create(
            $this->draft('AT'),
            [
                OrderLineDraft::product(1, '1'),
                OrderLineDraft::product(2, '1'),
                OrderLineDraft::charge(OrderLineType::Shipping, 'Paket', '5.0000', 1, 1),
            ],
            1_757_000_000,
        );

        self::assertSame(['physical', 'service', ''], array_column($this->writtenItems, 'kind'));
    }

    /**
     * Abo-2, P13: the line carries its model, its terms and its kind (DL1) —
     * frozen, like name and price. Article 2 is a service, its monthly
     * subscription (rule 5) costs 1.00 net.
     */
    public function testFriertModellBedingungenUndWiderrufsartEin(): void
    {
        $this->factory()->create(
            $this->draft('AT'),
            [
                OrderLineDraft::product(2, '2', 5),
                OrderLineDraft::product(1, '1'),
                OrderLineDraft::charge(OrderLineType::Shipping, 'Paket', '5.0000', 1, 1),
            ],
            1_757_000_000,
        );

        self::assertSame('1.0000', $this->writtenItems[0]['unitPrice'], 'der Preis des Abos, nicht der Einmalkauf');
        self::assertSame([5, 0, 0], array_column($this->writtenItems, 'subscriptionRule'));
        // Gross: 1.00 × 2 = 2.00 net + 20 %.
        self::assertSame(['Bedingungen 5 zu 2.4000', '', ''], array_column($this->writtenItems, 'subscriptionTerms'));
        self::assertSame(['service', 'physical', ''], array_column($this->writtenItems, 'kind'));
    }

    public function testDieVerlaengerungStehtNurAnDerAboZeile(): void
    {
        // Abo-7b (P69): the line names the renewed subscription; a
        // one-off line with the same detail would not carry it on.
        $this->factory()->create(
            $this->draft('AT'),
            [
                OrderLineDraft::product(2, '2', 5, 31),
                OrderLineDraft::product(1, '1', 0, 31),
                OrderLineDraft::charge(OrderLineType::Shipping, 'Paket', '5.0000', 1, 1),
            ],
            1_757_000_000,
        );

        self::assertSame([31, 0, 0], array_map(static fn (array $item): int => (int) ($item['renews'] ?? 0), $this->writtenItems));
    }

    public function testUnterUmkehrDerSteuerschuldNenntDieBedingungDenNettobetrag(): void
    {
        $this->factory()->create(
            $this->draft('DE', $this->vatCheck(VatIdStatus::Valid)),
            [OrderLineDraft::product(2, '2', 5)],
            1_757_000_000,
        );

        self::assertSame('Bedingungen 5 zu 2.0000', $this->writtenItems[0]['subscriptionTerms'], 'der Nettobetrag der Zeile');
    }

    public function testDieModellangabeBleibtAnDerZeichnung(): void
    {
        self::assertSame(5, OrderLineDraft::product(2, '1', 5)->subscriptionRule);
        self::assertSame(0, OrderLineDraft::product(2)->subscriptionRule);
        self::assertSame(0, OrderLineDraft::charge(OrderLineType::Shipping, 'Paket', '5.0000', 1)->subscriptionRule);
    }

    public function testComputesTotalsFromNetAndTheResolvedRate(): void
    {
        // 2 × 10.00 net at 20 % -> 20.00 net, 4.00 tax, 24.00 gross.
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(1, '2')], 1_757_000_000);

        // The totals carry the currency's decimal places, the rate does not: a
        // tax rate is not an amount of money and stays at four places.
        self::assertSame('20.00', $this->writtenOrders[0]['netTotal']);
        self::assertSame('4.00', $this->writtenOrders[0]['taxTotal']);
        self::assertSame('24.00', $this->writtenOrders[0]['grandTotal']);
        self::assertSame('20.0000', $this->writtenItems[0]['taxRate']);
    }

    public function testTheTotalsAddUpTheLinesAsAnInvoiceShowsThem(): void
    {
        // The same effect as in the basket (920f74e), at the place where it
        // weighs heavier: 8.3367 net, 20 %, two lines of quantity 1.
        //
        //   line net    8.3367 → shown 8.34, twice   = 16.68
        //   line gross 10.0040 → shown 10.00, twice  = 20.00
        //   summed raw 16.6734 and 20.0080 → 16.67 / 20.01
        //
        // So neither column would add up to the number below it.
        $factory = $this->factory('8.3367');

        $factory->create(
            $this->draft('AT'),
            [OrderLineDraft::product(1, '1'), OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        // The line fields stay at four places: net is the anchor, the tax
        // still comes from the unrounded line net, and
        // DocumentPayloadBuilder computes net = lineTotal − taxAmount.
        self::assertSame('1.6673', $this->writtenItems[0]['taxAmount']);
        self::assertSame('10.0040', $this->writtenItems[0]['lineTotal']);

        self::assertSame('16.68', $this->writtenOrders[0]['netTotal']);
        self::assertSame('20.00', $this->writtenOrders[0]['grandTotal']);

        // The tax is the difference, not a sum of its own — otherwise the
        // order would again contradict the page it comes from. 3.32 instead
        // of 3.34: the price for the gross column adding up.
        self::assertSame('3.32', $this->writtenOrders[0]['taxTotal']);
    }

    /**
     * 🔴 This assertion used to stand on its head.
     *
     * It recorded that the gross column per line does NOT add up — and that
     * column is exactly the one the checkout sums. So it cemented an order
     * total that differs from what the customer read above the buy button and
     * confirmed under § 8 FAGG. Measured: for 16.7 % of all gross prices
     * between 1.00 and 99.99 at 20 %, already at €1.05.
     *
     * A technically flawless test that pins down a bug defends it from then
     * on.
     */
    public function testDieBestellsummeIstDieAufDieDerKundeGeklicktHat(): void
    {
        // Two lines of 8.3367 net, 20 %: 10.0040 per line, shown 10.00.
        // The customer adds 10.00 + 10.00 and expects 20.00 — the basket
        // shows 20.00 (CartPricerTest), and the order must say the same.
        // Net and tax rounded separately would give 20.02.
        $factory = $this->factory('8.3367');

        $factory->create(
            $this->draft('AT'),
            [OrderLineDraft::product(1, '1'), OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        $bruttoSpalte = BigDecimal::zero();

        foreach ($this->writtenItems as $item) {
            $bruttoSpalte = $bruttoSpalte->plus(
                BigDecimal::of((string) $item['lineTotal'])->toScale(2, RoundingMode::HalfUp),
            );
        }

        self::assertSame('20.00', (string) $bruttoSpalte);
        self::assertSame(
            (string) $bruttoSpalte,
            $this->writtenOrders[0]['grandTotal'],
            'Die Summe der angezeigten Zeilen IST die Bestellsumme. Weicht sie ab, bekommt der Kunde eine Rechnung über einen anderen Betrag als den, dem er zugestimmt hat.',
        );

        // And the price for it, shown openly: net + tax does not give
        // grandTotal here. Three columns, two roundings — one cannot add up,
        // and from now on it is this one. The difference is limited to one
        // cent PER TAX RATE; the gross variant grew with every
        // line.
        self::assertSame('16.68', $this->writtenOrders[0]['netTotal']);
        self::assertSame('3.32', $this->writtenOrders[0]['taxTotal']);
    }

    public function testUsesTheDeliveryCountrysRateNotTheShopCountrys(): void
    {
        // The whole point of resolving tax per order: 19 % for Germany —
        // once the shop taxes at destination (block R, O1).
        $factory = $this->factory();

        $factory->create($this->draft('DE', taxAtDestination: true), [OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertSame('19.0000', $this->writtenItems[0]['taxRate']);
        self::assertSame('11.90', $this->writtenOrders[0]['grandTotal']);
    }

    /**
     * Block R (O1, R2, R4): without "tax in the country of destination" the
     * shop's rate — also for the shipping that follows the goods —, and every
     * line records where it was delivered.
     */
    public function testOhneSchalterDerSatzDesShopsUndDasBestimmungsland(): void
    {
        $this->factory()->create(
            $this->draft('DE'),
            [OrderLineDraft::product(1, '1'), OrderLineDraft::charge(OrderLineType::Shipping, 'Paket', '5.0000', 1, 1)],
            1_757_000_000,
        );

        self::assertSame(['AT:20.0000', 'AT:20.0000'], array_map(static fn (array $item): string => $item['taxCountry'].':'.$item['taxRate'], $this->writtenItems));
        self::assertSame(['DE', 'DE'], array_column($this->writtenItems, 'destinationCountry'));
    }

    /**
     * Block GARAN (GA6): duration and label stand on the item as they stood
     * before the button; without a guarantee 0 and NULL.
     */
    public function testDieGarantieStehtAnDerPosition(): void
    {
        $this->factory()->create($this->draft('AT'), [OrderLineDraft::product(1, '1'), OrderLineDraft::product(4, '1')], 1_757_000_000);

        self::assertSame(['5', '0'], array_column($this->writtenItems, 'durabilityYears'));
        self::assertSame([str_repeat("\x07", 16), null], array_column($this->writtenItems, 'durabilityLabel'));
    }

    /**
     * WG3: the gift card with class "not taxable" is not a line at 0 % of a
     * country, but not taxable — without a country. Next to goods at the
     * standard rate.
     */
    public function testDerGutscheinIstNichtSteuerbarUndOhneLand(): void
    {
        $this->factory()->create($this->draft('DE', taxAtDestination: true), [OrderLineDraft::product(4, '1'), OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertSame(['0.0000', '0.0000', '', 'notTaxable', '50.0000'], [
            $this->writtenItems[0]['taxRate'], $this->writtenItems[0]['taxAmount'], $this->writtenItems[0]['taxCountry'],
            $this->writtenItems[0]['priceSource'], $this->writtenItems[0]['lineTotal'],
        ]);
        self::assertSame(['19.0000', 'DE', 'list'], [$this->writtenItems[1]['taxRate'], $this->writtenItems[1]['taxCountry'], $this->writtenItems[1]['priceSource']]);

        // WG-4: the gift card's value frozen like its price; 0 on the goods.
        self::assertSame(['50.0000', '0.0000'], array_column($this->writtenItems, 'voucherValue'));
    }

    /**
     * Under reverse charge too: the sale of a multi-purpose voucher is not a
     * supply whose tax could pass over — "not taxable" takes precedence.
     */
    public function testNichtSteuerbarGehtReverseChargeVor(): void
    {
        $this->factory()->create($this->draft('DE', $this->vatCheck(VatIdStatus::Valid)), [OrderLineDraft::product(4, '1'), OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertSame(['notTaxable', 'reverseCharge'], array_column($this->writtenItems, 'priceSource'));
    }

    /**
     * A gift card with a taxable class is sold as set up — taxed; the issue
     * reports the mistake, not the order.
     */
    public function testEinGutscheinMitSteuerbarerKlasseBleibtBesteuert(): void
    {
        $this->factory()->create($this->draft('DE', taxAtDestination: true), [OrderLineDraft::product(5, '1')], 1_757_000_000);

        self::assertSame(['19.0000', 'DE', 'list'], [$this->writtenItems[0]['taxRate'], $this->writtenItems[0]['taxCountry'], $this->writtenItems[0]['priceSource']]);
    }

    /**
     * EZ-1, EZ-5, F5: a single-purpose voucher is sold taxed at the shop's
     * rate, whatever the switch "Steuer im Bestimmungsland" says, and frozen
     * as single-purpose. The multi-purpose one beside it is not.
     */
    public function testEinEinzweckGutscheinTraegtDenSatzDesHeimatlands(): void
    {
        $this->factory()->create($this->draft('DE', taxAtDestination: true), [OrderLineDraft::product(6, '1'), OrderLineDraft::product(4, '1')], 1_757_000_000);

        self::assertSame(['20.0000', 'AT', 'DE', 'list', '60.0000', 1], [
            $this->writtenItems[0]['taxRate'], $this->writtenItems[0]['taxCountry'], $this->writtenItems[0]['destinationCountry'],
            $this->writtenItems[0]['priceSource'], $this->writtenItems[0]['lineTotal'], $this->writtenItems[0]['voucherSinglePurpose'],
        ]);
        self::assertSame(0, $this->writtenItems[1]['voucherSinglePurpose']);
    }

    /**
     * A sale that taxed nothing makes no single-purpose voucher: the class
     * "not taxable" (the finding says so) and reverse charge.
     */
    public function testOhneSteuerBeimVerkaufKeinEinzweckGutschein(): void
    {
        $this->factory()->create($this->draft('AT'), [OrderLineDraft::product(7, '1')], 1_757_000_000);
        self::assertSame(['notTaxable', 0], [$this->writtenItems[0]['priceSource'], $this->writtenItems[0]['voucherSinglePurpose']]);
    }

    /**
     * Under reverse charge the sale of a single-purpose voucher is a supply
     * whose tax passes over — not "nicht steuerbar" as for a multi-purpose one.
     */
    public function testUnterReverseChargeKeinEinzweckGutschein(): void
    {
        $this->factory()->create($this->draft('DE', $this->vatCheck(VatIdStatus::Valid)), [OrderLineDraft::product(6, '1')], 1_757_000_000);
        self::assertSame(['reverseCharge', 0], [$this->writtenItems[0]['priceSource'], $this->writtenItems[0]['voucherSinglePurpose']]);
    }

    public function testAppliesReverseChargeForAVerifiedIdAcrossTheBorder(): void
    {
        $factory = $this->factory();

        $factory->create(
            $this->draft('DE', $this->vatCheck(VatIdStatus::Valid)),
            [OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        self::assertSame('0.0000', $this->writtenItems[0]['taxRate']);
        self::assertSame('0.00', $this->writtenOrders[0]['taxTotal']);
        self::assertSame('10.00', $this->writtenOrders[0]['grandTotal']);
        self::assertSame(PriceSource::ReverseCharge->value, $this->writtenItems[0]['priceSource']);
    }

    /**
     * C-12: without the shop's VAT ID no tax-exempt supply — Art. 11 Abs. 2
     * UStG 1994 annex (internal market) requires it on the invoice.
     */
    public function testOhneEigeneUidDesShopsKeinReverseCharge(): void
    {
        $this->factory()->create(
            $this->draft('DE', $this->vatCheck(VatIdStatus::Valid), shopVatId: ''),
            [OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        self::assertNotSame('0.0000', $this->writtenItems[0]['taxRate']);
        self::assertNotSame(PriceSource::ReverseCharge->value, $this->writtenItems[0]['priceSource']);
    }

    public function testDoesNotApplyReverseChargeWithinTheShopsOwnCountry(): void
    {
        // A valid Austrian VAT ID buying from an Austrian shop still pays
        // Austrian VAT. Reverse charge is a cross-border rule.
        $factory = $this->factory();

        $factory->create(
            $this->draft('AT', $this->vatCheck(VatIdStatus::Valid)),
            [OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        self::assertSame('20.0000', $this->writtenItems[0]['taxRate']);
        self::assertSame(PriceSource::List->value, $this->writtenItems[0]['priceSource']);
    }

    public function testAnUnverifiableIdIsTaxedRatherThanExempted(): void
    {
        // The safe direction. Charging tax wrongly is an email; not charging it
        // wrongly is the shop owner's liability.
        $factory = $this->factory();

        $factory->create(
            $this->draft('DE', $this->vatCheck(VatIdStatus::Unavailable), taxAtDestination: true),
            [OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        self::assertSame('19.0000', $this->writtenItems[0]['taxRate']);
    }

    public function testFreezesTheVatEvidenceOntoTheOrder(): void
    {
        $factory = $this->factory();

        $factory->create(
            $this->draft('DE', $this->vatCheck(VatIdStatus::Valid)),
            [OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        self::assertSame('valid', $this->writtenOrders[0]['vatIdStatus']);
        self::assertSame('WAPI-123', $this->writtenOrders[0]['vatIdRequestIdentifier']);
    }

    public function testFreezesBothAddresses(): void
    {
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertSame('Hauptstraße 5', $this->writtenOrders[0]['billingStreet']);
        self::assertSame('Lagerweg 9', $this->writtenOrders[0]['shippingStreet']);
        self::assertSame('AT', $this->writtenOrders[0]['billingCountry']);
    }

    public function testRefusesAProductThatIsNoLongerInTheCatalogue(): void
    {
        $factory = $this->factory();

        $this->expectException(ProductGoneFromCatalogue::class);

        $factory->create($this->draft('AT'), [OrderLineDraft::product(99, '1')], 1_757_000_000);
    }

    public function testRefusesAnOrderWithoutLines(): void
    {
        $factory = $this->factory();

        $this->expectException(\InvalidArgumentException::class);

        $factory->create($this->draft('AT'), [], 1_757_000_000);
    }

    public function testEineVarianteOhnePreiszeileErbtDenPreisIhresHauptprodukts(): void
    {
        // The case the purchase click would have failed on until C-7: article 2
        // has no price row of its own and inherits it from the main product —
        // in list and detail page all along, when ordering only now.
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(2, '1')], 1_757_000_000);

        self::assertSame('10.0000', $this->writtenItems[0]['unitPrice']);

        // The SKU stays that of the VARIANT — it is never inherited,
        // and it is the only thing that tells two variants of a product apart
        // in the basket as on the invoice.
        self::assertSame('SB-250-KL', $this->writtenItems[0]['sku']);
    }

    public function testDieGewinnendeKundengruppeStehtAnDerZeileNichtAmKopf(): void
    {
        // Two groups, two prices: the cheaper one wins, and the line
        // says which one it was. A field on the header could not do that — the same
        // order can have a second line from the other group.
        $factory = $this->factory('10.0000', [
            new PriceRow('9.0000', 'EUR', 7, '1.0000'),
            new PriceRow('8.0000', 'EUR', 9, '1.0000'),
        ]);

        $factory->create(
            $this->draft('AT', groups: [7, 9]),
            [OrderLineDraft::product(3, '1')],
            1_757_000_000,
        );

        self::assertSame('8.0000', $this->writtenItems[0]['unitPrice']);
        self::assertSame(9, $this->writtenItems[0]['customerGroup']);
        self::assertSame(PriceSource::CustomerGroup->value, $this->writtenItems[0]['priceSource']);
    }

    public function testEineSummeDieNichtZumCheckoutPasstSchreibtKeineBestellung(): void
    {
        // The belt next to the checksum's braces: the checksum proves that the
        // INPUT has not changed, this comparison that two ways of computing
        // over the same input give the same result. Two different questions —
        // and the second one used to go unanswered.
        $factory = $this->factory();

        $this->expectException(TotalDisagreesWithCheckout::class);

        try {
            $factory->create(
                $this->draft('AT', erwartet: '99.99'),
                [OrderLineDraft::product(1, '1')],
                1_757_000_000,
            );
        } finally {
            // And BEFORE the number range: an order number burnt on a
            // deviation cannot be got back.
            self::assertSame([], $this->writtenOrders);
            self::assertSame([], $this->writtenItems);
        }
    }

    public function testDieErwarteteSummeDarfFehlen(): void
    {
        // Empty means "no comparison" — for callers without a checkout
        // behind them (backend, migration, test). The purchase click always
        // fills it, and PurchaseHandlerTest asserts exactly that.
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertCount(1, $this->writtenOrders);
    }

    public function testEinListenpreisTraegtKeineKundengruppe(): void
    {
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(1, '1')], 1_757_000_000);

        // 0, not null: the column is unsigned and "no group" is not a
        // missing value, but the statement "applies to all".
        self::assertSame(0, $this->writtenItems[0]['customerGroup']);
    }

    public function testVersandIstEineZeileMitEigenemSteuersatz(): void
    {
        $factory = $this->factory();

        $factory->create(
            $this->draft('AT'),
            [
                OrderLineDraft::product(1, '1'),
                OrderLineDraft::charge(OrderLineType::Shipping, 'Paket', '5.0000', 2, 4),
            ],
            1_757_000_000,
        );

        $shipping = $this->writtenItems[1];

        self::assertSame(OrderLineType::Shipping->value, $shipping['lineType']);
        self::assertSame('Paket', $shipping['productName']);
        self::assertSame('5.0000', $shipping['unitPrice']);

        // Tax class 2 is at 10 % in this test — the proof that the
        // shipping line uses its OWN class and not that of the goods.
        self::assertSame('10.0000', $shipping['taxRate']);

        // No product, but an origin: the shipping method that produced it.
        self::assertSame(0, $shipping['product']);
        self::assertSame(4, $shipping['source']);
    }

    public function testJedeZeileTraegtIhreSteuerklasse(): void
    {
        // C-10: whoever switches the payment method distributes a new amount
        // over the goods' classes as at purchase. The rate alone is not enough
        // for that — two classes can have the same rate today.
        $factory = $this->factory();

        $factory->create(
            $this->draft('AT'),
            [
                OrderLineDraft::product(1, '1'),
                OrderLineDraft::charge(OrderLineType::Shipping, 'Paket', '5.0000', 2, 4),
                OrderLineDraft::charge(OrderLineType::PaymentDiscount, 'Vorkasse −1 %', '-0.1000', 1, 6),
            ],
            1_757_000_000,
        );

        self::assertSame(1, $this->writtenItems[0]['taxClass']);
        self::assertSame(2, $this->writtenItems[1]['taxClass']);
        self::assertSame(1, $this->writtenItems[2]['taxClass']);
    }

    public function testEinNachlassMindertDieSummen(): void
    {
        $factory = $this->factory();

        $factory->create(
            $this->draft('AT'),
            [
                OrderLineDraft::product(1, '1'),
                OrderLineDraft::charge(OrderLineType::PaymentDiscount, 'Vorkasse −1 %', '-0.1000', 1, 6),
            ],
            1_757_000_000,
        );

        // 10.00 − 0.10 net, both at 20 %.
        self::assertSame('9.90', $this->writtenOrders[0]['netTotal']);
        self::assertSame('1.98', $this->writtenOrders[0]['taxTotal']);
        self::assertSame('11.88', $this->writtenOrders[0]['grandTotal']);

        // The sign comes from the checkout and is NOT flipped once more
        // here — otherwise the discount would have become a surcharge
        // and the total would still have been right.
        self::assertSame('-0.1000', $this->writtenItems[1]['unitPrice']);
        self::assertSame('-0.0200', $this->writtenItems[1]['taxAmount']);
    }

    public function testEineZahlungsgebuehrWirdMitIhrerSteuerklasseGespeichert(): void
    {
        // E-1, Z3: the operator chose the fee's tax on the payment method,
        // the checkout split it into shares with a class. Until E-1
        // every fee was refused.
        $factory = $this->factory();

        $factory->create(
            $this->draft('AT'),
            [
                OrderLineDraft::product(1, '1'),
                OrderLineDraft::charge(OrderLineType::PaymentFee, 'Nachnahme', '3.0000', 2, 6),
            ],
            1_757_000_000,
        );

        $fee = $this->writtenItems[1];

        self::assertSame(OrderLineType::PaymentFee->value, $fee['lineType']);
        self::assertSame('Nachnahme', $fee['productName']);
        self::assertSame('3.0000', $fee['unitPrice']);

        // Class 2 is at 10 % here, the goods at 20 %: the fee carries
        // the class of its share, not that of the goods.
        self::assertSame('10.0000', $fee['taxRate']);
    }

    public function testDerKopfHaeltVersandZahlungAlterUndZustimmung(): void
    {
        $factory = $this->factory();

        $factory->create(
            new OrderDraft(
                shopId: 1,
                currency: 'EUR',
                billing: new Address(street: 'Hauptstraße 5', postal: '1100', city: 'Wien', country: 'AT'),
                shipping: new Address(street: 'Lagerweg 9', postal: '1110', city: 'Wien', country: 'AT'),
                shopCountry: 'AT',
                language: 'de',
                shippingMethod: 4,
                shippingMethodTitle: 'Paket',
                paymentMethod: 6,
                paymentMethodTitle: 'Vorkasse',
                paymentInstructions: '<p>Binnen 7 Tagen.</p>',
                minimumAge: 18,
                dateOfBirth: '1979-04-12',
                termsAcceptedAt: 1_757_000_000,
            ),
            [OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        $order = $this->writtenOrders[0];

        self::assertSame('de', $order['language']);
        self::assertSame(4, $order['shippingMethod']);
        self::assertSame('Paket', $order['shippingMethodTitle']);
        self::assertSame(6, $order['paymentMethod']);
        self::assertSame('Vorkasse', $order['paymentMethodTitle']);
        self::assertSame('<p>Binnen 7 Tagen.</p>', $order['paymentInstructions']);

        // The limit stands next to the date because it can change: 16
        // today does not prove that the order was lawful if 18 applied
        // back then.
        self::assertSame(18, $order['minimumAge']);
        self::assertSame('1979-04-12', $order['dateOfBirth']);
        self::assertSame(1_757_000_000, $order['termsAcceptedAt']);
        self::assertNull($order['digitalConsentAt'], 'F-3: nicht gefragt');
        self::assertSame(0, $order['businessCustomer']);
    }

    public function testEineZeileTraegtImmerIhreArt(): void
    {
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertSame(OrderLineType::Product->value, $this->writtenItems[0]['lineType']);
    }

    /**
     * The REAL ArticlePriceResolver, not a mock of its interface.
     *
     * The whole point of the C-7 change is that an order prices an article
     * through the same inheritance the storefront uses, and a mock would let
     * that regress without a word. Article 2 below deliberately has no price
     * row of its own — a variant that inherits its price from the main product.
     *
     * @param list<PriceRow> $extraRows rows for article 3, the customer-group case
     */
    private function factory(string $unitNet = '10.0000', array $extraRows = []): OrderFactory
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchAssociative')->willReturn(
            ['id' => 1, 'prefix' => 'WEB-', 'padding' => 5, 'current' => 0],
        );

        $prices = $this->createMock(PriceRepositoryInterface::class);
        $prices->method('findCandidates')->willReturnCallback(
            static fn (int $productId, string $currency, array $groups, int $timestamp = 0, int $rule = 0): array => match (true) {
                // Abo-2: the monthly subscription (rule 5) of main product 1.
                5 === $rule => 1 === $productId ? [new PriceRow('1.0000', 'EUR', null, '1.0000')] : [],
                0 !== $rule => [],
                default => match ($productId) {
                1 => [new PriceRow($unitNet, 'EUR', null, '1.0000')],

                // The variant itself has nothing. Inheritance has to find
                // article 1, its main product.
                2 => [],
                3 => $extraRows,
                // WG3: gift cards at 50.00.
                4, 5, 6, 7 => [new PriceRow('50.0000', 'EUR', null, '1.0000')],
                default => [],
                },
            },
        );

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturnCallback(
            // Class 9 is "not taxable" — the repository answers 0 everywhere (WG3).
            static fn (int $classId, string $country): string|null => 9 === $classId ? '0.0000' : match ($country) {
                'AT' => 2 === $classId ? '10.0000' : '20.0000',
                'DE' => '19.0000',
                default => null,
            },
        );

        $products = $this->createMock(OrderProductRepositoryInterface::class);
        $products->method('snapshot')->willReturnCallback(
            static fn (int $id): array|null => match ($id) {
                // Block GARAN: with the manufacturer's durability guarantee.
                1 => ['name' => 'Schwedenbitter', 'sku' => 'SB-250', 'taxClass' => 1, 'mainProduct' => 1, 'kind' => 'physical', 'durabilityYears' => '5', 'durabilityLabel' => str_repeat("\x07", 16)],
                2 => ['name' => 'Schwedenbitter', 'sku' => 'SB-250-KL', 'taxClass' => 1, 'mainProduct' => 1, 'kind' => 'service'],
                3 => ['name' => 'Kräutersalz', 'sku' => 'KS-200', 'taxClass' => 1, 'mainProduct' => 3, 'kind' => 'physical'],
                4 => ['name' => 'Gutschein 50 €', 'sku' => 'GS-50', 'taxClass' => 9, 'mainProduct' => 4, 'kind' => 'voucher', 'voucherValue' => '50.0000'],
                // Set up wrongly: a gift card with a taxable class.
                5 => ['name' => 'Gutschein 50 € (falsch)', 'sku' => 'GS-50X', 'taxClass' => 1, 'mainProduct' => 5, 'kind' => 'voucher'],
                // EZ-1: a single-purpose voucher; 7 set up wrongly in the class "not taxable".
                6 => ['name' => 'Wandergutschein', 'sku' => 'WG-50', 'taxClass' => 1, 'mainProduct' => 6, 'kind' => 'voucher', 'singlePurpose' => true, 'voucherValue' => '50.0000'],
                7 => ['name' => 'Wandergutschein (falsch)', 'sku' => 'WG-50X', 'taxClass' => 9, 'mainProduct' => 7, 'kind' => 'voucher', 'singlePurpose' => true],
                default => null,
            },
        );

        $writer = $this->createMock(OrderWriterInterface::class);
        $writer->method('createOrder')->willReturnCallback(
            function (array $values): int {
                $this->writtenOrders[] = $values;

                return 1;
            },
        );
        $writer->method('createItem')->willReturnCallback(
            function (array $values): void {
                $this->writtenItems[] = $values;
            },
        );

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE']);

        $taxes = new TaxResolver($rates, new CountryProvider($countries));

        $taxClasses = $this->createMock(ArticleTaxClassesInterface::class);
        // The gift card (4) in the class "not taxable" (WG3).
        $taxClasses->method('taxClassOf')->willReturnCallback(static fn (int $articleId): int => \in_array($articleId, [4, 7], true) ? 9 : 1);

        $resolver = new ArticlePriceResolver(
            new PriceCalculator($prices),
            $prices,
            new BasePriceCalculator(),
            new GrossCalculator($taxes),
            $taxClasses,
        );

        // The sentence itself is checked in SubscriptionTermsTest; what counts
        // here is which rule, which amount and whether net goes in.
        $lineTerms = $this->createMock(SubscriptionLineTerms::class);
        $lineTerms->method('of')->willReturnCallback(
            static fn (int $rule, string $amount): string|null => $rule > 0 ? 'Bedingungen '.$rule.' zu '.$amount : null,
        );

        return new OrderFactory(
            new NumberRangeAllocator($connection),
            $resolver,
            $taxes,
            $products,
            $writer,
            $lineTerms,
        );
    }

    /**
     * Abo-4, P25 (A9): the follow-up line of a subscription carries what was
     * frozen at purchase. Article 99 exists neither in the catalogue nor in
     * the price table — through product() the line would fail. Only the tax
     * rate is determined anew.
     */
    public function testEineEingefroreneZeileFragtDenKatalogNicht(): void
    {
        $this->factory()->create($this->draft('AT'), [$this->frozenLine(taxClass: 1)], 1_757_000_000);

        $item = $this->writtenItems[0];

        self::assertSame([99, 'E-Book', 'EB-001', '2.0000', '1.0000', 1], [$item['product'], $item['productName'], $item['sku'], $item['quantity'], $item['unitPrice'], $item['taxClass']]);
        self::assertSame(['20.0000', '0.4000', '2.4000'], [$item['taxRate'], $item['taxAmount'], $item['lineTotal']]);
        // Frozen, not rebuilt: SubscriptionLineTerms would say "Bedingungen 1 zu …",
        // the catalogue does not know article 99.
        self::assertSame([1, 'Bedingungen beim Kauf', 'digital'], [$item['subscriptionRule'], $item['subscriptionTerms'], $item['kind']]);
        self::assertSame([OrderLineType::Product->value, PriceSource::List->value], [$item['lineType'], $item['priceSource']]);
    }

    /**
     * DL4, Spec 6.5: follow-up order of a service subscription after a move —
     * delivery country DE, the line stays in the shop's country (AT, 20 %).
     */
    public function testDieFolgezeileEinerDienstleistungBleibtImLandDesShops(): void
    {
        $line = OrderLineDraft::frozen(99, '2', '1.0000', 1, 1, 'Bedingungen beim Kauf', 'Mitgliedschaft', 'MS-1', 'service');

        $this->factory()->create($this->draft('DE'), [$line], 1_757_000_000);

        self::assertSame(['AT', '20.0000', 'service'], [$this->writtenItems[0]['taxCountry'], $this->writtenItems[0]['taxRate'], $this->writtenItems[0]['kind']]);
    }

    public function testDerSteuersatzDerFolgezeileFolgtDemGeltendenRecht(): void
    {
        // Class 2 has 10 % in AT: the rate comes from the class at the time
        // of the follow-up order, not from the first order.
        $this->factory()->create($this->draft('AT'), [$this->frozenLine(taxClass: 2)], 1_757_000_000);

        self::assertSame(['10.0000', '0.2000', '2.2000'], [$this->writtenItems[0]['taxRate'], $this->writtenItems[0]['taxAmount'], $this->writtenItems[0]['lineTotal']]);
    }

    /**
     * P26: reverse charge by the frozen flag, without a new VAT ID check.
     * Control case: without the flag and without a check → 19 % DE.
     */
    public function testReverseChargeFolgtDemEingefrorenenKennzeichen(): void
    {
        $this->factory()->create($this->followUp('DE', reverseCharge: true), [$this->frozenLine(taxClass: 1)], 1_757_000_000);
        $this->factory()->create($this->followUp('DE', reverseCharge: false, taxAtDestination: true), [$this->frozenLine(taxClass: 1)], 1_757_000_000);

        self::assertSame(['0.0000', PriceSource::ReverseCharge->value], [$this->writtenItems[0]['taxRate'], $this->writtenItems[0]['priceSource']]);
        self::assertSame(['19.0000', PriceSource::List->value], [$this->writtenItems[1]['taxRate'], $this->writtenItems[1]['priceSource']]);
    }

    public function testDieFolgebestellungZeigtAufIhrAbo(): void
    {
        $this->factory()->create($this->followUp('AT', subscriptionId: 7), [$this->frozenLine(taxClass: 1)], 1_757_000_000);
        $this->factory()->create($this->draft('AT'), [OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertSame(7, $this->writtenOrders[0]['subscription']);
        self::assertSame([1_793_288_184, 1_795_966_584], [$this->writtenOrders[0]['servicePeriodFrom'], $this->writtenOrders[0]['servicePeriodUntil']]);
        // A purchase is not a follow-up order.
        self::assertSame(0, $this->writtenOrders[1]['subscription']);
        self::assertSame([null, null], [$this->writtenOrders[1]['servicePeriodFrom'], $this->writtenOrders[1]['servicePeriodUntil']]);
    }

    public function testEineEingefroreneZeileBrauchtMengeUndEinenBetrag(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrderLineDraft::frozen(99, '0', '1.0000', 1, 1, '', 'E-Book', 'EB-001', 'digital');
    }

    public function testEinNegativerBetragIstKeineFolgezeile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrderLineDraft::frozen(99, '1', '-1.0000', 1, 1, '', 'E-Book', 'EB-001', 'digital');
    }

    private function frozenLine(int $taxClass): OrderLineDraft
    {
        return OrderLineDraft::frozen(99, '2', '1.0000', $taxClass, 1, 'Bedingungen beim Kauf', 'E-Book', 'EB-001', 'digital');
    }

    private function followUp(string $deliveryCountry, bool $reverseCharge = false, int $subscriptionId = 0, bool $taxAtDestination = false): OrderDraft
    {
        return new OrderDraft(
            shopId: 1,
            currency: 'EUR',
            billing: new Address(firstname: 'Eva', lastname: 'Example', street: 'Hauptstraße 5', postal: '1100', city: 'Wien', country: 'AT', email: 'kunde@example.org'),
            shipping: new Address(street: 'Lagerweg 9', postal: '1110', city: 'Wien', country: $deliveryCountry),
            shopCountry: 'AT',
            shopVatId: 'ATU12345678',
            subscriptionId: $subscriptionId,
            frozenReverseCharge: $reverseCharge,
            servicePeriodFrom: $subscriptionId > 0 ? 1_793_288_184 : null,
            servicePeriodUntil: $subscriptionId > 0 ? 1_795_966_584 : null,
            taxAtDestination: $taxAtDestination,
        );
    }

    public function testWithoutAHomeCountryNoSaleCountsAsCrossBorder(): void
    {
        // The safe direction: without a home country no border can be
        // determined — so tax is charged, never delivered silently tax-free.
        $factory = $this->factory();

        $factory->create(
            $this->draft('DE', $this->vatCheck(VatIdStatus::Valid), shopCountry: ''),
            [OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        self::assertSame('19.0000', $this->writtenItems[0]['taxRate']);
    }

    /**
     * @param list<int> $groups
     */
    private function draft(string $deliveryCountry, VatIdCheckResult|null $vatCheck = null, string $shopCountry = 'AT', array $groups = [], string $erwartet = '', string $shopVatId = 'ATU12345678', bool $taxAtDestination = false): OrderDraft
    {
        return new OrderDraft(
            shopId: 1,
            currency: 'EUR',
            billing: new Address(
                firstname: 'Eva',
                lastname: 'Example',
                street: 'Hauptstraße 5',
                postal: '1100',
                city: 'Wien',
                country: 'AT',
                email: 'kunde@example.org',
            ),
            shipping: new Address(street: 'Lagerweg 9', postal: '1110', city: 'Wien', country: $deliveryCountry),
            shopCountry: $shopCountry,
            customerGroupIds: $groups,
            vatCheck: $vatCheck,
            customerVatId: null !== $vatCheck ? 'DE123456789' : '',
            expectedGrandTotal: $erwartet,
            shopVatId: $shopVatId,
            taxAtDestination: $taxAtDestination,
        );
    }

    private function vatCheck(VatIdStatus $status): VatIdCheckResult
    {
        return new VatIdCheckResult($status, 1_757_000_000, 'Musterfirma GmbH', 'WAPI-123');
    }
}
