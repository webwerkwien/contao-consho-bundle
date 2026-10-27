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
 * The calculation step from 7.2. Everything here runs without a database —
 * the two repositories have been interfaces since C-5, precisely for this.
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

        // 50.00 goods + 5.00 shipping = 55.00 net, 20 % → 66.00.
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
     * Block G (G5): if the stock is not enough, the checkout stops — asked
     * with the basket's lines, article and quantity.
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
     * G-5: "Renew licence" needs no code (Abo-7b) — the line does not ask the
     * stock.
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
        // CartView says so itself: the cart page offers removal,
        // the checkout does not go on as long as one is there.
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
     * Without an address the visitor has not named a country yet. Then the
     * basket computes in the shop's home country — otherwise there would be no
     * tax rate and the cart page would stand empty before anybody has typed
     * anything at all.
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
     * 🔴 C15: an unreachable VIES does not block. The visitor cannot fix an
     * outage in Brussels by waiting — he orders with tax and gets "Check
     * again".
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

        // The goods really are in — otherwise the equation below only checks
        // the shipping (as happened until C-12, see grossCalculator()).
        self::assertCount(1, $state->cart->lines);
        self::assertSame([], $state->cart->unpriced);
        self::assertTrue($state->isReady());

        // No rate, so gross equals net — on the line the overview shows as
        // well. Instead of a rate the invoice carries the reverse-charge
        // note.
        self::assertSame($state->cart->lines[0]->lineNet, $state->cart->lines[0]->lineGross);
        self::assertSame('55.00', $state->netTotal);
        self::assertSame('55.00', $state->grossTotal);
    }

    /**
     * The control case: the same delivery to Germany WITHOUT a VAT ID carries
     * the German 19 % — the rate exists, only reverse charge takes it away.
     */
    /**
     * C-12: without the shop's VAT ID the checkout computes with tax, even
     * with the customer's VAT ID checked — Art. 11 Abs. 2 UStG 1994 annex
     * (internal market).
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
        // Shipping follows the goods into the delivery country: 5.00 net at 19 %.
        self::assertSame('65.45', $state->grossTotal);
        self::assertTrue($state->taxAtDestination);
    }

    /**
     * Block R (O1, R2): without "tax in the country of destination" a distance
     * sale to a consumer in another EU country is taxed in the shop's country —
     * goods AND shipping at 20 %, as at home. Delivery still goes to Germany:
     * only the rate follows the switch.
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
     * The shipping methods that are not chosen also state their price at the
     * shop's rate — the list is what the customer compares.
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
     * Outside the EU there is no delivery threshold: the rate the operator
     * entered for the country applies with and without the switch.
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
        // § 3 UStG: reverse charge applies across borders, not in one's own
        // country. A valid Austrian VAT ID changes nothing about a delivery
        // to Austria.
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
        // C2: topmost = default; if it is not available, the next one moves
        // up. A choice that was made is never switched silently.
        $state = $this->calculate($this->vollstaendig(), shipping: [
            $this->versandart(1, 'Nicht hierhin', verfuegbar: false),
            $this->versandart(2, 'Paket', net: '7.0000'),
        ]);

        self::assertSame(2, $state->shipping?->id);
        self::assertCount(2, $state->shippingOptions);
        self::assertFalse($state->shippingOptions[0]->available);
    }

    /**
     * 🔴 An unavailable method does NOT disappear from the list. Whoever had
     * chosen it and then changes the country must see that it is gone — a
     * selection that simply dissolves looks like a bug.
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
     * A method whose module was uninstalled is not an error message, but
     * simply one way fewer.
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
        // C5: the amount carries an origin. A discount is negative.
        $state = $this->calculate($this->vollstaendig(), payment: [
            $this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount)),
        ]);

        self::assertSame(PaymentAdjustmentOrigin::Discount, $state->paymentAdjustment);

        // 50.00 goods + 5.00 shipping − 2.00 discount = 53.00 net.
        self::assertSame('-2.0000', $state->payment?->net);
        self::assertSame('53.00', $state->netTotal);
        self::assertSame('63.60', $state->grossTotal);
    }

    /**
     * WG8: a basket of gift cards only has no goods a discount could reduce —
     * the payment method stays, without a discount.
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
        // 50.00 in class 1 (20 %) and 50.00 in class 2 (10 %): the 10.00
        // shipping go 5.00/5.00, and the invoice gets two lines.
        $state = $this->calculate(
            $this->vollstaendig(),
            items: [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1)],
            shipping: [$this->versandart(1, 'Paket', net: '10.0000', mode: ShippingTaxMode::Proportional)],
        );

        self::assertCount(2, $state->shippingShares);
        self::assertSame('5.00', $state->shippingShares[0]->net, 'Auf den Stellen der Währung, nicht auf vier.');
        self::assertSame('6.0000', $state->shippingShares[0]->gross);
        self::assertSame('5.5000', $state->shippingShares[1]->gross);

        // 50 + 50 goods + 10 shipping net; gross 60 + 55 + 6 + 5.50.
        self::assertSame('110.00', $state->netTotal);
        self::assertSame('126.50', $state->grossTotal);
    }

    /**
     * C4 in one sentence: "applies likewise to an amount of the payment
     * method". A discount on a basket with two rates is split exactly like the
     * shipping — otherwise it would stand on the invoice with a rate nobody
     * can justify.
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

        // 100 goods + 5 shipping − 4 discount = 101.00 net.
        self::assertSame('101.00', $state->netTotal);
    }

    /**
     * E-1, Z3: whether a payment fee is an ancillary supply or a supply of its
     * own is not for Consho to decide. Without the operator's choice the
     * payment method is not offered at all — until E-1 the order only failed
     * at the click.
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

        // 100 goods + 5 shipping + 4 fee = 109.00 net.
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
     * Block R (R2): the fee follows the goods it comes with — to Germany
     * without "tax in the country of destination" at 20 % AT, with it at
     * 19 % DE.
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
     * E-3, Z2: cash on delivery exists only with a shipping method that
     * collects. The checkout passes on the capabilities of the CHOSEN shipping
     * method.
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
     * E-4, V1: the checkout gives the shipping method weight and dimensions per
     * piece, from the catalogue — until E-4 nobody read them.
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
     * F-1, F13: shipping is needed as soon as ONE item is physical — a book
     * with an e-book goes as a parcel. Digital items only: no parcel, but
     * electronic delivery (F-2).
     * Without a data source or for an unknown article: physical.
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
     * Nachlese E5b: the checkout tells the payment method whether digital
     * content or gift cards are in the basket — "on invoice" with the switch
     * on then drops out. A service does not count.
     */
    public function testAufRechnungNichtFuerSofortNutzbareGueter(): void
    {
        $rechnung = new PaymentMethod(1, 'invoice', 'Auf Rechnung', ['notHandedOutAtOnce' => '1']);

        // Article 7 — the test catalogue has a price only for it — in every kind.
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
     * Variant A: the checkout no longer blocks "full minimum term" for
     * consumers — who is offered it is decided by the rule's member groups,
     * already when adding.
     */
    public function testGleicheLaufzeitSperrtDenVerbraucherNichtMehr(): void
    {
        $verbraucher = $this->calculate($this->vollstaendig(), items: [new CartItemRow(1, 7, '1.0000', 1, 11)]);

        self::assertTrue($verbraucher->isReady());
        self::assertSame([], array_map(static fn (CheckoutProblem $problem): string => $problem->value, $verbraucher->problems));
    }

    /**
     * Abo-2, P14 (A12): the consent box follows the withdrawal kinds of the
     * digital lines — article 7 digital content, 8 service, 9 physical.
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
     * WG3: a gift card is delivered electronically, but requires no consent
     * to early performance — nothing begins early, the code is money.
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
     * F-2, F14: digital items only — the shipping step stays and offers
     * electronic delivery: one option, without costs, even without a shipping
     * method set up. A parcel shipping method chosen before is not "gone", it
     * just does not apply. The age check runs as for a shipping method without
     * a check at the door: only age-verified groups.
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
     * E-5: the core asks countries (V3) and bulky goods (V4) before the type
     * is asked — a shipping method only for Germany is not available in
     * Austria, one with 100 cm side length not for 120 cm.
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
     * E-6, V6: if the parcel service of the chosen shipping method needs a
     * phone number, the checkout asks for it — otherwise not.
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
     * 🔴 Shipping AND discount together. An earlier version used the same
     * variable for both, and the payment stage overwrote the shipping shares —
     * the total then happened to be almost right.
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
     * 🔴 A shipping of 4.95 on two tax classes in equal parts gave 2.4750 per
     * line — on the invoice twice 2.48, together 4.96. The visitor had read
     * 4.95.
     *
     * Since then the splitter divides on the currency's decimal places, and
     * the sum of the lines is exactly the amount stated.
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
     * 🔴 The gross of the shipping option came from the shipping method's tax
     * class. With "proportional", according to 5.1 the class only serves to
     * convert a gross entry — taken as a rate it gave 5.94 on the option,
     * while the lines together gave 5.6925.
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

        // 5.00 at 20 % and 5.00 at 10 % are 11.50 — not 12.00, as the
        // shipping method's class alone would give.
        self::assertSame('11.5000', $state->shipping->gross);
    }

    /**
     * The groups travel as a list all the way into the shipping context. A
     * module that offers "retailers pay no shipping" sees the same groups the
     * price was computed with.
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
     * The age check hangs in the shipping stage, because one of its two
     * answers is the shipping method. It speaks up before one has been chosen
     * at all — a basket nobody may buy should not say so only after three
     * steps.
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
        // 6, 12, 14 are a notice according to C19. The basket says so, the
        // checkout lets it through.
        $state = $this->calculate($this->vollstaendig(), minimumAge: 12);

        self::assertTrue($state->isReady());
        self::assertSame(12, $state->minimumAge);
    }

    /**
     * The same state computed twice gives the same result — otherwise the
     * purchase click (7.3) could not hold the form's checksum against a fresh
     * calculation.
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
    // ── Block H (H-4): cart rule in the checkout ──────────────────────────

    /**
     * The payment method checks its limit against what is to be paid — after
     * the discount: 50 goods − 10 % + 5 shipping = 50 net.
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
     * H: if the chosen shipping method costs nothing, the code saves nothing —
     * it does not apply and is not used up (the purchase click only redeems
     * what the state carries).
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
     * H: if the chosen method costs nothing, the others still stand at 0 —
     * chosen, they would be too. The code is not used up.
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
     * Two rules that deduct the same: the same totals, a different discount.
     * The fingerprint has to know it, otherwise the purchase freezes a line
     * that did not stand above the button.
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

    // ── Gift cards (WG-5) ─────────────────────────────────────────────────
    // Article 7: 50 net at 20 % = 60.00; parcel 5 net at 20 % = 6.00.

    /**
     * E1: if the gift card covers everything, there is nothing to collect —
     * payment method "gift card", alone, even if another one was chosen before.
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
     * E1 without a payment method means: also without its fee — the fee only
     * exists where a payment method collects.
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
     * E2: with partial coverage, fee and limits stay on the whole order; the
     * gift cards then cover the total including the fee, in the order they
     * were entered.
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
        // 60 + 6 + 4.80 fee
        self::assertSame('70.80', $state->grossTotal);
        self::assertSame(['20.00', '30.00'], array_map(static fn ($v): string => $v->applied, $state->vouchers));
        self::assertSame('20.80', $state->toPay());
        $this->assertBilanz($state);
    }

    /**
     * WG-7:
     * 66 order, 64 gift card, prepayment with a discount of 2 — without the
     * rule the discount lowered it to 63.60 and the gift card paid everything.
     * Now: no discount, prepayment collects 2.
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

        // Without a gift card the discount stays.
        $ohne = $this->calculate($this->vollstaendig(), payment: [$this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount))]);
        self::assertSame(PaymentAdjustmentOrigin::Discount, $ohne->paymentAdjustment);

        // A gift card that pays nothing (blocked) does not take the discount.
        $gesperrt = $this->calculate(
            $this->vollstaendig(),
            payment: [$this->zahlungsart(1, 'Vorkasse', adjustment: new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount))],
            vouchers: $this->gutscheine(['AAAAAAAAAAAAAAAA' => '64.0000'], blocked: true),
            voucherCodes: ['AAAAAAAAAAAAAAAA'],
        );
        self::assertSame(PaymentAdjustmentOrigin::Discount, $gesperrt->paymentAdjustment);
    }

    /**
     * With a subscription line the payment method stays: it carries the
     * follow-up payments. The gift card still covers the first one.
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
     * WG8: money is not bought with a gift card — the gift card line stays
     * open, only the rest is covered.
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

        // 60 gift card line + 55 goods + parcel: only goods and parcel covered.
        self::assertFalse($state->coveredByVouchers);
        self::assertSame(1, $state->payment?->id);
        self::assertSame('60.00', $state->toPay());
        $this->assertBilanz($state);
    }

    /**
     * E4: if a gift card pays less between page and click (redeemed
     * elsewhere), it is a different agreement — the checksum says so.
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
     * E4: blocked between page and click — the gift card pays nothing any
     * more, the coverage tips over, the payment method is a real one again. A
     * different agreement, so a different checksum (WG-7).
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
     * What PurchaseSettlement has to see (WG-7): the gift cards pay what they
     * show, the payment method the rest — together exactly the total that
     * goes onto the order.
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
                // The real cash on delivery (E-3): it asks the shipping method, and
                // exactly this question is what the checkout is meant to answer.
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
     * Article 7 is in tax class 1 (20 %), article 8 in class 2 (10 %), both
     * 50.00 net.
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
             * Germany with rates of its own since C-12. Before, the double only
             * knew AT: an article delivered to DE found no rate, counted as
             * "without a price" and dropped out of the basket — and the
             * reverse-charge test checked net = gross on a basket in which only
             * the shipping was left. On a real installation the checkout computed
             * the goods at 19 % and every such order failed.
             */
            public function findRate(int $taxClassId, string $countryCode, int $timestamp): string|null
            {
                return match ($countryCode) {
                    'AT' => 1 === $taxClassId ? '20.0000' : '10.0000',
                    'DE' => 1 === $taxClassId ? '19.0000' : '7.0000',
                    // Block R: a third country the operator keeps at 0 % (export).
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
 * Outside the test class, because an anonymous class cannot carry a static
 * method of an interface with `getType()` without PHPUnit instantiating it
 * while collecting — and because PaymentHandlers asks the type statically.
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
