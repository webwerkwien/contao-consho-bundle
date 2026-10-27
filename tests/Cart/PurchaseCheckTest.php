<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\PurchaseCheck;
use Webwerkwien\ContaoConshoBundle\Cart\PurchaseVerdict;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Against SQLite with the shared catalogue test schema — visibility is
 * decided by VisibilityCriteria itself, not by a mock. That is the whole point:
 * the rules live in one place, and the cart is their fifth reader.
 *
 * The trait's inserters return no id (`insertProduct(): void`),
 * which is why the ids are constants here. Assigning to a shop goes
 * through `insertProductShop()` — there is no `assignToShop()` method.
 *
 * `check(..., '', '')` means "do not ask about translations" (see
 * PurchaseCheck::check()) and is only right where no page is involved
 * — most tests here check rules 1–3, for which that holds. The
 * language tests further down pass real language pairs.
 */
class PurchaseCheckTest extends TestCase
{
    use CatalogSchema;

    // Ten digits like a real timestamp — start/stop are varchar(10),
    // see VisibilityCriteria::published().
    private const NOW = 1_700_000_000;

    private const SHOP = 1;
    private const MAIN = 1;
    private const VARIANT = 2;

    private PurchaseCheck $check;

    protected function setUp(): void
    {
        $this->createCatalogSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->check = new PurchaseCheck($this->catalogConnection, new VisibilityCriteria());
    }

    public function testEinSichtbaresProduktOhneVariantenDarfGekauftWerden(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        $verdict = $this->check->check(self::MAIN, self::SHOP, '2', self::NOW, '', '');

        self::assertTrue($verdict->allowed);
        self::assertSame('2.0000', $verdict->quantity);
        self::assertSame(self::MAIN, $verdict->mainProductId);
        self::assertNull($verdict->reason);
    }

    public function testEinUnveroeffentlichtesProduktWirdAbgelehnt(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 0]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(PurchaseVerdict::UNAVAILABLE, $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testEinProduktEinesAnderenShopsWirdAbgelehnt(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => 2]);

