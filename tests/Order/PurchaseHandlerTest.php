<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Cart\CartView;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodOption;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingShare;
use Webwerkwien\ContaoConshoBundle\Order\OrderDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;
use Webwerkwien\ContaoConshoBundle\Order\OrderPlacerInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentMethodGoneAtPurchase;
use Webwerkwien\ContaoConshoBundle\Order\PlacedOrder;
use Webwerkwien\ContaoConshoBundle\Order\PurchaseHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutCalculator;
use Webwerkwien\ContaoConshoBundle\Voucher\AppliedVoucher;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherStatus;

/**
 * The translation of the computed checkout into an order.
 *
 * ⚠️ **Why this test exists:** `PurchaseHandler` was the only new class
 * from C-7 without a test of its own — mocked in the controller test, not
 * touched in the placer test. Swapping language and fallback language, or
 * `$page->id` instead of `rootId`, would have passed green and fetched the
 * legal texts from the wrong root page.
 *
 * The placer here is a double that records the draft and the lines:
 * the TRANSLATION is checked, not the writing.
 */
class PurchaseHandlerTest extends TestCase
{
    private OrderDraft|null $draft = null;

    /** @var list<OrderLineDraft> */
    private array $lines = [];

    private PaymentMethod|null $method = null;

    public function testDerEntwurfTraegtDieSummeAufDieDerKundeGeklicktHat(): void
    {
        // 🔴 The belt next to the checksum's braces. Without this field OrderFactory
        // writes any total it computes itself — and exactly there C-7 once
        // failed. The field is optional, so a test has to record that the
        // purchase click fills it.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(), 1_757_000_000);

        self::assertNotNull($this->draft);
        self::assertSame('13.90', $this->draft->expectedGrandTotal);
    }

    /**
     * E-6, V6: the phone number for the parcel service only goes onto the
     * order when the chosen shipping method needs it — data minimisation, no
     * field "just in case".
     */
    public function testDasTelefonNurWennDerPaketdienstEsBraucht(): void
    {
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(phone: '+436641234567', needsPhone: true), 1_757_000_000);
        self::assertNotNull($this->draft);
        self::assertSame('+436641234567', $this->draft->shippingPhone);

