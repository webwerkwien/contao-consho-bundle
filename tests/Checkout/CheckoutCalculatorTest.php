<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Cart\CartItemRow;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CodeStatus;
use Webwerkwien\ContaoConshoBundle\Checkout\AgeGate;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutBlock;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutCalculator;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutFingerprint;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutProblem;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingTaxSplitter;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\CashOnDeliveryHandler;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustment;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\ProductConditions;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLimits;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLine;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethod;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypeInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingQuote;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

/**
 * Der Rechenschritt aus 7.2. Alles hier läuft ohne Datenbank — die beiden
 * Repositories sind seit C-5 Schnittstellen, eben dafür.
 */
class CheckoutCalculatorTest extends TestCase
{
    private const NOW = 1_790_000_000;

    public function testDieSechsStufenErgebenEinenVollstaendigenZustand(): void
    {
        $state = $this->calculate($this->vollstaendig());

        self::assertTrue($state->isReady());
        self::assertNull($state->blockedAt());
        self::assertSame('50.00', $state->cart->net);

        // 50,00 Ware + 5,00 Versand = 55,00 netto, 20 % → 66,00.
        self::assertSame('55.00', $state->netTotal);
        self::assertSame('66.00', $state->grossTotal);
        self::assertSame('EUR', $state->currency);
    }

    public function testEinLeererWarenkorbIstDasErsteProblem(): void
    {
        $state = $this->calculate($this->vollstaendig(), items: []);

        self::assertFalse($state->isReady());
        self::assertTrue($state->has(CheckoutProblem::BasketEmpty));
        self::assertFalse($state->needsShipping);
    }

    /**
     * Block G (G5): Reicht der Bestand nicht, hält die Kasse an — gefragt mit
     * den Zeilen des Korbs, Artikel und Menge.
     */
    public function testZuWenigBestandHaeltDenCheckoutAn(): void
    {
        $availability = $this->createMock(Availability::class);
        $availability->expects(self::once())->method('short')->with([[7, '2.0000']])->willReturn([7]);

        $state = $this->calculate($this->vollstaendig(), items: [new CartItemRow(1, 7, '2.0000', 1)], availability: $availability);

        self::assertTrue($state->has(CheckoutProblem::StockShort));
        self::assertTrue(CheckoutProblem::StockShort->blocks());
        self::assertFalse($state->isReady());
    }

    /**
     * G-5: „Lizenz verlängern" braucht keinen Code (Abo-7b) — die Zeile fragt
     * den Bestand nicht.
     */
    public function testEineVerlaengerungFragtDenBestandNicht(): void
    {
        $availability = $this->createMock(Availability::class);
        $availability->expects(self::once())->method('short')->with([[8, '2.0000']])->willReturn([]);

        $this->calculate($this->vollstaendig(), items: [
            new CartItemRow(1, 7, '1.0000', 1, 5, 31),
            new CartItemRow(2, 8, '2.0000', 1),
        ], availability: $availability);
    }

    public function testReichtDerBestandGehtEsWeiter(): void
    {
        $availability = $this->createStub(Availability::class);
        $availability->method('short')->willReturn([]);

        $state = $this->calculate($this->vollstaendig(), availability: $availability);

        self::assertFalse($state->has(CheckoutProblem::StockShort));
        self::assertTrue($state->isReady());
    }

    public function testEineUnbepreisteZeileHaeltDenCheckoutAn(): void
    {
        // CartView sagt es selbst: die Warenkorbseite bietet das Entfernen an,
        // der Checkout geht nicht weiter, solange eine dasteht.
        $state = $this->calculate($this->vollstaendig(), items: [
            new CartItemRow(1, 7, '1.0000', 1),
            new CartItemRow(2, 999, '1.0000', 1),
        ]);

        self::assertTrue($state->has(CheckoutProblem::UnpricedLine));
        self::assertFalse($state->isReady());
    }

    public function testEineUnvollstaendigeAdresseFuehrtZumAdressbaustein(): void
    {
        $state = $this->calculate((new CheckoutData())->withBilling(new Address(lastname: 'Muster')));

        self::assertTrue($state->has(CheckoutProblem::AddressIncomplete));
        self::assertSame(CheckoutBlock::Address, $state->blockedAt());
    }

    public function testEineAbweichendeLieferadresseMussEbenfallsVollstaendigSein(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()->withShipping(new Address(lastname: 'Muster')),
        );

