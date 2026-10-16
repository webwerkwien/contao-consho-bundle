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
 * Die Übersetzung vom gerechneten Checkout in eine Bestellung.
 *
 * ⚠️ **Warum es diesen Test gibt:** `PurchaseHandler` war bis zum 2026-09-23
 * die einzige neue Klasse aus C-7 ohne eigenen Test — im Controller-Test
 * gemockt, im Placer-Test nicht berührt. Gefunden im Fable-Review (F11).
 * Ein Vertauschen von Sprache und Rückfallsprache oder `$page->id` statt
 * `rootId` wäre grün durchgelaufen und hätte die Rechtstexte vom falschen
 * Startpunkt geholt.
 *
 * Der Placer ist hier ein Double, das den Entwurf und die Zeilen mitschreibt:
 * geprüft wird die ÜBERSETZUNG, nicht das Schreiben.
 */
class PurchaseHandlerTest extends TestCase
{
    private OrderDraft|null $draft = null;

    /** @var list<OrderLineDraft> */
    private array $lines = [];

    private PaymentMethod|null $method = null;

    public function testDerEntwurfTraegtDieSummeAufDieDerKundeGeklicktHat(): void
    {
        // 🔴 Der Gürtel zur Prüfsumme. Ohne dieses Feld schreibt OrderFactory
        // jede Summe, die sie selbst errechnet — und genau daran ist C-7 im
        // Review gescheitert (F1). Das Feld ist optional, also muss ein Test
        // festhalten, dass der Kaufklick es füllt.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(), 1_757_000_000);

        self::assertNotNull($this->draft);
        self::assertSame('13.90', $this->draft->expectedGrandTotal);
    }

    /**
     * E-6, V6: Die Telefonnummer für den Paketdienst kommt nur an die
     * Bestellung, wenn die gewählte Versandart sie braucht —
     * Datensparsamkeit, kein Feld „für alle Fälle".
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
     * F-3: Die Zustimmung kommt nur an die Bestellung, wo nach ihr gefragt
     * wurde — digitale Positionen und ein Verbraucher. Ein Geschäftskunde
     * wird als solcher eingefroren.
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

        // Der Startpunkt, nicht die Seite: an ihm hängen die Rechtstexte.
        self::assertSame(1, $this->draft->shopId);
        self::assertSame('AT', $this->draft->shopCountry);

        // Und die Zahlungsart kommt in der Sprache der Bestellung: ihr
        // `instructions` ist der Zahlungshinweis, den der Kunde liest.
        self::assertNotNull($this->method);
        self::assertSame(6, $this->method->id);
        self::assertSame('prepayment', $this->method->type);

        // C-9: eingefroren am Entwurf, damit die Bestätigung ihn auch ohne
        // Rechnung hat (Michael, 2026-09-25).
        self::assertSame('<p>Binnen 7 Tagen.</p>', $this->draft->paymentInstructions);
    }

    public function testJederVersandanteilWirdEineEigeneZeile(): void
    {
        // Anteilige Versandsteuer teilt EINE Versandart auf zwei Steuersätze
        // auf (ShippingTaxSplitter, C-5). Eine Rechnung muss beide Teile mit
        // ihrem Satz zeigen, also werden es zwei Zeilen — mit demselben Titel
        // und derselben Herkunft.
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
        // Abo-2 (P13): Ohne die Regel schlüge OrderFactory den Einmalkauf-Preis
        // nach, und die Summe widerspräche der Kasse.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(subscriptionRule: 5), 1_757_000_000);

        $ware = array_values(array_filter($this->lines, static fn (OrderLineDraft $l): bool => OrderLineType::Product === $l->type));

        self::assertSame(5, $ware[0]->subscriptionRule);
    }

    public function testDieVerlaengerungGehtMitInDieBestellung(): void
    {
        // Abo-7b (Review L7): ohne `renews` entstünde ein neues Abo mit neuer
        // Berechtigung statt der Verlängerung.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(subscriptionRule: 5, renews: 31), 1_757_000_000);

        $ware = array_values(array_filter($this->lines, static fn (OrderLineDraft $l): bool => OrderLineType::Product === $l->type));

        self::assertSame([5, 31], [$ware[0]->subscriptionRule, $ware[0]->renews]);
    }

    public function testEinNachlassBehaeltSeinVorzeichen(): void
    {
        // Das Vorzeichen kommt aus dem Checkout. Es hier noch einmal zu
        // drehen machte aus dem Nachlass einen Aufschlag — und die Summe
        // stimmte trotzdem.
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
        // Übersetzt, nicht abgelehnt: Die Entscheidung, dass eine Gebühr nicht
        // versteuert werden kann, fällt in OrderFactory. Dieser Handler
        // übersetzt, er urteilt nicht.
        $this->handler()->place(12, 5, 1, 'AT', 'de', 'en', 0, [], $this->state(
            paymentShares: [new ShippingShare(1, '3.0000')],
            adjustment: PaymentAdjustmentOrigin::Fee,
        ), 1_757_000_000);

        $zahlung = array_values(array_filter($this->lines, static fn (OrderLineDraft $l): bool => OrderLineType::PaymentFee === $l->type));

        self::assertCount(1, $zahlung);
    }

    /**
     * E1: voll gedeckt ist die Zahlungsart „Gutschein", kein Datensatz — und
     * die Gutscheine gehen mit dem, was die Seite zeigte, in den Entwurf.
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
        // Nur, wer zahlt.
        self::assertSame([['id' => 3, 'code' => 'AAAABBBBCCCCDDDD', 'amount' => '13.90']], $this->draft->vouchers);
    }

    /**
     * Zahlungsart 0 ohne Deckung ist ein gebasteltes Formular — dieselbe
     * Antwort wie für jede verschwundene Zahlungsart.
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
        // Der Shop führt Art 6, der Zustand nennt Art 99 — zwischen Anzeige
        // und Klick unveröffentlicht oder gelöscht.
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
     * Der Placer ist ein Double, das mitschreibt, was der Handler ihm gibt.
     *
     * `OrderPlacer` ist final; die Naht ist `OrderPlacerInterface` — dieselbe
     * Konvention wie bei `CartConversionInterface`, `OrderFactoryInterface`
     * und `DocumentIssuerInterface`, und aus demselben Grund: Der Handler
     * braucht genau eine Methode, nicht eine Transaktion.
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