        $this->draft = null;
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(phone: '+436641234567', needsPhone: false), 1_757_000_000);
        self::assertNotNull($this->draft);
        self::assertSame('', $this->draft->shippingPhone, 'getippt, aber nicht gebraucht: nicht gespeichert');
    }

    /**
     * F-3: the consent only goes onto the order where it was asked for —
     * digital items and a consumer. A business customer is frozen as such.
     */
    public function testZustimmungNurWoGefragt(): void
    {
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(digitalConsentAt: 1_757_000_000, hasDigitalLines: true), 1_757_000_000);
        self::assertNotNull($this->draft);
        self::assertSame(1_757_000_000, $this->draft->digitalConsentAt);
        self::assertFalse($this->draft->businessCustomer);

        $this->draft = null;
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(digitalConsentAt: 1_757_000_000, hasDigitalLines: true), 1_757_000_000, '', true);
        self::assertNotNull($this->draft);
        self::assertNull($this->draft->digitalConsentAt, 'einen Geschäftskunden fragt niemand');
        self::assertTrue($this->draft->businessCustomer);

        $this->draft = null;
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(digitalConsentAt: 1_757_000_000), 1_757_000_000);
        self::assertNotNull($this->draft);
        self::assertNull($this->draft->digitalConsentAt, 'ohne digitale Position kein Verzicht');

        $this->draft = null;
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(hasDigitalLines: true), 1_757_000_000);
        self::assertNotNull($this->draft);
        self::assertNull($this->draft->digitalConsentAt, 'nicht angehakt');
    }

    public function testSpracheUndRueckfallLandenNichtVertauschtImEntwurf(): void
    {
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(), 1_757_000_000);

        self::assertNotNull($this->draft);
        self::assertSame('de', $this->draft->language);
        self::assertSame('en', $this->draft->fallbackLanguage);

        // The root page, not the page: the legal texts hang on it.
        self::assertSame(1, $this->draft->shopId);
        self::assertSame('AT', $this->draft->shopCountry);

        // And the payment method comes in the order's language: its
        // `instructions` are the payment note the customer reads.
        self::assertNotNull($this->method);
        self::assertSame(6, $this->method->id);
        self::assertSame('prepayment', $this->method->type);

        // C-9: frozen on the draft, so that the confirmation has it even without
        // an invoice.
        self::assertSame('<p>Binnen 7 Tagen.</p>', $this->draft->paymentInstructions);
    }

    public function testJederVersandanteilWirdEineEigeneZeile(): void
    {
        // Proportional shipping tax splits ONE shipping method over two tax
        // rates (ShippingTaxSplitter, C-5). An invoice has to show both parts at
        // their rate, so they become two lines — with the same title
        // and the same origin.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(shippingShares: [
            new ShippingShare(1, '2.3967'),
            new ShippingShare(2, '1.7700'),
        ]), 1_757_000_000);

        $versand = array_values(array_filter($this->lines, static fn (OrderLineDraft $l): bool => OrderLineType::Shipping === $l->type));

        self::assertCount(2, $versand);
        self::assertSame('2.3967', $versand[0]->net);
        self::assertSame(1, $versand[0]->taxClassId);
        self::assertSame('1.7700', $versand[1]->net);
        self::assertSame(2, $versand[1]->taxClassId);

        foreach ($versand as $zeile) {
            self::assertSame('Paket', $zeile->title);
            self::assertSame(4, $zeile->source, 'Beide Teile kommen aus derselben Versandart.');
        }
    }

    public function testDasModellDerZeileGehtMitInDieBestellung(): void
    {
        // Abo-2 (P13): without the rule OrderFactory would look up the one-off
        // price, and the total would contradict the checkout.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(subscriptionRule: 5), 1_757_000_000);

        $ware = array_values(array_filter($this->lines, static fn (OrderLineDraft $l): bool => OrderLineType::Product === $l->type));

        self::assertSame(5, $ware[0]->subscriptionRule);
    }

    public function testDieVerlaengerungGehtMitInDieBestellung(): void
    {
        // Abo-7b: without `renews` a new subscription with a new
        // entitlement would be created instead of the renewal.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(subscriptionRule: 5, renews: 31), 1_757_000_000);

        $ware = array_values(array_filter($this->lines, static fn (OrderLineDraft $l): bool => OrderLineType::Product === $l->type));

        self::assertSame([5, 31], [$ware[0]->subscriptionRule, $ware[0]->renews]);
    }

    public function testEinNachlassBehaeltSeinVorzeichen(): void
    {
        // The sign comes from the checkout. Flipping it here once more
        // would turn the discount into a surcharge — and the total would
        // still be right.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(
            paymentShares: [new ShippingShare(1, '-0.1000')],
            adjustment: PaymentAdjustmentOrigin::Discount,
        ), 1_757_000_000);

        $zahlung = array_values(array_filter($this->lines, static fn (OrderLineDraft $l): bool => OrderLineType::PaymentDiscount === $l->type));

        self::assertCount(1, $zahlung);
        self::assertSame('-0.1000', $zahlung[0]->net);
    }

    public function testEineGebuehrWirdAlsGebuehrUebersetzt(): void
    {
        // Translated, not refused: the decision that a fee cannot be
        // taxed is made in OrderFactory. This handler
        // translates, it does not judge.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(
            paymentShares: [new ShippingShare(1, '3.0000')],
            adjustment: PaymentAdjustmentOrigin::Fee,
        ), 1_757_000_000);

        $zahlung = array_values(array_filter($this->lines, static fn (OrderLineDraft $l): bool => OrderLineType::PaymentFee === $l->type));

        self::assertCount(1, $zahlung);
    }

    /**
     * E1: fully covered, the payment method is "gift card", not a record — and
     * the gift cards go into the draft with what the page showed.
     */
    public function testVollGedecktZahltMitGutschein(): void
    {
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(
            payment: new MethodOption(CheckoutCalculator::PAYMENT_BY_VOUCHER, 'Gutschein', true),
            vouchers: [new AppliedVoucher(3, 'AAAABBBBCCCCDDDD', VoucherStatus::Usable, '20.0000', '13.90', '6.1000'), new AppliedVoucher(4, 'EEEEFFFFGGGGHHHH', VoucherStatus::Usable, '5.0000', '0.00', '5.0000')],
            covered: true,
        ), 1_757_000_000);

        self::assertSame(CheckoutCalculator::PAYMENT_BY_VOUCHER, $this->method?->id);
        self::assertSame('Gutschein', $this->method->title);
        self::assertSame(0, $this->draft?->paymentMethod);
        self::assertSame('', $this->draft->paymentInstructions);
        // Only those that pay.
        self::assertSame([['id' => 3, 'code' => 'AAAABBBBCCCCDDDD', 'amount' => '13.90']], $this->draft->vouchers);
    }

    /**
     * Payment method 0 without coverage is a crafted form — the same answer
     * as for any payment method that has disappeared.
     */
    public function testGutscheinZahlungOhneDeckungWirdAbgelehnt(): void
    {
        $this->expectException(PaymentMethodGoneAtPurchase::class);

        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(
            payment: new MethodOption(CheckoutCalculator::PAYMENT_BY_VOUCHER, 'Gutschein', true),
        ), 1_757_000_000);
    }

    public function testOhneZahlungsartEntstehtKeineBestellung(): void
    {
        $this->expectException(PaymentMethodGoneAtPurchase::class);

        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(payment: null), 1_757_000_000);
    }

    public function testEineZahlungsartDieDerShopNichtMehrFuehrtWirdAbgelehnt(): void
    {
        // The shop carries method 6, the state names method 99 — unpublished
        // or deleted between display and click.
        $this->expectException(PaymentMethodGoneAtPurchase::class);

        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(
            payment: new MethodOption(99, 'Weg', true),
        ), 1_757_000_000);
    }

    /**
     * @param list<ShippingShare> $shippingShares
     * @param list<ShippingShare> $paymentShares
     */
    private function state(
        array $shippingShares = [],
        array $paymentShares = [],
        PaymentAdjustmentOrigin|null $adjustment = null,
        MethodOption|null $payment = new MethodOption(6, 'Vorkasse', true),
        string $phone = '',
        bool $needsPhone = false,
        int $digitalConsentAt = 0,
        bool $hasDigitalLines = false,
        int $subscriptionRule = 0,
        int $renews = 0,
        array $vouchers = [],
        bool $covered = false,
    ): CheckoutState {
        $cart = new CartView(
            [new CartLine(1, 9, 9, '1.0000', '7.4167', '7.4167', '8.9000', 1, $subscriptionRule, $renews)],
            [],
            '1.0000',
            '7.42',
            '8.90',
            'EUR',
        );

        return new CheckoutState(
            $cart,
            new CheckoutData(
                billing: new Address(street: 'Hauptstraße 5', postal: '1100', city: 'Wien', country: 'AT'),
                shippingPhone: $phone,
                digitalConsentAt: $digitalConsentAt,
            ),
            true,
            [],
            [],
            new MethodOption(4, 'Paket', true),
            $payment,
            $shippingShares,
            $paymentShares,
            $adjustment,
            '11.59',
            '13.90',
            'EUR',
            false,
            0,
            [],
            $needsPhone,
            false,
            $hasDigitalLines,
            vouchers: $vouchers,
            coveredByVouchers: $covered,
        );
    }

    /**
     * The placer is a double that records what the handler gives it.
     *
     * `OrderPlacer` is final; the seam is `OrderPlacerInterface` — the same
     * convention as for `CartConversionInterface`, `OrderFactoryInterface`
     * and `DocumentIssuerInterface`, and for the same reason: the handler
     * needs exactly one method, not a transaction.
     */
    private function handler(): PurchaseHandler
    {
        $methods = $this->createMock(PaymentMethodRepositoryInterface::class);
        $methods->method('forShop')->with(1, 'de', 'en')->willReturn([new PaymentMethod(6, 'prepayment', 'Vorkasse', ['instructions' => '<p>Binnen 7 Tagen.</p>'])]);

        $placer = $this->createMock(OrderPlacerInterface::class);
        $placer->method('place')->willReturnCallback(
            function (int $cartId, int $rootId, OrderDraft $draft, array $lines, PaymentMethod $method): PlacedOrder {
                $this->draft = $draft;
                $this->lines = $lines;
                $this->method = $method;

                return new PlacedOrder(77, str_repeat('a', 64));
            },
        );

        return new PurchaseHandler($placer, $methods);
    }
}