        self::assertTrue($state->has(CheckoutProblem::AddressIncomplete));
    }

    public function testEinNichtBelieferbaresLandWirdGemeldet(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()->withShipping(new Address(lastname: 'M', street: 'W 1', postal: '8000', city: 'Zürich', country: 'CH', email: 'a@b.c')),
            deliveryCountries: ['AT', 'DE'],
        );

        self::assertTrue($state->has(CheckoutProblem::DeliveryCountryNotServed));
        self::assertSame(CheckoutBlock::Address, $state->blockedAt());
    }

    /**
     * Ohne Adresse hat der Besucher noch kein Land genannt. Dann rechnet der
     * Warenkorb im Heimatland des Shops — sonst gäbe es keinen Steuersatz und
     * die Warenkorbseite stünde leer da, bevor überhaupt jemand etwas
     * eingetippt hat.
     */
    public function testOhneAdresseGiltDasHeimatlandDesShops(): void
    {
        $state = $this->calculate(new CheckoutData());

        self::assertSame('50.00', $state->cart->net);
        self::assertSame('60.00', $state->cart->gross);
        self::assertTrue($state->has(CheckoutProblem::AddressIncomplete));
    }

    public function testEineUngueltigeUidHaeltAn(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()
                ->withVatId('ATU00000000')
                ->withVatCheck(new VatIdCheckResult(VatIdStatus::Invalid, self::NOW)),
        );

        self::assertTrue($state->has(CheckoutProblem::VatIdInvalid));
        self::assertFalse($state->isReady());
    }

    /**
     * 🔴 C15: Ein nicht erreichbares VIES sperrt nicht. Der Besucher kann eine
     * Störung in Brüssel nicht beheben, indem er wartet — er bestellt mit
     * Steuer und bekommt „Erneut prüfen".
     */
    public function testEinNichtErreichbaresViesSperrtNicht(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()
                ->withVatId('ATU12345678')
                ->withVatCheck(new VatIdCheckResult(VatIdStatus::Unavailable, self::NOW)),
        );

        self::assertTrue($state->has(CheckoutProblem::VatServiceUnavailable));
        self::assertTrue($state->isReady());
        self::assertNull($state->blockedAt());
        self::assertFalse($state->reverseCharge);
    }

    public function testEineGeprueftUidInsAuslandNimmtDieSteuerWeg(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()
                ->withShipping(new Address(lastname: 'M', street: 'W 1', postal: '10115', city: 'Berlin', country: 'DE', email: 'a@b.c'))
                ->withVatId('DE123456789')
                ->withVatCheck(new VatIdCheckResult(VatIdStatus::Valid, self::NOW, null, 'WAPI1')),
        );

        self::assertTrue($state->reverseCharge);

        // Die Ware ist wirklich drin — sonst prüft die Gleichung unten nur
        // den Versand (so geschehen bis C-12, siehe grossCalculator()).
        self::assertCount(1, $state->cart->lines);
        self::assertSame([], $state->cart->unpriced);
        self::assertTrue($state->isReady());

        // Kein Satz, also ist brutto gleich netto — auch an der Zeile, die die
        // Übersicht zeigt. Die Rechnung trägt statt eines Satzes den
        // Reverse-Charge-Hinweis.
        self::assertSame($state->cart->lines[0]->lineNet, $state->cart->lines[0]->lineGross);
        self::assertSame('55.00', $state->netTotal);
        self::assertSame('55.00', $state->grossTotal);
    }

    /**
     * Die Gegenprobe: dieselbe Lieferung nach Deutschland OHNE UID trägt
     * die deutschen 19 % — der Satz existiert, nur Reverse Charge nimmt ihn weg.
     */
    /**
     * C-12 (Michael, 2026-09-26: „1a nur mit eigener UID"): Ohne UID des
     * Shops rechnet der Checkout mit Steuer, auch bei geprüfter UID des
     * Kunden — Art. 11 Abs. 2 UStG 1994 Anhang (Binnenmarkt).
     */
    public function testOhneEigeneUidDesShopsKeinReverseCharge(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()
                ->withShipping(new Address(lastname: 'M', street: 'W 1', postal: '10115', city: 'Berlin', country: 'DE', email: 'a@b.c'))
                ->withVatId('DE123456789')
                ->withVatCheck(new VatIdCheckResult(VatIdStatus::Valid, self::NOW, null, 'WAPI1')),
            shopVatId: '',
            taxAtDestination: true,
        );

        self::assertFalse($state->reverseCharge);
        self::assertSame('59.50', $state->cart->gross);
    }

    public function testOhneUidInsAuslandGiltDerSatzDesLieferlands(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()
                ->withShipping(new Address(lastname: 'M', street: 'W 1', postal: '10115', city: 'Berlin', country: 'DE', email: 'a@b.c')),
            taxAtDestination: true,
        );

        self::assertFalse($state->reverseCharge);
        self::assertCount(1, $state->cart->lines);
        self::assertSame('59.50', $state->cart->gross);
        // Der Versand folgt der Ware ins Lieferland: 5,00 netto zu 19 %.
        self::assertSame('65.45', $state->grossTotal);
        self::assertTrue($state->taxAtDestination);
    }

    /**
     * Block R (O1, R2): Ohne „Steuer im Bestimmungsland" ist der Fernverkauf
     * an einen Verbraucher in einem anderen EU-Land im Shopland zu
     * versteuern — Ware UND Versand zu 20 %, wie im Inland. Geliefert wird
     * trotzdem nach Deutschland: Nur der Satz folgt dem Schalter.
     */
    public function testOhneSchalterGiltImEuAuslandDerSatzDesShops(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()
                ->withShipping(new Address(lastname: 'M', street: 'W 1', postal: '10115', city: 'Berlin', country: 'DE', email: 'a@b.c')),
        );

        self::assertSame('DE', $state->data->deliveryCountry());
        self::assertSame('AT', $state->cart->lines[0]->taxCountry);
        self::assertSame('60.00', $state->cart->gross);
        self::assertSame('66.00', $state->grossTotal);
        self::assertFalse($state->taxAtDestination);
    }

    /**
     * Auch die Versandarten, die nicht gewählt sind, nennen ihren Preis zum
     * Satz des Shops — die Liste ist, was der Kunde vergleicht.
     */
    public function testOhneSchalterNennenAuchDieAnderenVersandartenDenSatzDesShops(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()
                ->withShipping(new Address(lastname: 'M', street: 'W 1', postal: '10115', city: 'Berlin', country: 'DE', email: 'a@b.c')),
            shipping: [$this->versandart(1, 'Paket'), $this->versandart(2, 'Express', net: '10.0000')],
        );

        self::assertSame(['6.0000', '12.0000'], array_map(static fn ($option): string => (string) BigDecimal::of($option->gross)->toScale(4), $state->shippingOptions));
    }

    /**
     * Außerhalb der EU gibt es keine Lieferschwelle: Der Satz, den der
     * Betreiber für das Land eingetragen hat, gilt mit und ohne Schalter.
     */
    public function testAusserhalbDerEuGiltDerSatzDesLandesMitUndOhneSchalter(): void
    {
        $nachZuerich = $this->vollstaendig()
            ->withShipping(new Address(lastname: 'M', street: 'W 1', postal: '8001', city: 'Zürich', country: 'CH', email: 'a@b.c'));

        foreach ([false, true] as $schalter) {
            $state = $this->calculate($nachZuerich, taxAtDestination: $schalter);

            self::assertSame('CH', $state->cart->lines[0]->taxCountry);
            self::assertSame('55.00', $state->grossTotal);
        }
    }
    public function testDieEigeneUidImInlandGibtKeinReverseCharge(): void
    {
        // § 3 UStG: Reverse Charge gilt grenzüberschreitend, nicht im eigenen
        // Land. Eine gültige österreichische UID ändert an einer Lieferung
        // nach Österreich nichts.
        $state = $this->calculate(
            $this->vollstaendig()
                ->withVatId('ATU12345678')
                ->withVatCheck(new VatIdCheckResult(VatIdStatus::Valid, self::NOW, null, 'WAPI1')),
        );

        self::assertFalse($state->reverseCharge);
        self::assertSame('66.00', $state->grossTotal);
    }

    public function testDieObersteVerfuegbareArtIstDieVorauswahl(): void
    {
        // C2: oberste = Standard; ist sie nicht verfügbar, rückt die nächste
        // nach. Eine getroffene Wahl wird nie still umgestellt.
        $state = $this->calculate($this->vollstaendig(), shipping: [
            $this->versandart(1, 'Nicht hierhin', verfuegbar: false),
            $this->versandart(2, 'Paket', net: '7.0000'),
        ]);

        self::assertSame(2, $state->shipping?->id);
        self::assertCount(2, $state->shippingOptions);
        self::assertFalse($state->shippingOptions[0]->available);
    }

    /**
     * 🔴 Eine unverfügbare Art verschwindet NICHT aus der Liste. Wer sie
     * gewählt hatte und dann das Land wechselt, muss sehen, dass sie weg ist —
     * eine Auswahl, die sich einfach auflöst, sieht aus wie ein Fehler.
     */
    public function testEineWeggefalleneWahlWirdGemeldetUndNichtErsetzt(): void
    {
        $data = $this->vollstaendig()->withShippingMethod(1);

        $state = $this->calculate($data, shipping: [
            $this->versandart(1, 'Nicht hierhin', verfuegbar: false),
            $this->versandart(2, 'Paket', net: '7.0000'),
        ]);

        self::assertNull($state->shipping);
        self::assertTrue($state->has(CheckoutProblem::ShippingMethodGone));
        self::assertSame(CheckoutBlock::Shipping, $state->blockedAt());
    }

    public function testOhneVerfuegbareVersandartGehtEsNichtWeiter(): void
    {
        $state = $this->calculate($this->vollstaendig(), shipping: [
            $this->versandart(1, 'Nicht hierhin', verfuegbar: false),
        ]);

        self::assertTrue($state->has(CheckoutProblem::NoShippingMethod));
        self::assertSame(CheckoutBlock::Shipping, $state->blockedAt());
    }

    public function testOhneVerfuegbareZahlungsartGehtEsNichtWeiter(): void
    {
        $state = $this->calculate($this->vollstaendig(), payment: []);

        self::assertTrue($state->has(CheckoutProblem::NoPaymentMethod));
        self::assertSame(CheckoutBlock::Payment, $state->blockedAt());
    }

    /**
     * Eine Art, deren Modul deinstalliert wurde, ist keine Fehlermeldung,
     * sondern schlicht ein Weg weniger.
     */
    public function testEineArtOhneRegistriertenTypVerschwindetStill(): void
    {
        $state = $this->calculate($this->vollstaendig(), shipping: [
            new ShippingMethod(1, 'aus_einem_modul_das_weg_ist', 'Kurier', ShippingTaxMode::Fixed, 1, []),
            $this->versandart(2, 'Paket', net: '5.0000'),
        ]);

        self::assertCount(1, $state->shippingOptions);
        self::assertSame(2, $state->shipping?->id);
    }

    public function testEinNachlassDerZahlungsartSenktDieSumme(): void
    {
        // C5: Der Betrag trägt eine Herkunft. Ein Nachlass ist negativ.
        $state = $this->calculate($this->vollstaendig(), payment: [
            $this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount)),
        ]);

        self::assertSame(PaymentAdjustmentOrigin::Discount, $state->paymentAdjustment);

        // 50,00 Ware + 5,00 Versand − 2,00 Nachlass = 53,00 netto.
        self::assertSame('-2.0000', $state->payment?->net);
        self::assertSame('53.00', $state->netTotal);
        self::assertSame('63.60', $state->grossTotal);
    }

    /**
     * WG8: Ein Warenkorb nur aus Gutscheinen hat keine Ware, die ein
     * Nachlass mindern könnte — die Zahlungsart bleibt, ohne Nachlass.
     */
    public function testNurGutscheineBekommenKeinenNachlassDerZahlungsart(): void
    {
        $articles = new class implements ArticleShippingDataInterface {
            public function of(array $articleIds): array
            {
                return [7 => ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => 'voucher']];
            }
        };

        $state = $this->calculate($this->vollstaendig(), items: [new CartItemRow(1, 7, '1.0000', 1)], articles: $articles, payment: [
            $this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount)),
        ]);

        self::assertSame(1, $state->payment?->id);
        self::assertTrue($state->payment->available);
        self::assertNull($state->paymentAdjustment);
        self::assertSame('50.00', $state->netTotal);
    }

    public function testDerVersandWirdNachSteuersaetzenAufgeteilt(): void
    {
        // 50,00 in Klasse 1 (20 %) und 50,00 in Klasse 2 (10 %): Die 10,00
        // Versand gehen 5,00/5,00, und die Rechnung bekommt zwei Zeilen.
        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)],
            shipping: [$this->versandart(1, 'Paket', net: '10.0000', mode: ShippingTaxMode::Proportional)],
        );

        self::assertCount(2, $state->shippingShares);
        self::assertSame('5.00', $state->shippingShares[0]->net, 'Auf den Stellen der Währung, nicht auf vier.');
        self::assertSame('6.0000', $state->shippingShares[0]->gross);
        self::assertSame('5.5000', $state->shippingShares[1]->gross);

        // 50 + 50 Ware + 10 Versand netto; brutto 60 + 55 + 6 + 5,50.
        self::assertSame('110.00', $state->netTotal);
        self::assertSame('126.50', $state->grossTotal);
    }

    /**
     * C4 in einem Satz: „gilt ebenso für einen Betrag der Zahlungsart". Ein
     * Nachlass auf einen Warenkorb mit zwei Sätzen wird genauso aufgeteilt wie
     * der Versand — sonst stünde er mit einem Satz auf der Rechnung, den
     * niemand begründen kann.
     */
    public function testDerBetragDerZahlungsartWirdEbensoAufgeteilt(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)],
            payment: [$this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('4.0000', PaymentAdjustmentOrigin::Discount))],
        );

        self::assertCount(2, $state->paymentShares);
        self::assertSame('-2.00', $state->paymentShares[0]->net);
        self::assertSame('-2.4000', $state->paymentShares[0]->gross, '20 % auf die eine Hälfte.');
        self::assertSame('-2.2000', $state->paymentShares[1]->gross, '10 % auf die andere.');

        // 100 Ware + 5 Versand − 4 Nachlass = 101,00 netto.
        self::assertSame('101.00', $state->netTotal);
    }

    /**
     * E-1, Z3: Ob eine Zahlungsgebühr Nebenleistung oder eigene Leistung ist,
     * entscheidet Consho nicht. Ohne Wahl des Betreibers wird die Zahlungsart
     * gar nicht angeboten — bis E-1 scheiterte die Bestellung erst beim Klick.
     */
    public function testEineGebuehrOhneSteuerwahlWirdNichtAngeboten(): void
    {
        $state = $this->calculate($this->vollstaendig(), payment: [
            $this->zahlungsart(1, 'Nachnahme', adjustment: new PaymentAdjustment('3.0000', PaymentAdjustmentOrigin::Fee)),
            $this->zahlungsart(2, 'Vorkasse'),
        ]);

        self::assertFalse($state->paymentOptions[0]->available);
        self::assertTrue($state->paymentOptions[1]->available);
        self::assertSame(2, $state->payment?->id, 'Die Vorgabe rückt auf die erste verfügbare Art.');
    }

    public function testEineFesteGebuehrOhneSteuerklasseWirdNichtAngeboten(): void
    {
        $state = $this->calculate($this->vollstaendig(), payment: [
            $this->zahlungsart(1, 'Nachnahme', adjustment: new PaymentAdjustment('3.0000', PaymentAdjustmentOrigin::Fee), feeMode: ShippingTaxMode::Fixed),
        ]);

        self::assertFalse($state->paymentOptions[0]->available);
        self::assertTrue($state->has(CheckoutProblem::NoPaymentMethod));
    }

    public function testEineAnteiligeGebuehrWirdWieDerVersandAufgeteilt(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)],
            payment: [$this->zahlungsart(1, 'Nachnahme', adjustment: new PaymentAdjustment('4.0000', PaymentAdjustmentOrigin::Fee), feeMode: ShippingTaxMode::Proportional)],
        );

        self::assertSame(PaymentAdjustmentOrigin::Fee, $state->paymentAdjustment);
        self::assertCount(2, $state->paymentShares);
        self::assertSame('2.00', $state->paymentShares[0]->net, 'Eine Gebühr ist positiv.');
        self::assertSame('2.4000', $state->paymentShares[0]->gross, '20 % auf die eine Hälfte.');
        self::assertSame('2.2000', $state->paymentShares[1]->gross, '10 % auf die andere.');

        // 100 Ware + 5 Versand + 4 Gebühr = 109,00 netto.
        self::assertSame('109.00', $state->netTotal);
    }

    public function testEineFesteGebuehrTraegtNurDieGewaehlteSteuerklasse(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)],
            payment: [$this->zahlungsart(1, 'Nachnahme', adjustment: new PaymentAdjustment('4.0000', PaymentAdjustmentOrigin::Fee), feeMode: ShippingTaxMode::Fixed, feeClass: 1)],
        );

        self::assertCount(1, $state->paymentShares);
        self::assertSame(1, $state->paymentShares[0]->taxClassId);
        self::assertSame('4.0000', $state->paymentShares[0]->net);
        self::assertSame('4.8000', $state->paymentShares[0]->gross, 'Ganz zu 20 %, obwohl die Hälfte der Ware 10 % trägt.');
        self::assertSame('4.8000', $state->payment?->gross);
    }

    /**
     * Block R (R2): Die Gebühr folgt der Ware, mit der sie kommt — nach
     * Deutschland ohne „Steuer im Bestimmungsland" zu 20 % AT, mit zu 19 % DE.
     */
    public function testDieGebuehrFolgtDemSchalter(): void
    {
        $nachBerlin = $this->vollstaendig()
            ->withShipping(new Address(lastname: 'M', street: 'W 1', postal: '10115', city: 'Berlin', country: 'DE', email: 'a@b.c'));
        $nachnahme = [$this->zahlungsart(1, 'Nachnahme', adjustment: new PaymentAdjustment('4.0000', PaymentAdjustmentOrigin::Fee), feeMode: ShippingTaxMode::Fixed, feeClass: 1)];

        self::assertSame('4.8000', $this->calculate($nachBerlin, payment: $nachnahme)->payment?->gross);
        self::assertSame('4.7600', $this->calculate($nachBerlin, payment: $nachnahme, taxAtDestination: true)->payment?->gross);
    }

    /**
     * E-3, Z2: Nachnahme gibt es nur mit einer Versandart, die kassiert. Der
     * Checkout reicht die Fähigkeiten der GEWÄHLTEN Versandart weiter.
     */
    public function testNachnahmeNurMitEinerVersandartDieKassiert(): void
    {
        $nachnahme = new PaymentMethod(2, 'cod', 'Nachnahme', ['fee' => '3.0000', 'feeCurrency' => 'EUR'], ShippingTaxMode::Fixed, 1);
        $mit = new ShippingMethod(1, 'pauschale', 'Post mit Nachnahme', ShippingTaxMode::Fixed, 1, ['net' => '5.0000', 'nachnahme' => true]);
        $ohne = new ShippingMethod(1, 'pauschale', 'Paket', ShippingTaxMode::Fixed, 1, ['net' => '5.0000', 'nachnahme' => false]);

        $state = $this->calculate($this->vollstaendig(), shipping: [$mit], payment: [$this->zahlungsart(1, 'Vorkasse'), $nachnahme]);

        self::assertTrue($state->paymentOptions[1]->available);
        self::assertSame('3.0000', $state->paymentOptions[1]->net, 'die Gebühr netto');
        self::assertSame('3.6000', $state->paymentOptions[1]->gross, 'fest zu 20 %');

        $state = $this->calculate($this->vollstaendig(), shipping: [$ohne], payment: [$this->zahlungsart(1, 'Vorkasse'), $nachnahme]);

        self::assertFalse($state->paymentOptions[1]->available);
    }

    /**
     * E-4, V1: Der Checkout gibt der Versandart Gewicht und Maße je Stück mit,
     * aus dem Katalog — bis E-4 las sie niemand.
     */
    public function testDieVersandartBekommtGewichtUndMasse(): void
    {
        $waage = new class implements ShippingMethodTypeInterface {
            /** @var list<ShippingLine> */
            public array $gesehen = [];

            public static function getType(): string
            {
                return 'waage';
            }

            public function isAvailable(ShippingContext $context, array $config): bool
            {
                $this->gesehen = $context->lines;

                return true;
            }

            public function calculate(ShippingContext $context, array $config): ShippingQuote
            {
                return new ShippingQuote('0.0000');
            }

            public function capabilities(array $config): ShippingCapabilities
            {
                return new ShippingCapabilities(0, false);
            }
        };

        $articles = new class implements ArticleShippingDataInterface {
            public function of(array $articleIds): array
            {
                return [7 => ['weight' => '1.2500', 'length' => '30.0000', 'width' => '20.0000', 'height' => '5.0000', 'kind' => 'physical']];
            }
        };

        $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '2.0000', 1)],
            shipping: [new ShippingMethod(1, 'waage', 'Paket', ShippingTaxMode::Fixed, 1, [])],
            shippingTypes: [$waage],
            articles: $articles,
        );

        self::assertCount(1, $waage->gesehen);
        $line = $waage->gesehen[0];
        self::assertSame('1.2500', $line->weight);
        self::assertSame(['30.0000', '20.0000', '5.0000'], [$line->length, $line->width, $line->height]);
        self::assertSame('2.5000', $line->totalWeight(), 'zwei Stück');
    }

    /**
     * F-1, F13: Versand braucht es, sobald EINE Position körperlich ist —
     * Buch mit E-Book geht als Paket. Nur digitale Positionen: kein Paket,
     * sondern die elektronische Zustellung (F-2).
     * Ohne Datenquelle oder für einen unbekannten Artikel: körperlich.
     */
    public function testVersandNurBeiKoerperlichenPositionen(): void
    {
        $articles = new class implements ArticleShippingDataInterface {
            public function of(array $articleIds): array
            {
                $zeile = ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000'];

                return [7 => [...$zeile, 'kind' => 'digital'], 8 => [...$zeile, 'kind' => 'physical']];
            }
        };

        $digital = $this->calculate($this->vollstaendig(), items: [new CartItemRow(1, 7, '1.0000', 1)], articles: $articles);
        self::assertTrue($digital->electronicDelivery);
        self::assertTrue($digital->hasDigitalLines);

        $gemischt = $this->calculate($this->vollstaendig(), items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)], articles: $articles);
        self::assertFalse($gemischt->electronicDelivery);
        self::assertTrue($gemischt->hasDigitalLines, 'F-3: die Zustimmung auch im gemischten Warenkorb');

        self::assertFalse($this->calculate($this->vollstaendig())->electronicDelivery, 'ohne Datenquelle körperlich');
        self::assertFalse($this->calculate($this->vollstaendig())->hasDigitalLines);
    }

    /**
     * Nachlese E5b: Die Kasse sagt der Zahlungsart, ob digitale Inhalte oder
     * Gutscheine im Korb sind — „Auf Rechnung" mit Schalter fällt dann weg.
     * Eine Dienstleistung zählt nicht.
     */
    public function testAufRechnungNichtFuerSofortNutzbareGueter(): void
    {
        $rechnung = new PaymentMethod(1, 'invoice', 'Auf Rechnung', ['notHandedOutAtOnce' => '1']);

        // Artikel 7 — der Testkatalog hat einen Preis nur für ihn — in jeder Art.
        $angeboten = function (string $kind) use ($rechnung): bool {
            $articles = new class($kind) implements ArticleShippingDataInterface {
                public function __construct(private readonly string $kind)
                {
                }

                public function of(array $articleIds): array
                {
                    return [7 => ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => $this->kind]];
                }
            };

            $state = $this->calculate(
                $this->vollstaendig(),
                items: [new CartItemRow(1, 7, '1.0000', 1)],
                payment: [$rechnung],
                articles: $articles,
                paymentHandlers: ['invoice' => new InvoicePaymentHandler()],
            );
            self::assertCount(1, $state->cart->lines);

            return $state->paymentOptions[0]->available;
        };

        self::assertFalse($angeboten('digital'), 'digital');
        self::assertFalse($angeboten('voucher'), 'Gutschein');
        self::assertTrue($angeboten('service'), 'Dienstleistung');
        self::assertTrue($angeboten('physical'), 'Ware');
    }

    /**
     * Variante A (Michael, 2026-10-08: „nicht automatisch, der Betreiber muss
     * diese Jahresabos auf Geschäftskunden limitieren"): Die Kasse sperrt
     * „volle Mindestlaufzeit" nicht mehr für Verbraucher — wem sie angeboten
     * wird, entscheiden die Kundengruppen der Regel, schon beim Hinzufügen.
     */
    public function testGleicheLaufzeitSperrtDenVerbraucherNichtMehr(): void
    {
        $verbraucher = $this->calculate($this->vollstaendig(), items: [new CartItemRow(1, 7, '1.0000', 1, 11)]);

        self::assertTrue($verbraucher->isReady());
        self::assertSame([], array_map(static fn (CheckoutProblem $problem): string => $problem->value, $verbraucher->problems));
    }

    /**
     * Abo-2, P14 (A12): Der Zustimmungskasten richtet sich nach den
     * Widerrufsarten der digitalen Zeilen — Artikel 7 digitaler Inhalt,
     * 8 Dienstleistung, 9 körperlich.
     */
    public function testDieWiderrufsartenDerDigitalenZeilen(): void
    {
        $articles = new class implements ArticleShippingDataInterface {
            public function of(array $articleIds): array
            {
                $zeile = ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000'];

                return [7 => [...$zeile, 'kind' => 'digital'], 8 => [...$zeile, 'kind' => 'service'], 9 => [...$zeile, 'kind' => 'physical']];
            }
        };

        $arten = fn (array $items): array => $this->calculate($this->vollstaendig(), items: $items, articles: $articles)->withdrawalKinds;

        self::assertSame(['content'], $arten([new CartItemRow(1, 7, '1.0000', 1)]));
        self::assertSame(['service'], $arten([new CartItemRow(1, 8, '1.0000', 1)]));
        self::assertSame(['content', 'service'], $arten([new CartItemRow(1, 8, '1.0000', 1), new CartItemRow(2, 7, '1.0000', 1), new CartItemRow(3, 9, '1.0000', 1)]));
        self::assertSame([], $arten([new CartItemRow(1, 9, '1.0000', 1)]));
    }

    /**
     * WG3: Ein Wertgutschein wird elektronisch zugestellt, verlangt aber
     * keine Zustimmung zum vorzeitigen Beginn — es beginnt nichts vorzeitig,
     * der Code ist Geld.
     */
    public function testEinGutscheinOhneVersandUndOhneZustimmung(): void
    {
        $articles = new class implements ArticleShippingDataInterface {
            public function of(array $articleIds): array
            {
                $zeile = ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000'];

                return [7 => [...$zeile, 'kind' => 'voucher'], 8 => [...$zeile, 'kind' => 'digital']];
            }
        };

        $gutschein = $this->calculate($this->vollstaendig(), items: [new CartItemRow(1, 7, '1.0000', 1)], articles: $articles);
        self::assertTrue($gutschein->electronicDelivery);
        self::assertFalse($gutschein->hasDigitalLines);
        self::assertSame([], $gutschein->withdrawalKinds);

        $mitEbook = $this->calculate($this->vollstaendig(), items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)], articles: $articles);
        self::assertTrue($mitEbook->hasDigitalLines, 'das E-Book daneben fragt weiter');
        self::assertSame(['content'], $mitEbook->withdrawalKinds);
    }

    /**
     * F-2, F14: Nur digitale Positionen — der Versandschritt bleibt und bietet
     * die elektronische Zustellung an: eine Option, ohne Kosten, auch ohne
     * eingerichtete Versandart. Eine vorher gewählte Paket-Versandart ist
     * nicht „weg", sie gilt nur nicht. Die Altersprüfung läuft wie bei einer
     * Versandart ohne Prüfung an der Tür: nur altersgeprüfte Gruppen.
     */
    public function testElektronischeZustellung(): void
    {
        $articles = new class implements ArticleShippingDataInterface {
            public function of(array $articleIds): array
            {
                return [7 => ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => 'digital']];
            }
        };

        $state = $this->calculate($this->vollstaendig()->withShippingMethod(1), shipping: [], articles: $articles);

        self::assertTrue($state->needsShipping, 'es gibt einen Zustellschritt');
        self::assertTrue($state->electronicDelivery);
        self::assertCount(1, $state->shippingOptions);
        self::assertSame(CheckoutCalculator::ELECTRONIC_DELIVERY, $state->shipping?->id);
        self::assertSame('Electronic delivery', $state->shipping->title);
        self::assertSame([], $state->shippingShares, 'keine Versandzeile');
        self::assertFalse($state->has(CheckoutProblem::NoShippingMethod));
        self::assertFalse($state->has(CheckoutProblem::ShippingMethodGone));
        self::assertSame('60.00', $state->grossTotal, 'nur die Ware: 50 netto + 20 %');

        $ab18 = $this->calculate($this->vollstaendig(), minimumAge: 18, articles: $articles);
        self::assertTrue($ab18->has(CheckoutProblem::AgeNotVerifiable), 'keine Ident-Zustellung');

        $geprueft = $this->calculate($this->vollstaendig(), customerGroups: [5], minimumAge: 18, ageVerifiedGroups: [5], articles: $articles);
        self::assertFalse($geprueft->has(CheckoutProblem::AgeNotVerifiable));

        $paket = $this->calculate($this->vollstaendig());
        self::assertFalse($paket->electronicDelivery, 'körperlich: die Versandarten des Shops');
    }

    /**
     * E-5: Länder (V3) und Sperrgut (V4) fragt der Kern, bevor der Typ
     * gefragt wird — eine Versandart nur für Deutschland ist in Österreich
     * nicht verfügbar, eine mit 100 cm Seitenlänge nicht für 120 cm.
     */
    public function testLaenderUndGrenzenDerVersandart(): void
    {
        $nurDe = new ShippingMethod(1, 'pauschale', 'Paket DE', ShippingTaxMode::Fixed, 1, ['net' => '5.0000'], ['DE']);
        $at = new ShippingMethod(2, 'pauschale', 'Paket AT', ShippingTaxMode::Fixed, 1, ['net' => '5.0000'], ['AT']);

        $state = $this->calculate($this->vollstaendig(), shipping: [$nurDe, $at]);

        self::assertFalse($state->shippingOptions[0]->available);
        self::assertSame(2, $state->shipping?->id);

        $articles = new class implements ArticleShippingDataInterface {
            public function of(array $articleIds): array
            {
                return [7 => ['weight' => '3.0000', 'length' => '120.0000', 'width' => '20.0000', 'height' => '10.0000', 'kind' => 'physical']];
            }
        };
        $klein = new ShippingMethod(1, 'pauschale', 'Paket', ShippingTaxMode::Fixed, 1, ['net' => '5.0000'], [], new ShippingLimits('100.0000'));
        $sperrgut = new ShippingMethod(2, 'pauschale', 'Sperrgut', ShippingTaxMode::Fixed, 1, ['net' => '25.0000']);

        $state = $this->calculate($this->vollstaendig(), shipping: [$klein, $sperrgut], articles: $articles);

        self::assertFalse($state->shippingOptions[0]->available, 'die längste Seite 120 cm passt nicht in 100 cm');
        self::assertSame(2, $state->shipping?->id);
    }

    /**
     * E-6, V6: Braucht der Paketdienst der gewählten Versandart eine
     * Telefonnummer, fragt der Checkout danach — sonst nicht.
     */
    public function testDasTelefonNurWennDieVersandartEsBraucht(): void
    {
        $telefon = new class implements ShippingMethodTypeInterface {
            public static function getType(): string
            {
                return 'avis';
            }

            public function isAvailable(ShippingContext $context, array $config): bool
            {
                return true;
            }

            public function calculate(ShippingContext $context, array $config): ShippingQuote
            {
                return new ShippingQuote('5.0000');
            }

            public function capabilities(array $config): ShippingCapabilities
            {
                return new ShippingCapabilities(0, false, false, true);
            }
        };
        $avis = [new ShippingMethod(1, 'avis', 'Paket mit Avis', ShippingTaxMode::Fixed, 1, [])];

        $state = $this->calculate($this->vollstaendig(), shipping: $avis, shippingTypes: [$telefon]);
        self::assertTrue($state->needsPhone);
        self::assertTrue($state->has(CheckoutProblem::PhoneMissing));
        self::assertFalse($state->isReady());

        $state = $this->calculate($this->vollstaendig()->withShippingPhone('+436641234567'), shipping: $avis, shippingTypes: [$telefon]);
        self::assertFalse($state->has(CheckoutProblem::PhoneMissing));

        $state = $this->calculate($this->vollstaendig());
        self::assertFalse($state->needsPhone);
        self::assertFalse($state->has(CheckoutProblem::PhoneMissing));
    }

    /**
     * 🔴 Versand UND Nachlass zusammen. Beim Bauen hatte ich für beide
     * dieselbe Variable benutzt, und die Zahlungsstufe überschrieb die
     * Versandanteile — die Summe stimmte dann zufällig fast.
     */
    public function testVersandUndNachlassKommenBeideInDerSummeAn(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            shipping: [$this->versandart(1, 'Paket', net: '5.0000')],
            payment: [$this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount))],
        );

        self::assertCount(1, $state->shippingShares);
        self::assertSame('5.0000', $state->shippingShares[0]->net, 'Ein einziger Anteil IST der Betrag und wird nicht gerundet.');
        self::assertCount(1, $state->paymentShares);
        self::assertSame('-2.00', $state->paymentShares[0]->net);
        self::assertSame('53.00', $state->netTotal);
    }

    /**
     * 🔴 Im Review vom 2026-09-22 gemessen. Ein Versand von 4,95 auf zwei
     * Steuerklassen zu gleichen Teilen ergab 2,4750 je Zeile — auf der
     * Rechnung zweimal 2,48, zusammen 4,96. Der Besucher hatte 4,95 gelesen.
     *
     * Seitdem teilt der Aufteiler auf den Stellen der Währung, und die Summe
     * der Zeilen ist genau der genannte Betrag.
     */
    public function testDieVersandzeilenErgebenGenauDenGenanntenBetrag(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)],
            shipping: [$this->versandart(1, 'Paket', net: '4.9500', mode: ShippingTaxMode::Proportional)],
        );

        $summe = BigDecimal::zero();

        foreach ($state->shippingShares as $share) {
            $summe = $summe->plus($share->net);
        }

        self::assertSame(0, $summe->compareTo(BigDecimal::of('4.95')), 'Die Zeilen ergeben den Versandbetrag.');
        self::assertSame('100.00', $state->cart->net);
        self::assertSame('104.95', $state->netTotal);
    }

    /**
     * 🔴 Ebenfalls aus dem Review: Das Brutto der Versandoption kam aus der
     * Steuerklasse der Versandart. Bei „anteilig" dient die Klasse laut 5.1
     * nur der Umrechnung einer Brutto-Eingabe — als Satz genommen ergab sie
     * 5,94 an der Option, während die Zeilen zusammen 5,6925 ergaben.
     */
    public function testDasBruttoDerVersandoptionIstDieSummeSeinerZeilen(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)],
            shipping: [$this->versandart(1, 'Paket', net: '10.0000', mode: ShippingTaxMode::Proportional)],
        );

        $summe = BigDecimal::zero();

        foreach ($state->shippingShares as $share) {
            $summe = $summe->plus($share->gross);
        }

        self::assertNotNull($state->shipping);
        self::assertSame(0, $summe->compareTo(BigDecimal::of($state->shipping->gross)));

        // 5,00 zu 20 % und 5,00 zu 10 % sind 11,50 — nicht 12,00, wie es die
        // Klasse der Versandart allein ergäbe.
        self::assertSame('11.5000', $state->shipping->gross);
    }

    /**
     * Die Gruppen reisen als Liste bis in den Versandkontext — die Korrektur
     * vom 2026-09-22. Ein Modul, das „Händler zahlen keinen Versand" anbietet,
     * sieht dieselben Gruppen, mit denen der Preis gerechnet wurde.
     */
    public function testDieKundengruppenErreichenDenVersandkontextVollstaendig(): void
    {
        $type = new class implements ShippingMethodTypeInterface {
            /**
             * @var list<int>|null
             */
            public array|null $gesehen = null;

            public static function getType(): string
            {
                return 'merker';
            }

            public function isAvailable(ShippingContext $context, array $config): bool
            {
                $this->gesehen = $context->customerGroups;

                return true;
            }

            public function calculate(ShippingContext $context, array $config): ShippingQuote
            {
                return new ShippingQuote('0.0000');
            }

            public function capabilities(array $config): ShippingCapabilities
            {
                return new ShippingCapabilities(0, false);
            }
        };

        $this->calculate(
            $this->vollstaendig(),
            shipping: [new ShippingMethod(1, 'merker', 'Paket', ShippingTaxMode::Fixed, 1, [])],
            shippingTypes: [$type],
            customerGroups: [2, 3],
        );

        self::assertSame([2, 3], $type->gesehen);
    }

    /**
     * Die Altersprüfung hängt in der Versandstufe, weil eine ihrer beiden
     * Antworten die Versandart ist. Sie meldet sich, bevor überhaupt eine
     * gewählt wurde — ein Warenkorb, den niemand kaufen darf, soll das nicht
     * erst nach drei Schritten sagen.
     */
    public function testEinArtikelAbAchtzehnOhneJedenPruefwegWirdGemeldet(): void
    {
        $state = $this->calculate($this->vollstaendig(), minimumAge: 18);

        self::assertTrue($state->has(CheckoutProblem::AgeNotVerifiable));
        self::assertSame(CheckoutBlock::Shipping, $state->blockedAt());
        self::assertSame(18, $state->minimumAge);
    }

    public function testEineGepruefteGruppeLaesstDenArtikelAbAchtzehnDurch(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            customerGroups: [3],
            minimumAge: 18,
            ageVerifiedGroups: [3],
        );

        self::assertTrue($state->isReady());
    }

    public function testEinHinweisalterHaeltNichtAn(): void
    {
        // 6, 12, 14 sind laut C19 ein Hinweis. Der Warenkorb sagt es, der
        // Checkout lässt durch.
        $state = $this->calculate($this->vollstaendig(), minimumAge: 12);

        self::assertTrue($state->isReady());
        self::assertSame(12, $state->minimumAge);
    }

    /**
     * Derselbe Zustand zweimal gerechnet ergibt dasselbe — sonst könnte der
     * Kaufklick (7.3) die Prüfsumme des Formulars nicht gegen eine frische
     * Rechnung halten.
     */
    public function testZweimalGerechnetKommtDasselbeHeraus(): void
    {
        $data = $this->vollstaendig();

        self::assertEquals($this->calculate($data), $this->calculate($data));
    }

    // ── Aufbau ───────────────────────────────────────────────────────────

    private function vollstaendig(): CheckoutData
    {
        return (new CheckoutData())->withBilling(new Address(
            lastname: 'Muster',
            street: 'Hauptstr. 1',
            postal: '1100',
            city: 'Wien',
            country: 'AT',
            email: 'eva@example.org',
        ));
    }

    /**
     * @param list<CartItemRow>|null              $items
     * @param list<ShippingMethod>|null           $shipping
     * @param list<PaymentMethod>|null            $payment
     * @param list<ShippingMethodTypeInterface>   $shippingTypes
     * @param list<string>                        $deliveryCountries
     * @param list<int>                           $customerGroups
     * @param list<int>                           $ageVerifiedGroups
     */
    // ── Block H (H-4): Warenkorbregel in der Kasse ────────────────────────

    /**
     * Die Zahlungsart prüft ihre Grenze an dem, was zu zahlen ist — nach
     * dem Rabatt: 50 Ware − 10 % + 5 Versand = 50 netto.
     */
    public function testDieZahlungsartSiehtDenBetragNachDemRabatt(): void
    {
        $recorder = new RecordingPaymentHandler();

        $this->calculate(
            $this->vollstaendig(),
            payment: [new PaymentMethod(1, 'merker', 'Merker', [])],
            discounts: $this->rabatte(['code' => 'ZEHN', 'effect' => 'percent', 'percent' => '10']),
            discountCode: 'ZEHN',
            paymentHandlers: ['merker' => $recorder],
        );

        self::assertSame(50.0, (float) $recorder->gesehen);
    }

    public function testVersandkostenfreiMachtJedeVersandartZuNull(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()->withShippingMethod(1),
            shipping: [$this->versandart(1, 'Paket'), $this->versandart(2, 'Express', net: '9.0000')],
            discounts: $this->rabatte(['code' => 'FREI', 'effect' => 'freeShipping']),
            discountCode: 'FREI',
        );

        self::assertSame(CodeStatus::Applied, $state->codeStatus);
        self::assertSame([0.0, 0.0], array_map(static fn ($option): float => (float) $option->gross, $state->shippingOptions));
        self::assertSame(0.0, (float) $state->shipping?->gross);
        self::assertSame([], $state->shippingShares);
    }

    /**
     * Review H, L1: Kostet die gewählte Versandart nichts, spart der Code
     * nichts — er gilt nicht und wird nicht verbraucht (der Kaufklick löst
     * nur ein, was der Zustand trägt).
     */
    public function testVersandkostenfreiOhneVersandkostenGiltNicht(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()->withShippingMethod(1),
            shipping: [$this->versandart(1, 'Abholung', net: '0.0000')],
            discounts: $this->rabatte(['code' => 'FREI', 'effect' => 'freeShipping']),
            discountCode: 'FREI',
        );

        self::assertNull($state->discount);
        self::assertSame(CodeStatus::NothingToSave, $state->codeStatus);
    }

    /**
     * Zweites Review H, L-B: Kostet die gewählte Art nichts, stehen die
     * anderen trotzdem bei 0 — gewählt, wären sie es auch. Verbraucht wird
     * der Code nicht.
     */
    public function testVersandkostenfreiZeigtJedeArtMitNullAuchWennDieGewaehlteNichtsKostet(): void
    {
        $state = $this->calculate(
            $this->vollstaendig()->withShippingMethod(1),
            shipping: [$this->versandart(1, 'Abholung', net: '0.0000'), $this->versandart(2, 'Paket', net: '4.0000')],
            discounts: $this->rabatte(['code' => 'FREI', 'effect' => 'freeShipping']),
            discountCode: 'FREI',
        );

        self::assertNull($state->discount);
        self::assertSame(CodeStatus::NothingToSave, $state->codeStatus);
        self::assertSame([0.0, 0.0], array_map(static fn ($option): float => (float) $option->gross, $state->shippingOptions));
    }

    /**
     * Zwei Regeln, die gleich viel abziehen: gleiche Summen, anderer Rabatt.
     * Der Fingerabdruck muss ihn kennen, sonst friert der Kauf eine Zeile
     * ein, die nicht über dem Knopf stand.
     */
    public function testDerFingerabdruckKenntDenRabatt(): void
    {
        $discounts = $this->rabatte(['code' => 'EINS', 'effect' => 'percent', 'percent' => '10'], ['code' => 'ZWEI', 'effect' => 'percent', 'percent' => '10']);
        $fingerprint = new CheckoutFingerprint();

        $eins = $this->calculate($this->vollstaendig(), discounts: $discounts, discountCode: 'EINS');
        $discounts->reset();
        $zwei = $this->calculate($this->vollstaendig(), discounts: $discounts, discountCode: 'ZWEI');
        $ohne = $this->calculate($this->vollstaendig());

        self::assertSame($eins->grossTotal, $zwei->grossTotal);
        self::assertNotSame($fingerprint->of($eins), $fingerprint->of($zwei));
        self::assertNotSame($fingerprint->of($eins), $fingerprint->of($ohne));
    }

    // ── Wertgutscheine (WG-5) ─────────────────────────────────────────────
    // Artikel 7: 50 netto zu 20 % = 60,00; Paket 5 netto zu 20 % = 6,00.

    /**
     * E1: Deckt der Gutschein alles, gibt es nichts einzuziehen — Zahlungsart
     * „Gutschein", allein, auch wenn vorher eine andere gewählt war.
     */
    public function testVollGedecktBrauchtKeineZahlungsart(): void
    {
        $state = $this->calculate($this->vollstaendig()->withPaymentMethod(1), vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '100.0000']), voucherCodes: ['AAAAAAAAAAAAAAAA']);

        self::assertTrue($state->coveredByVouchers);
        self::assertSame(CheckoutCalculator::PAYMENT_BY_VOUCHER, $state->payment?->id);
        self::assertSame('Gift voucher', $state->payment->title);
        self::assertCount(1, $state->paymentOptions);
        self::assertSame([], $state->problems);
        self::assertSame('66.00', $state->grossTotal);
        self::assertSame('66.00', $state->vouchers[0]->applied);
        self::assertSame('34.0000', $state->vouchers[0]->after);
        self::assertSame('0.00', $state->toPay());
        self::assertTrue($state->isReady());
        $this->assertBilanz($state);
    }

    /**
     * E1 ohne Zahlungsart heißt: auch ohne deren Gebühr — die Gebühr gibt es
     * nur, wo eine Zahlungsart einzieht.
     */
    public function testVollGedecktOhneGebuehr(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            payment: [$this->zahlungsart(1, 'Nachnahme', adjustment: new PaymentAdjustment('4.0000', PaymentAdjustmentOrigin::Fee), feeMode: ShippingTaxMode::Fixed, feeClass: 1)],
            vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '66.0000']),
            voucherCodes: ['AAAAAAAAAAAAAAAA'],
        );

        self::assertTrue($state->coveredByVouchers);
        self::assertSame([], $state->paymentShares);
        self::assertSame('66.00', $state->grossTotal);
        self::assertSame('0.00', $state->toPay());
        $this->assertBilanz($state);
    }

    /**
     * E2: Bei Teildeckung bleiben Gebühr und Grenzen auf der ganzen
     * Bestellung; die Gutscheine decken danach den Gesamtbetrag samt Gebühr,
     * in der Reihenfolge der Eingabe.
     */
    public function testTeilgedecktZahltDieZahlungsartDenRestSamtGebuehr(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            payment: [$this->zahlungsart(1, 'Nachnahme', adjustment: new PaymentAdjustment('4.0000', PaymentAdjustmentOrigin::Fee), feeMode: ShippingTaxMode::Fixed, feeClass: 1)],
            vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '30.0000', 'BBBBBBBBBBBBBBBB' => '20.0000']),
            voucherCodes: ['BBBBBBBBBBBBBBBB', 'AAAAAAAAAAAAAAAA'],
        );

        self::assertFalse($state->coveredByVouchers);
        self::assertSame(1, $state->payment?->id);
        // 60 + 6 + 4,80 Gebühr
        self::assertSame('70.80', $state->grossTotal);
        self::assertSame(['20.00', '30.00'], array_map(static fn ($v): string => $v->applied, $state->vouchers));
        self::assertSame('20.80', $state->toPay());
        $this->assertBilanz($state);
    }

    /**
     * Review WG-7, S3 (Michael, 2026-10-06: „kein Skonto bei Gutscheinen"):
     * 66 Bestellung, 64 Gutschein, Vorkasse mit 2 Nachlass — ohne die Regel
     * senkte der Nachlass auf 63,60 und der Gutschein zahlte alles. Jetzt:
     * kein Nachlass, Vorkasse zieht 2 ein.
     */
    public function testKeinSkontoWennEinGutscheinMitzahlt(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            payment: [$this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount))],
            vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '64.0000']),
            voucherCodes: ['AAAAAAAAAAAAAAAA'],
        );

        self::assertSame(1, $state->payment?->id);
        self::assertNull($state->paymentAdjustment);
        self::assertSame('66.00', $state->grossTotal);
        self::assertSame('2.00', $state->toPay());
        $this->assertBilanz($state);

        // Ohne Gutschein bleibt der Nachlass.
        $ohne = $this->calculate($this->vollstaendig(), payment: [$this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount))]);
        self::assertSame(PaymentAdjustmentOrigin::Discount, $ohne->paymentAdjustment);

        // Ein Gutschein, der nichts zahlt (gesperrt), nimmt den Nachlass nicht.
        $gesperrt = $this->calculate(
            $this->vollstaendig(),
            payment: [$this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount))],
            vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '64.0000'], blocked: true),
            voucherCodes: ['AAAAAAAAAAAAAAAA'],
        );
        self::assertSame(PaymentAdjustmentOrigin::Discount, $gesperrt->paymentAdjustment);
    }

    /**
     * Mit einer Abo-Zeile bleibt die Zahlungsart: sie trägt die
     * Folgezahlungen. Die erste deckt der Gutschein trotzdem.
     */
    public function testMitAboBleibtDieZahlungsart(): void
    {
        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1, 5)],
            vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '100.0000']),
            voucherCodes: ['AAAAAAAAAAAAAAAA'],
        );

        self::assertFalse($state->coveredByVouchers);
        self::assertSame(1, $state->payment?->id);
        self::assertSame('0.00', $state->toPay());
        $this->assertBilanz($state);
    }

    /**
     * WG8: Geld kauft man nicht mit einem Gutschein — die Gutscheinzeile
     * bleibt offen, gedeckt wird nur der Rest.
     */
    public function testGutscheinzeileZahltKeinGutschein(): void
    {
        $articles = new class implements ArticleShippingDataInterface {
            public function of(array $articleIds): array
            {
                $zeile = ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000'];

                return [7 => [...$zeile, 'kind' => 'voucher'], 8 => [...$zeile, 'kind' => 'physical']];
            }
        };

        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)],
            articles: $articles,
            vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '1000.0000']),
            voucherCodes: ['AAAAAAAAAAAAAAAA'],
        );

        // 60 Gutscheinzeile + 55 Ware + Paket: nur Ware und Paket gedeckt.
        self::assertFalse($state->coveredByVouchers);
        self::assertSame(1, $state->payment?->id);
        self::assertSame('60.00', $state->toPay());
        $this->assertBilanz($state);
    }

    /**
     * E4: Zahlt ein Gutschein zwischen Seite und Klick weniger (anderswo
     * eingelöst), ist es eine andere Vereinbarung — die Prüfsumme sagt es.
     */
    public function testDiePruefsummeKenntDieGutscheine(): void
    {
        $voll = $this->calculate($this->vollstaendig(), vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '30.0000']), voucherCodes: ['AAAAAAAAAAAAAAAA']);
        $weniger = $this->calculate($this->vollstaendig(), vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '10.0000']), voucherCodes: ['AAAAAAAAAAAAAAAA']);
        $ohne = $this->calculate($this->vollstaendig());

        $fingerprint = new CheckoutFingerprint();

        self::assertSame($voll->grossTotal, $weniger->grossTotal);
        self::assertNotSame($fingerprint->of($voll), $fingerprint->of($weniger));
        self::assertNotSame($fingerprint->of($voll), $fingerprint->of($ohne));
    }

    /**
     * E4: zwischen Seite und Klick gesperrt — der Gutschein zahlt nichts mehr,
     * die Deckung kippt, die Zahlungsart ist wieder eine echte. Eine andere
     * Vereinbarung, also eine andere Prüfsumme (Review WG-7, P3).
     */
    public function testEinGesperrterGutscheinAendertDiePruefsumme(): void
    {
        $vorher = $this->calculate($this->vollstaendig(), vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '100.0000']), voucherCodes: ['AAAAAAAAAAAAAAAA']);
        $wallet = $this->gutscheine(['AAAAAAAAAAAAAAAA' => '100.0000'], blocked: true);
        $nachher = $this->calculate($this->vollstaendig(), vouchers: $wallet, voucherCodes: ['AAAAAAAAAAAAAAAA']);

        self::assertTrue($vorher->coveredByVouchers);
        self::assertFalse($nachher->coveredByVouchers);
        self::assertSame(1, $nachher->payment?->id);
        self::assertSame([], $nachher->payingVouchers());
        self::assertNotSame((new CheckoutFingerprint())->of($vorher), (new CheckoutFingerprint())->of($nachher));
        $this->assertBilanz($nachher);
    }

    /**
     * Was PurchaseSettlement sehen muss (Review WG-7, P1): die Gutscheine
     * zahlen, was sie zeigen, die Zahlungsart den Rest — zusammen genau der
     * Gesamtbetrag, der auf die Bestellung kommt.
     */
    private function assertBilanz(CheckoutState $state): void
    {
        $sum = BigDecimal::of($state->toPay());

        foreach ($state->payingVouchers() as $voucher) {
            $sum = $sum->plus($voucher->applied);
        }

        self::assertTrue($sum->isEqualTo($state->grossTotal), $sum.' ≠ '.$state->grossTotal);
        self::assertFalse(BigDecimal::of($state->toPay())->isNegative());
    }

    /**
     * @param array<string, string> $balances code => Saldo
     */
    private function gutscheine(array $balances, bool $blocked = false): VoucherWallet
    {
        $connection = \Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks::connection();
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($connection, 'tl_consho_voucher');

        foreach ($balances as $code => $balance) {
            $connection->insert('tl_consho_voucher', ['code' => $code, 'shop' => 1, 'currency' => 'EUR', 'faceValue' => $balance, 'balance' => $balance, 'blocked' => $blocked ? 1 : 0]);
        }

        return new VoucherWallet($connection);
    }

    /**
     * @param array<string, mixed> ...$rules
     */
    private function rabatte(array ...$rules): CartDiscounts
    {
        $connection = \Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_cart_rule', 'tl_consho_cart_rule_redemption', 'tl_consho_product', 'tl_consho_product_property'] as $table) {
            \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($connection, $table);
        }

        foreach ($rules as $rule) {
            $connection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Rabatt', 'published' => 1, ...$rule]);
        }

        return new CartDiscounts($connection, new ProductConditions($connection));
    }

    private function calculate(
        CheckoutData $data,
        array|null $items = null,
        array|null $shipping = null,
        array|null $payment = null,
        array $shippingTypes = [],
        array $deliveryCountries = [],
        array $customerGroups = [],
        int $minimumAge = 0,
        array $ageVerifiedGroups = [],
        string $shopVatId = 'ATU12345678',
        ArticleShippingDataInterface|null $articles = null,
        bool $netAudience = false,
        Availability|null $availability = null,
        CartDiscounts|null $discounts = null,
        string $discountCode = '',
        array $paymentHandlers = [],
        VoucherWallet|null $vouchers = null,
        array $voucherCodes = [],
        bool $taxAtDestination = false,
    ): CheckoutState {
        $shipping ??= [$this->versandart(1, 'Paket', net: '5.0000')];
        $payment ??= [$this->zahlungsart(1, 'Vorkasse')];
        $types = [] !== $shippingTypes ? $shippingTypes : [$this->pauschale()];

        $locator = [];

        foreach ($types as $type) {
            $locator[$type::getType()] = static fn (): ShippingMethodTypeInterface => $type;
        }

        $calculator = new CheckoutCalculator(
            $this->pricer($articles),
            $this->shippingRepository($shipping),
            new ShippingMethodTypes(new ServiceLocator($locator)),
            $this->paymentRepository($payment),
            new PaymentHandlers(new ServiceLocator([
                'vorkasse' => static fn (): PaymentHandlerInterface => new PaymentHandlerStub(),
                // Die echte Nachnahme (E-3): Sie fragt die Versandart, und
                // genau diese Frage soll der Checkout beantworten.
                'cod' => static fn (): PaymentHandlerInterface => new CashOnDeliveryHandler(),
                ...array_map(static fn (PaymentHandlerInterface $handler): \Closure => static fn (): PaymentHandlerInterface => $handler, $paymentHandlers),
            ])),
            new ShippingTaxSplitter(),
            $this->grossCalculator(),
            new AgeGate(),
            $articles,
            null,
            $availability,
            $discounts,
            $vouchers,
        );

        return $calculator->calculate(
            1,
            $items ?? [new CartItemRow(1, 7, '1.0000', 1)],
            'EUR',
            'AT',
            $deliveryCountries,
            $customerGroups,
            $data,
            self::NOW,
            $minimumAge,
            $ageVerifiedGroups,
            '',
            '',
            $shopVatId,
            $netAudience,
            $discountCode,
            voucherCodes: $voucherCodes,
            taxAtDestination: $taxAtDestination,
        );
    }

    private function versandart(int $id, string $title, bool $verfuegbar = true, string $net = '5.0000', ShippingTaxMode $mode = ShippingTaxMode::Fixed): ShippingMethod
    {
        return new ShippingMethod($id, 'pauschale', $title, $mode, 1, ['net' => $net, 'verfuegbar' => $verfuegbar]);
    }

    private function zahlungsart(int $id, string $title, bool $verfuegbar = true, PaymentAdjustment|null $adjustment = null, ShippingTaxMode|null $feeMode = null, int $feeClass = 0): PaymentMethod
    {
        return new PaymentMethod($id, 'vorkasse', $title, ['verfuegbar' => $verfuegbar, 'adjustment' => $adjustment], $feeMode, $feeClass);
    }

    private function pauschale(): ShippingMethodTypeInterface
    {
        return new class implements ShippingMethodTypeInterface {
            public static function getType(): string
            {
                return 'pauschale';
            }

            public function isAvailable(ShippingContext $context, array $config): bool
            {
                return true === ($config['verfuegbar'] ?? true);
            }

            public function calculate(ShippingContext $context, array $config): ShippingQuote
            {
                return new ShippingQuote((string) ($config['net'] ?? '0.0000'));
            }

            public function capabilities(array $config): ShippingCapabilities
            {
                return new ShippingCapabilities(0, false, true === ($config['nachnahme'] ?? false));
            }
        };
    }

    /**
     * @param list<ShippingMethod> $methods
     */
    private function shippingRepository(array $methods): ShippingMethodRepositoryInterface
    {
        return new class($methods) implements ShippingMethodRepositoryInterface {
            /**
             * @param list<ShippingMethod> $methods
             */
            public function __construct(private readonly array $methods)
            {
            }

            public function forShop(int $shopId, string $currency, string $language = '', string $fallback = ''): array
            {
                return $this->methods;
            }
        };
    }

    /**
     * @param list<PaymentMethod> $methods
     */
    private function paymentRepository(array $methods): PaymentMethodRepositoryInterface
    {
        return new class($methods) implements PaymentMethodRepositoryInterface {
            /**
             * @param list<PaymentMethod> $methods
             */
            public function __construct(private readonly array $methods)
            {
            }

            public function forShop(int $shopId, string $language = '', string $fallback = ''): array
            {
                return $this->methods;
            }
        };
    }

    /**
     * Artikel 7 steht in Steuerklasse 1 (20 %), Artikel 8 in Klasse 2 (10 %),
     * beide 50,00 netto.
     */
    private function pricer(ArticleShippingDataInterface|null $kinds = null): CartPricer
    {
        $prices = new class implements CartLinePriceSource {
            public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null
            {
                return match ($articleId) {
                    7 => new CartLinePrice('50.0000', 1),
                    8 => new CartLinePrice('50.0000', 2),
                    default => null,
                };
            }
        };

        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(
            static fn (array $ids): array => array_combine($ids, $ids),
        );

        // WG8: the kind on the line, from the same source the checkout gets.
        return new CartPricer($tree, $prices, $this->grossCalculator(), kinds: $kinds);
    }

    private function grossCalculator(): GrossCalculator
    {
        $rates = new class implements TaxRateRepositoryInterface {
            /*
             * Deutschland mit eigenen Sätzen, seit der Abnahme von C-12
             * (2026-09-26). Vorher kannte das Doppel nur AT: Ein Artikel mit
             * Lieferung nach DE fand keinen Satz, galt als „ohne Preis" und
             * fiel aus dem Warenkorb — und der Reverse-Charge-Test prüfte
             * netto = brutto an einem Warenkorb, in dem nur noch der Versand
             * stand. Am Server rechnete der Checkout die Ware mit 19 % und
             * jede solche Bestellung scheiterte.
             */
            public function findRate(int $taxClassId, string $countryCode, int $timestamp): string|null
            {
                return match ($countryCode) {
                    'AT' => 1 === $taxClassId ? '20.0000' : '10.0000',
                    'DE' => 1 === $taxClassId ? '19.0000' : '7.0000',
                    // Block R: ein Drittland, das der Betreiber mit 0 % (Ausfuhr) führt.
                    'CH' => '0.0000',
                    default => null,
                };
            }

            public function findConfiguredCountries(int $taxClassId, int $timestamp): array
            {
                return ['AT', 'DE'];
            }

            public function findTaxClassIdsUsedByShop(int $shopId): array
            {
                return [1, 2];
            }

            public function findClassesWithBoundaryIn(int $after, int $until): array
            {
                return [];
            }
        };

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE', 'CH']);

        return new GrossCalculator(new TaxResolver($rates, new CountryProvider($countries)));
    }
}

