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
     * F-1, F13: Ob eine Zeile körperlich ist, wird beim Kauf eingefroren —
     * Widerruf und Lieferung hängen daran, der Produkttyp kann sich ändern.
     * Eine Kostenzeile ist keine Ware.
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
     * Abo-2, P13: Die Zeile trägt ihr Modell, ihre Bedingungen und ihre
     * Art (DL1) — eingefroren, wie Name und Preis. Artikel 2 ist eine Dienstleistung,
     * sein Monatsabo (Regel 5) kostet 1,00 netto.
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
        // Brutto: 1,00 × 2 = 2,00 netto + 20 %.
        self::assertSame(['Bedingungen 5 zu 2.4000', '', ''], array_column($this->writtenItems, 'subscriptionTerms'));
        self::assertSame(['service', 'physical', ''], array_column($this->writtenItems, 'kind'));
    }

    public function testDieVerlaengerungStehtNurAnDerAboZeile(): void
    {
        // Abo-7b (P69): die Zeile nennt das verlängerte Abo; eine
        // Einmalkauf-Zeile mit derselben Angabe trüge sie nicht weiter.
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

        // Die Summen tragen die Stellen der Währung, der Satz nicht: ein
        // Steuersatz ist kein Geldbetrag und bleibt vierstellig.
        self::assertSame('20.00', $this->writtenOrders[0]['netTotal']);
        self::assertSame('4.00', $this->writtenOrders[0]['taxTotal']);
        self::assertSame('24.00', $this->writtenOrders[0]['grandTotal']);
        self::assertSame('20.0000', $this->writtenItems[0]['taxRate']);
    }

    public function testTheTotalsAddUpTheLinesAsAnInvoiceShowsThem(): void
    {
        // Derselbe Befund wie im Warenkorb (920f74e), an der Stelle, an der er
        // schwerer wiegt: 8.3367 netto, 20 %, zwei Zeilen à Menge 1.
        //
        //   Zeilennetto  8.3367 → angezeigt 8,34, zweimal   = 16,68
        //   Zeilenbrutto 10.0040 → angezeigt 10,00, zweimal = 20,00
        //   roh summiert 16.6734 bzw. 20.0080 → 16,67 / 20,01
        //
        // Beide Spalten ergäben also nicht die Zahl unter sich.
        $factory = $this->factory('8.3367');

        $factory->create(
            $this->draft('AT'),
            [OrderLineDraft::product(1, '1'), OrderLineDraft::product(1, '1')],
            1_757_000_000,
        );

        // Die Zeilenfelder bleiben vierstellig: Netto ist der Anker, die
        // Steuer entsteht weiterhin aus dem ungerundeten Zeilennetto, und
        // DocumentPayloadBuilder rechnet netto = lineTotal − taxAmount.
        self::assertSame('1.6673', $this->writtenItems[0]['taxAmount']);
        self::assertSame('10.0040', $this->writtenItems[0]['lineTotal']);

        self::assertSame('16.68', $this->writtenOrders[0]['netTotal']);
        self::assertSame('20.00', $this->writtenOrders[0]['grandTotal']);

        // Die Steuer ist seit 2026-09-23 die Differenz, keine eigene Summe —
        // sonst widerspräche die Bestellung wieder der Seite, von der sie
        // kommt. 3,32 statt 3,34: der Preis dafür, dass die Brutto-Spalte
        // aufgeht.
        self::assertSame('3.32', $this->writtenOrders[0]['taxTotal']);
    }

    /**
     * 🔴 Diese Zusicherung stand bis zum 2026-09-23 auf dem Kopf.
     *
     * Sie hielt fest, dass die Brutto-Spalte je Zeile NICHT aufgeht — und
     * genau diese Spalte ist die, die der Checkout summiert. Damit zementierte
     * sie eine Bestellsumme, die von dem abweicht, was der Kunde über dem
     * Kaufknopf gelesen und mit § 8 FAGG bestätigt hat. Gemessen: bei 16,7 %
     * aller Bruttopreise zwischen 1,00 und 99,99 zu 20 %, schon bei 1,05 €.
     *
     * Gefunden im Fable-Review zu C-7 (F1). Ein handwerklich einwandfreier
     * Test, der einen Fehler festschreibt, verteidigt ihn von da an — Punkt 26
     * der Vault-Notiz „Prüfungen, die nicht prüfen was man glaubt".
     */
    public function testDieBestellsummeIstDieAufDieDerKundeGeklicktHat(): void
    {
        // Zwei Zeilen à 8.3367 netto, 20 %: je Zeile 10.0040, angezeigt 10,00.
        // Der Kunde addiert 10,00 + 10,00 und erwartet 20,00 — der Warenkorb
        // zeigt 20,00 (CartPricerTest), und die Bestellung muss dasselbe
        // sagen. Netto und Steuer getrennt gerundet ergäben 20,02.
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

        // Und der Preis dafür, offen ausgewiesen: netto + steuer ergibt hier
        // nicht grandTotal. Drei Spalten, zwei Rundungen — eine kann nicht
        // aufgehen, und das ist ab jetzt diese. Die Differenz ist auf einen
        // Cent JE STEUERSATZ begrenzt; die Brutto-Variante wuchs mit jeder
        // Zeile.
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
     * Block R (O1, R2, R4): ohne „Steuer im Bestimmungsland" der Satz des
     * Shops — auch für den Versand, der der Ware folgt —, und jede Zeile hält
     * fest, wohin geliefert wurde.
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
     * Block GARAN (GA6): Dauer und Label stehen an der Position, wie sie vor
     * dem Knopf standen; ohne Garantie 0 und NULL.
     */
    public function testDieGarantieStehtAnDerPosition(): void
    {
        $this->factory()->create($this->draft('AT'), [OrderLineDraft::product(1, '1'), OrderLineDraft::product(4, '1')], 1_757_000_000);

        self::assertSame(['5', '0'], array_column($this->writtenItems, 'durabilityYears'));
        self::assertSame([str_repeat("\x07", 16), null], array_column($this->writtenItems, 'durabilityLabel'));
    }

    /**
     * WG3: Der Gutschein mit Klasse „nicht steuerbar" ist keine Zeile zu 0 %
     * eines Landes, sondern nicht steuerbar — ohne Land. Neben einer Ware
     * zum normalen Satz.
     */
    public function testDerGutscheinIstNichtSteuerbarUndOhneLand(): void
    {
        $this->factory()->create($this->draft('DE', taxAtDestination: true), [OrderLineDraft::product(4, '1'), OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertSame(['0.0000', '0.0000', '', 'notTaxable', '50.0000'], [
            $this->writtenItems[0]['taxRate'], $this->writtenItems[0]['taxAmount'], $this->writtenItems[0]['taxCountry'],
            $this->writtenItems[0]['priceSource'], $this->writtenItems[0]['lineTotal'],
        ]);
        self::assertSame(['19.0000', 'DE', 'list'], [$this->writtenItems[1]['taxRate'], $this->writtenItems[1]['taxCountry'], $this->writtenItems[1]['priceSource']]);

        // WG-4: der Wert des Gutscheins eingefroren wie sein Preis; an der Ware 0.
        self::assertSame(['50.0000', '0.0000'], array_column($this->writtenItems, 'voucherValue'));
    }

    /**
     * Auch unter Reverse Charge: Der Verkauf eines Mehrzweck-Gutscheins ist
     * keine Leistung, deren Steuer übergehen könnte — „nicht steuerbar"
     * geht vor.
     */
    public function testNichtSteuerbarGehtReverseChargeVor(): void
    {
        $this->factory()->create($this->draft('DE', $this->vatCheck(VatIdStatus::Valid)), [OrderLineDraft::product(4, '1'), OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertSame(['notTaxable', 'reverseCharge'], array_column($this->writtenItems, 'priceSource'));
    }

    /**
     * Ein Gutschein mit steuerbarer Klasse wird verkauft, wie eingerichtet —
     * besteuert; den Fehler meldet der Befund, nicht die Bestellung.
     */
    public function testEinGutscheinMitSteuerbarerKlasseBleibtBesteuert(): void
    {
        $this->factory()->create($this->draft('DE', taxAtDestination: true), [OrderLineDraft::product(5, '1')], 1_757_000_000);

        self::assertSame(['19.0000', 'DE', 'list'], [$this->writtenItems[0]['taxRate'], $this->writtenItems[0]['taxCountry'], $this->writtenItems[0]['priceSource']]);
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
     * C-12 (Michael, 2026-09-26: „1a nur mit eigener UID"): Ohne UID des
     * Shops keine steuerfreie Lieferung — Art. 11 Abs. 2 UStG 1994 Anhang
     * (Binnenmarkt) verlangt sie auf der Rechnung.
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
        // Der Fall, an dem der Kaufklick bis C-7 gescheitert wäre: Artikel 2
        // hat keine eigene Preiszeile und erbt sie vom Hauptprodukt — in
        // Liste und Detailseite seit jeher, beim Bestellen erst jetzt. Auf c5
        // ist das „Schwedenbitter 250 ml".
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(2, '1')], 1_757_000_000);

        self::assertSame('10.0000', $this->writtenItems[0]['unitPrice']);

        // Die Artikelnummer bleibt die der VARIANTE — sie wird nie vererbt,
        // und sie ist das Einzige, was zwei Varianten eines Produkts im
        // Warenkorb wie auf der Rechnung unterscheidet.
        self::assertSame('SB-250-KL', $this->writtenItems[0]['sku']);
    }

    public function testDieGewinnendeKundengruppeStehtAnDerZeileNichtAmKopf(): void
    {
        // Zwei Gruppen, zwei Preise: die günstigere gewinnt, und die Zeile
        // sagt, welche es war. Ein Feld am Kopf könnte das nicht — dieselbe
        // Bestellung kann eine zweite Zeile aus der anderen Gruppe haben.
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
        // Der Gürtel zur Prüfsumme: Die Prüfsumme belegt, dass sich die
        // EINGABE nicht geändert hat, dieser Vergleich, dass zwei Rechenwege
        // über dieselbe Eingabe dasselbe ergeben. Zwei verschiedene Fragen —
        // und die zweite war bis zum Review vom 2026-09-23 unbeantwortet.
        $factory = $this->factory();

        $this->expectException(TotalDisagreesWithCheckout::class);

        try {
            $factory->create(
                $this->draft('AT', erwartet: '99.99'),
                [OrderLineDraft::product(1, '1')],
                1_757_000_000,
            );
        } finally {
            // Und zwar VOR dem Nummernkreis: eine Bestellnummer, die an einer
            // Abweichung verbrennt, ist nicht wiederzubekommen.
            self::assertSame([], $this->writtenOrders);
            self::assertSame([], $this->writtenItems);
        }
    }

    public function testDieErwarteteSummeDarfFehlen(): void
    {
        // Leer heißt „kein Vergleich" — für Aufrufer, die keine Kasse hinter
        // sich haben (Backend, Migration, Test). Der Kaufklick füllt sie
        // immer, und PurchaseHandlerTest sichert genau das zu.
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(1, '1')], 1_757_000_000);

        self::assertCount(1, $this->writtenOrders);
    }

    public function testEinListenpreisTraegtKeineKundengruppe(): void
    {
        $factory = $this->factory();

        $factory->create($this->draft('AT'), [OrderLineDraft::product(1, '1')], 1_757_000_000);

        // 0, nicht null: die Spalte ist unsigned und „keine Gruppe" ist kein
        // fehlender Wert, sondern die Aussage „gilt für alle".
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

        // Steuerklasse 2 steht in diesem Test auf 10 % — der Beleg dafür, dass
        // die Versandzeile ihre EIGENE Klasse benutzt und nicht die der Ware.
        self::assertSame('10.0000', $shipping['taxRate']);

        // Kein Produkt, aber eine Herkunft: die Versandart, die sie erzeugt hat.
        self::assertSame(0, $shipping['product']);
        self::assertSame(4, $shipping['source']);
    }

    public function testJedeZeileTraegtIhreSteuerklasse(): void
    {
        // C-10: Wer die Zahlungsart wechselt, verteilt einen neuen Betrag wie
        // beim Kauf über die Klassen der Ware. Der Satz allein reicht dafür
        // nicht — zwei Klassen können heute denselben Satz haben.
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

        // 10.00 − 0.10 netto, beide zu 20 %.
        self::assertSame('9.90', $this->writtenOrders[0]['netTotal']);
        self::assertSame('1.98', $this->writtenOrders[0]['taxTotal']);
        self::assertSame('11.88', $this->writtenOrders[0]['grandTotal']);

        // Das Vorzeichen kommt aus dem Checkout und wird hier NICHT noch
        // einmal gedreht — sonst wäre aus dem Nachlass ein Aufschlag geworden
        // und die Summe hätte trotzdem gestimmt.
        self::assertSame('-0.1000', $this->writtenItems[1]['unitPrice']);
        self::assertSame('-0.0200', $this->writtenItems[1]['taxAmount']);
    }

    public function testEineZahlungsgebuehrWirdMitIhrerSteuerklasseGespeichert(): void
    {
        // E-1, Z3: Die Steuer der Gebühr hat der Betreiber an der Zahlungsart
        // gewählt, der Checkout hat sie in Anteile mit Klasse zerlegt. Bis E-1
        // wurde jede Gebühr abgelehnt (Entscheidung 2026-09-23).
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

        // Klasse 2 steht hier auf 10 %, die Ware auf 20 %: Die Gebühr trägt
        // die Klasse ihres Anteils, nicht die der Ware.
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

        // Die Grenze steht neben dem Datum, weil sie sich ändern kann: 16
        // heute belegt nicht, dass die Bestellung rechtmäßig war, wenn damals
        // 18 galt.
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
     * row of its own — it is the "Schwedenbitter 250 ml" case from c5.
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
                // Abo-2: das Monatsabo (Regel 5) des Hauptprodukts 1.
                5 === $rule => 1 === $productId ? [new PriceRow('1.0000', 'EUR', null, '1.0000')] : [],
                0 !== $rule => [],
                default => match ($productId) {
                1 => [new PriceRow($unitNet, 'EUR', null, '1.0000')],

                // The variant itself has nothing. Inheritance has to find
                // article 1, its main product.
                2 => [],
                3 => $extraRows,
                // WG3: Wertgutscheine zu 50,00.
                4, 5 => [new PriceRow('50.0000', 'EUR', null, '1.0000')],
                default => [],
                },
            },
        );

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturnCallback(
            // Klasse 9 ist „nicht steuerbar" — das Repository antwortet überall 0 (WG3).
            static fn (int $classId, string $country): string|null => 9 === $classId ? '0.0000' : match ($country) {
                'AT' => 2 === $classId ? '10.0000' : '20.0000',
                'DE' => '19.0000',
                default => null,
            },
        );

        $products = $this->createMock(OrderProductRepositoryInterface::class);
        $products->method('snapshot')->willReturnCallback(
            static fn (int $id): array|null => match ($id) {
                // Block GARAN: mit Haltbarkeitsgarantie des Herstellers.
                1 => ['name' => 'Schwedenbitter', 'sku' => 'SB-250', 'taxClass' => 1, 'mainProduct' => 1, 'kind' => 'physical', 'durabilityYears' => '5', 'durabilityLabel' => str_repeat("\x07", 16)],
                2 => ['name' => 'Schwedenbitter', 'sku' => 'SB-250-KL', 'taxClass' => 1, 'mainProduct' => 1, 'kind' => 'service'],
                3 => ['name' => 'Kräutersalz', 'sku' => 'KS-200', 'taxClass' => 1, 'mainProduct' => 3, 'kind' => 'physical'],
                4 => ['name' => 'Gutschein 50 €', 'sku' => 'GS-50', 'taxClass' => 9, 'mainProduct' => 4, 'kind' => 'voucher', 'voucherValue' => '50.0000'],
                // Falsch eingerichtet: ein Gutschein mit steuerbarer Klasse.
                5 => ['name' => 'Gutschein 50 € (falsch)', 'sku' => 'GS-50X', 'taxClass' => 1, 'mainProduct' => 5, 'kind' => 'voucher'],
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
        // Der Gutschein (4) in der Klasse „nicht steuerbar" (WG3).
        $taxClasses->method('taxClassOf')->willReturnCallback(static fn (int $articleId): int => 4 === $articleId ? 9 : 1);

        $resolver = new ArticlePriceResolver(
            new PriceCalculator($prices),
            $prices,
            new BasePriceCalculator(),
            new GrossCalculator($taxes),
            $taxClasses,
        );

        // Der Satz selbst ist in SubscriptionTermsTest geprüft; hier zählt,
        // welche Regel, welcher Betrag und ob netto hineingehen.
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
     * Abo-4, P25 (A9): Die Folgezeile eines Abos trägt, was beim Kauf
     * eingefroren wurde. Artikel 99 gibt es weder im Katalog noch in der
     * Preistabelle — über product() würde die Zeile scheitern. Nur der
     * Steuersatz wird neu bestimmt.
     */
    public function testEineEingefroreneZeileFragtDenKatalogNicht(): void
    {
        $this->factory()->create($this->draft('AT'), [$this->frozenLine(taxClass: 1)], 1_757_000_000);

        $item = $this->writtenItems[0];

        self::assertSame([99, 'E-Book', 'EB-001', '2.0000', '1.0000', 1], [$item['product'], $item['productName'], $item['sku'], $item['quantity'], $item['unitPrice'], $item['taxClass']]);
        self::assertSame(['20.0000', '0.4000', '2.4000'], [$item['taxRate'], $item['taxAmount'], $item['lineTotal']]);
        // Eingefroren, nicht neu gebaut: SubscriptionLineTerms sagte „Bedingungen 1 zu …",
        // der Katalog kennt Artikel 99 nicht.
        self::assertSame([1, 'Bedingungen beim Kauf', 'digital'], [$item['subscriptionRule'], $item['subscriptionTerms'], $item['kind']]);
        self::assertSame([OrderLineType::Product->value, PriceSource::List->value], [$item['lineType'], $item['priceSource']]);
    }

    /**
     * DL4, Spec 6.5: Folgebestellung eines Dienstleistungs-Abos nach dem
     * Umzug — Lieferland DE, die Zeile bleibt im Land des Shops (AT, 20 %).
     */
    public function testDieFolgezeileEinerDienstleistungBleibtImLandDesShops(): void
    {
        $line = OrderLineDraft::frozen(99, '2', '1.0000', 1, 1, 'Bedingungen beim Kauf', 'Mitgliedschaft', 'MS-1', 'service');

        $this->factory()->create($this->draft('DE'), [$line], 1_757_000_000);

        self::assertSame(['AT', '20.0000', 'service'], [$this->writtenItems[0]['taxCountry'], $this->writtenItems[0]['taxRate'], $this->writtenItems[0]['kind']]);
    }

    public function testDerSteuersatzDerFolgezeileFolgtDemGeltendenRecht(): void
    {
        // Klasse 2 hat in AT 10 %: der Satz kommt aus der Klasse zum Zeitpunkt
        // der Folgebestellung, nicht aus der ersten Bestellung.
        $this->factory()->create($this->draft('AT'), [$this->frozenLine(taxClass: 2)], 1_757_000_000);

        self::assertSame(['10.0000', '0.2000', '2.2000'], [$this->writtenItems[0]['taxRate'], $this->writtenItems[0]['taxAmount'], $this->writtenItems[0]['lineTotal']]);
    }

    /**
     * P26: Reverse Charge nach dem eingefrorenen Kennzeichen, ohne neue
     * UID-Prüfung. Gegenprobe: ohne Kennzeichen und ohne Prüfung → 19 % DE.
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
        // Ein Kauf ist keine Folgebestellung.
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
            billing: new Address(firstname: 'Michael', lastname: 'Morgenbesser', street: 'Hauptstraße 5', postal: '1100', city: 'Wien', country: 'AT', email: 'kunde@example.org'),
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
        // Die sichere Richtung: Ohne Heimatland ist keine Grenze bestimmbar —
        // also wird versteuert, nie still steuerfrei geliefert.
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
                firstname: 'Michael',
                lastname: 'Morgenbesser',
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