        self::assertSame(PurchaseVerdict::UNAVAILABLE, $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testEinHauptproduktMitVariantenWirdAbgelehnt(): void
    {
        // It is not goods but the choice before them (VisibilityCriteria).
        $this->insertProduct(['id' => self::MAIN, 'published' => 1]);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(PurchaseVerdict::NEEDS_CHOICE, $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testEineSichtbareVarianteDarfGekauftWerden(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        $verdict = $this->check->check(self::VARIANT, self::SHOP, '1', self::NOW, '', '');

        self::assertTrue($verdict->allowed);
        // Minimum quantity and step size sit on the main product.
        self::assertSame(self::MAIN, $verdict->mainProductId);
    }

    public function testEineVarianteEinesAbgeschaltetenHauptproduktsWirdAbgelehnt(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 0]);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(PurchaseVerdict::UNAVAILABLE, $this->check->check(self::VARIANT, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testDieMindestmengeDerVarianteKommtVomHauptprodukt(): void
    {
        // The variant carries the column's defaults (1/1); what counts
        // is the 5 on the main product — otherwise the wrong row would stand out.
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '5.0000', 'purchaseSteps' => '3.0000']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame('8.0000', $this->check->check(self::VARIANT, self::SHOP, '6', self::NOW, '', '')->quantity);
    }

    public function testEineUngueltigeMengeWirdAbgelehnt(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '5.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(PurchaseVerdict::INVALID_QUANTITY, $this->check->check(self::MAIN, self::SHOP, '2', self::NOW, '', '')->reason);
    }

    /**
     * Spec F, open points (K6): 1.5 licence keys rounded down to
     * one. Digital goods and gift vouchers come only whole; a
     * service may be 1.5 hours, goods 1.5 kg.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function kinds(): iterable
    {
        yield 'digital' => ['digital', false];
        yield 'Wertgutschein' => ['voucher', false];
        yield 'Dienstleistung' => ['service', true];
        yield 'körperlich' => ['physical', true];
    }

    /** @dataProvider kinds */
    public function testNurGanzeMengenBeiDigitalemUndGutscheinen(string $kind, bool $fractionAllowed): void
    {
        $this->catalogConnection->insert('tl_consho_product_type', ['id' => 4, 'kind' => $kind]);
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'productType' => 4, 'minPurchase' => '0.5000', 'purchaseSteps' => '0.5000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertTrue($this->check->check(self::MAIN, self::SHOP, '2', self::NOW, '', '')->allowed, 'ganz geht immer');
        self::assertSame($fractionAllowed, $this->check->check(self::MAIN, self::SHOP, '1.5', self::NOW, '', '')->allowed);
    }

    public function testEineUnbekannteIdWirdAbgelehnt(): void
    {
        self::assertSame(PurchaseVerdict::UNAVAILABLE, $this->check->check(999, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testEinInDieserSpracheUnsichtbaresProduktDarfDortNichtGekauftWerden(): void
    {
        // The fifth visibility rule: safety texts on the product, but
        // no translation — on the French page the catalogue does not show
        // the product, so it must not go into the cart from there either.
        // Without the language the cart would check four of five
        // rules and accept what the page does not even offer.
        $this->insertProduct([
            'id' => self::MAIN,
            'published' => 1,
            'minPurchase' => '1.0000',
            'purchaseSteps' => '1.0000',
            'safetyWarnings' => 'Enthält Alkohol',
        ]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(
            PurchaseVerdict::UNAVAILABLE,
            $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, 'fr', 'de')->reason,
        );

        // In the source language the same article, the same line: allowed.
        self::assertTrue($this->check->check(self::MAIN, self::SHOP, '1', self::NOW, 'de', 'de')->allowed);
    }

    /**
     * Before the change to required parameters, a caller
     * that passed only $language and forgot $fallback silently got
     * $language !== $fallback ('de' !== '') — the main language then counted as a
     * foreign language, VisibilityCriteria required a translation row for
     * 'de', which never exists for the main language, and a perfectly normal
     * product without any safety text was wrongly refused. That was
     * reproducible with the old default `string $fallback = ''` (see the
     * git history of this test).
     *
     * Now both parameters are required: leaving one out is no longer a silent
     * runtime error but an immediate ArgumentCountError on the
     * call — an error at analysis time, not at the sale.
     */
    public function testFehlendeFallbackSpracheIstEinFehlerBeimAufruf(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        $this->expectException(\ArgumentCountError::class);

        /** @phpstan-ignore-next-line arguments.count (the missing argument is exactly the point of this test) */
        $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, 'de');
    }

    public function testMitUebersetztenSicherheitstextenDarfAuchDortGekauftWerden(): void
    {
        $this->insertProduct([
            'id' => self::MAIN,
            'published' => 1,
            'minPurchase' => '1.0000',
            'purchaseSteps' => '1.0000',
            'safetyWarnings' => 'Enthält Alkohol',
        ]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);
        $this->insertTranslation([
            'pid' => self::MAIN,
            'ptable' => 'tl_consho_product',
            'language' => 'fr',
            'title' => 'Amer suédois',
            // Without an alias the translation counts as incomplete — the
            // variant would have no address of its own in this language
            // (VisibilityCriteria::translated()).
            'alias' => 'amer-suedois',
            'safetyWarnings' => 'Contient de l’alcool',
        ]);

        self::assertTrue($this->check->check(self::MAIN, self::SHOP, '1', self::NOW, 'fr', 'de')->allowed);
    }

    public function testEinAngebotenesModellDarfInDenWarenkorb(): void
    {
        $this->visibleProduct();

        $check = new PurchaseCheck($this->catalogConnection, new VisibilityCriteria(), $this->offers([0, 5]));

        self::assertTrue($check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 5, [3])->allowed);
        self::assertTrue($check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 0, [])->allowed);
    }

    public function testEinNichtAngebotenesModellWirdAbgelehnt(): void
    {
        $this->visibleProduct();

        $check = new PurchaseCheck($this->catalogConnection, new VisibilityCriteria(), $this->offers([5]));

        // The product does not have rule 7, and it is not sold as a one-off purchase.
        self::assertSame(PurchaseVerdict::NOT_OFFERED, $check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 7)->reason);
        self::assertSame(PurchaseVerdict::NOT_OFFERED, $check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 0)->reason);
        self::assertSame(PurchaseVerdict::NOT_OFFERED, $check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', -1)->reason);
    }

    public function testDieGruppenGehenAnDieAngebote(): void
    {
        $this->visibleProduct();

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->expects(self::once())->method('allows')->with(self::MAIN, 5, [3, 4])->willReturn(true);

        (new PurchaseCheck($this->catalogConnection, new VisibilityCriteria(), $offers))
            ->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 5, [3, 4])
        ;
    }

    public function testOhneModellFragtDieMengenaenderungNichtNachDemAngebot(): void
    {
        // Changing the quantity of an existing line does not change its model.
        // If the check then asked about the one-off purchase, the quantity of
        // a subscription-only product could never be changed again. Whether the model still applies
        // is told by the cart through the price (CartPricer).
        $this->visibleProduct();

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->expects(self::never())->method('allows');

        self::assertTrue((new PurchaseCheck($this->catalogConnection, new VisibilityCriteria(), $offers))->check(self::MAIN, self::SHOP, '1', self::NOW, '', '')->allowed);
    }

    public function testJederAblehnungsgrundHatSeinenHinweis(): void
    {
        self::assertSame('not_offered', PurchaseVerdict::refuse(PurchaseVerdict::NOT_OFFERED)->notice()->value);
        self::assertSame('needs_choice', PurchaseVerdict::refuse(PurchaseVerdict::NEEDS_CHOICE)->notice()->value);
        self::assertSame('invalid_quantity', PurchaseVerdict::refuse(PurchaseVerdict::INVALID_QUANTITY)->notice()->value);
        self::assertSame('unavailable', PurchaseVerdict::refuse(PurchaseVerdict::UNAVAILABLE)->notice()->value);
    }

    /**
     * `refuse()` used to return `quantity = '0.0000'` and
     * `mainProductId = 0` — both look like real values. A caller
     * that reads them without checking `allowed` first would get no error
     * but a plausible-looking zero line in the cart. Both are
     * now `null`, so that a missing null check is a PHPStan error instead of
     * a silent trap.
     */
    public function testEineAblehnungHatWederMengeNochHauptproduktId(): void
    {
        $verdict = PurchaseVerdict::refuse(PurchaseVerdict::UNAVAILABLE);

        self::assertNull($verdict->quantity);
        self::assertNull($verdict->mainProductId);
    }

    private function visibleProduct(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);
    }

    /**
     * @param list<int> $offered the models the product offers to everyone
     */
    private function offers(array $offered): SubscriptionOffers
    {
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('allows')->willReturnCallback(
            static fn (int $article, int $rule): bool => \in_array($rule, $offered, true),
        );

        return $offers;
    }
}