/**
 * Außerhalb der Testklasse, weil eine anonyme Klasse keine statische Methode
 * einer Schnittstelle mit `getType()` tragen kann, ohne dass PHPUnit sie beim
 * Sammeln instanziiert — und weil PaymentHandlers den Typ statisch abfragt.
 */
final class PaymentHandlerStub implements PaymentHandlerInterface
{
    public static function getType(): string
    {
        return 'vorkasse';
    }

    public function isAvailable(PaymentContext $context, array $config): bool
    {
        return true === ($config['verfuegbar'] ?? true);
    }

    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null
    {
        $adjustment = $config['adjustment'] ?? null;

        return $adjustment instanceof PaymentAdjustment ? $adjustment : null;
    }

    public function documentTrigger(array $config): DocumentTrigger
    {
        return DocumentTrigger::OnOrder;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        return PaymentStart::completed('', []);
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        return AttemptState::Pending;
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        return NotificationResult::ignored();
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
    }
}

/**
 * Block H (H-4): records the amount a payment method judges its limit on.
 */
final class RecordingPaymentHandler implements PaymentHandlerInterface
{
    public string|null $gesehen = null;

    public static function getType(): string
    {
        return 'merker';
    }

    public function isAvailable(PaymentContext $context, array $config): bool
    {
        $this->gesehen = $context->netTotal;

        return true;
    }

    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null
    {
        return null;
    }

    public function documentTrigger(array $config): DocumentTrigger
    {
        return DocumentTrigger::OnOrder;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        return PaymentStart::completed('', []);
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        return AttemptState::Pending;
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        return NotificationResult::ignored();
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
    }
}
