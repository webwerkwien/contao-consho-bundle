<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Contao\CoreBundle\Intl\Countries;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Cart\CartItemRow;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CodeStatus;
use Webwerkwien\ContaoConshoBundle\Checkout\AgeGate;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutCalculator;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingTaxSplitter;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeAllocator;
use Webwerkwien\ContaoConshoBundle\Order\OrderDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderFactory;
use Webwerkwien\ContaoConshoBundle\Order\OrderPlacerInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderProductRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderWriterInterface;
use Webwerkwien\ContaoConshoBundle\Order\PlacedOrder;
use Webwerkwien\ContaoConshoBundle\Order\PurchaseHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustment;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\InMemoryPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRow;
use Webwerkwien\ContaoConshoBundle\Pricing\ProductConditions;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethod;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypeInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingQuote;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Checkout\PaymentHandlerStub;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherRedemption;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;
use Webwerkwien\ContaoConshoBundle\Voucher\CoverablePots;

require_once __DIR__.'/../Checkout/CheckoutCalculatorTest.php';

/**
 * Checkout and order, against each other (C-12).
 *
 * Until C-12 each side only checked itself: the controller test mocks the
 * purchase, the purchase test mocks the placer. That is how the bug that
 * made every reverse-charge order fail survived from C-7 onwards. Here the
 * REAL CheckoutCalculator, the REAL PurchaseHandler and the REAL
 * OrderFactory run over the same price and tax sources — the order has to
 * arrive at the amount above the button, otherwise OrderFactory throws
 * TotalDisagreesWithCheckout.
 *
 * The basket is deliberately awkward: two tax classes, a net price with
 * four decimal places and quantity 3, proportional shipping over both
 * rates, a discount of the payment method.
 */
class CheckoutAgreesWithOrderTest extends TestCase
{
    private const NOW = 1_757_000_000;

    /** @var list<array<string, mixed>> */
    private array $orders = [];

    /** @var list<array<string, mixed>> */
    private array $items = [];

    /** @var list<OrderDraft> */
    private array $drafts = [];

    /**
     * DL4: the kind per product — 7 tea, 8 book, 9 consulting package.
     *
     * @var array<int, string>
     */
    private array $kinds = [7 => 'physical', 8 => 'physical', 9 => 'service'];

    /**
     * @return iterable<string, array{string, bool, string, bool}>
     */
    public static function faelle(): iterable
    {
        // Delivery country, customer's VAT ID checked, shop's VAT ID, reverse charge expected
        yield 'Inland' => ['AT', false, 'ATU12345678', false];
        yield 'Deutschland ohne UID' => ['DE', false, 'ATU12345678', false];
        yield 'Deutschland mit UID: Reverse Charge' => ['DE', true, 'ATU12345678', true];
        yield 'Deutschland mit UID, Shop ohne UID' => ['DE', true, '', false];
    }

    /**
     * @dataProvider faelle
     */
    public function testDieBestellungKommtAufDenBetragUeberDemKnopf(string $country, bool $verifiedVatId, string $shopVatId, bool $reverseCharge): void
    {
        $state = $this->state($country, $verifiedVatId, $shopVatId);

        self::assertTrue($state->isReady(), 'der Checkout muss kaufbereit sein, sonst prüft dieser Test nichts');
        // Since Abo-2 with a subscription line of the same book: checkout and order
        // must both take the rule's price, not the one-off purchase.
        self::assertCount(3, $state->cart->lines);
        self::assertSame([], $state->cart->unpriced);
        self::assertSame($reverseCharge, $state->reverseCharge);

        // If the order summed differently, OrderFactory would throw here.
        $this->purchase($state, $shopVatId);

        self::assertCount(1, $this->orders);
        self::assertSame($state->grossTotal, $this->orders[0]['grandTotal']);
        self::assertSame($state->netTotal, $this->orders[0]['netTotal']);

        $abo = array_values(array_filter($this->items, static fn (array $item): bool => 5 === ($item['subscriptionRule'] ?? 0)));
        self::assertCount(1, $abo);
        self::assertSame('4.1667', $abo[0]['unitPrice']);

        if ($reverseCharge) {
            self::assertSame($state->netTotal, $state->grossTotal);
            self::assertSame(['0.0000'], array_values(array_unique(array_column($this->items, 'taxRate'))));
        }
    }

    /**
     * DL4 — the whole path instead of a single part: AT shop, German
     * consumer, goods + e-book + consulting package. The goods and the e-book
     * carry German tax, the service Austrian — in the checkout as in the
     * order, otherwise OrderFactory would throw.
     */
    public function testDieDienstleistungTraegtDieSteuerDesShops(): void
    {
        $this->kinds[8] = 'digital';
        $state = $this->state('DE', false, 'ATU12345678', $this->dreiArten(), taxAtDestination: true);

        self::assertTrue($state->isReady());
        // Checkout: 100.00 net consulting at 20 % AT, not 19 % DE.
        self::assertSame('120.0000', $state->cart->lines[2]->lineGross);
        // DL: the line carries its country on — the condition text of the
        // review page (CheckoutController::reviewLines) relies on it, as does
        // OrderFactory when freezing.
        self::assertSame(['DE', 'DE', 'AT'], array_map(static fn ($line): string => $line->taxCountry, $state->cart->lines));

        $this->purchase($state, 'ATU12345678');

        $products = array_values(array_filter($this->items, static fn (array $item): bool => 'product' === $item['lineType']));
        self::assertSame(['DE', 'DE', 'AT'], array_column($products, 'taxCountry'));
        self::assertSame(['19.0000', '7.0000', '20.0000'], array_column($products, 'taxRate'));
        self::assertSame(['physical', 'digital', 'service'], array_column($products, 'kind'));
        self::assertSame($state->grossTotal, $this->orders[0]['grandTotal']);

        // Shipping follows the delivery; the discount follows the country of the
        // service it reduces (H) — so in class 1 also AT.
        $shipping = array_filter($this->items, static fn (array $item): bool => 'shipping' === $item['lineType']);
        self::assertSame(['DE'], array_values(array_unique(array_column($shipping, 'taxCountry'))));
        self::assertSame(['1|AT', '1|DE', '2|DE'], $this->keysOf('paymentDiscount'));
    }

    /**
     * Block R (O1, R2, R4): without "tax in the country of destination"
     * everything that travels to Germany is a distance sale below the
     * threshold — goods at 20 % and 10 % AT, shipping with them; the service
     * is AT anyway. Checkout and order compute the same, and every line
     * records where the supply went.
     */
    public function testOhneSchalterVersteuertDerShopImEigenenLand(): void
    {
        $this->kinds[8] = 'digital';
        $state = $this->state('DE', false, 'ATU12345678', $this->dreiArten());

        self::assertTrue($state->isReady());
        self::assertSame(['AT', 'AT', 'AT'], array_map(static fn ($line): string => $line->taxCountry, $state->cart->lines));

        $this->purchase($state, 'ATU12345678');

        $products = array_values(array_filter($this->items, static fn (array $item): bool => 'product' === $item['lineType']));
        self::assertSame(['20.0000', '10.0000', '20.0000'], array_column($products, 'taxRate'));
        self::assertSame($state->grossTotal, $this->orders[0]['grandTotal']);

        $shipping = array_filter($this->items, static fn (array $item): bool => 'shipping' === $item['lineType']);
        self::assertSame(['AT'], array_values(array_unique(array_column($shipping, 'taxCountry'))));
        self::assertSame(['DE'], array_values(array_unique(array_column($this->items, 'destinationCountry'))));
    }

    /**
     * H: discount and prepayment discount on a service, customer in DE. Tea
     * (DE 19 %) and consulting (AT 20 %) share class 1, but not the country —
     * a discount reduces the consideration of each supply at its own rate.
     * 10 %: tea 59.50 → −5.95 (net 5.00, DE), consulting 120.00 → −12.00
     * (net 10.00, AT); together exactly what the customer reads.
     */
    public function testRabattUndNachlassFolgenDemLandDerLeistung(): void
    {
        $connection = \Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_cart_rule', 'tl_consho_cart_rule_redemption', 'tl_consho_product', 'tl_consho_product_property'] as $table) {
            \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($connection, $table);
        }

        $connection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Herbstrabatt', 'code' => 'Herbst', 'published' => 1, 'effect' => 'percent', 'percent' => '10']);

        $this->kinds[8] = 'digital';
        $state = $this->state('DE', false, 'ATU12345678', $this->dreiArten(), 'Herbst', new CartDiscounts($connection, new ProductConditions($connection)), taxAtDestination: true);

        self::assertTrue($state->isReady());

        $this->purchase($state, 'ATU12345678');
        self::assertSame($state->grossTotal, $this->orders[0]['grandTotal']);

        $discount = $this->linesOf('discount');
        self::assertSame([['1', 'DE', '19.0000', '-5.0000', '-5.9500'], ['1', 'AT', '20.0000', '-10.0000', '-12.0000']], array_values(array_filter($discount, static fn (array $line): bool => '1' === $line[0])));

        // The prepayment discount likewise: in class 1 one line per country, at the rate of the supply.
        $payment = array_values(array_filter($this->linesOf('paymentDiscount'), static fn (array $line): bool => '1' === $line[0]));
        self::assertSame(['AT', 'DE'], array_column($payment, 1), 'der größere Anteil zuerst');
        self::assertSame(['20.0000', '19.0000'], array_column($payment, 2));

        // And the goods lines carry their share of the discount.
        $products = array_values(array_filter($this->items, static fn (array $item): bool => 'product' === $item['lineType']));
        self::assertSame(['5.0000', '10.0000'], [$products[0]['discountNet'], $products[2]['discountNet']]);
    }

    /**
     * H (W2): a customer of the net group reads net — €7.77 come off net, and
     * the order's discount lines add up to exactly that.
     */
    public function testFuerNettoKundenGehtDerBetragNettoBisInDieBestellung(): void
    {
        $connection = \Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_cart_rule', 'tl_consho_cart_rule_redemption', 'tl_consho_product', 'tl_consho_product_property'] as $table) {
            \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($connection, $table);
        }

        $connection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Herbstrabatt', 'code' => 'Herbst', 'published' => 1, 'effect' => 'amount', 'amount' => '7.77', 'currency' => 'EUR']);

        $state = $this->state('AT', false, 'ATU12345678', null, 'Herbst', new CartDiscounts($connection, new ProductConditions($connection)), true);

        self::assertSame(CodeStatus::Applied, $state->codeStatus);
        self::assertSame('7.77', (string) BigDecimal::of($state->discount?->displayed() ?? '0')->toScale(2));

        $this->purchase($state, 'ATU12345678');
        self::assertSame($state->grossTotal, $this->orders[0]['grandTotal']);

        $net = BigDecimal::zero();

        foreach ($this->linesOf('discount') as $line) {
            $net = $net->plus($line[3]);
        }

        self::assertSame('-7.7700', (string) $net->toScale(4));
    }

    /**
     * @return list<string> "class|country" of the lines of a type, sorted
     */
    private function keysOf(string $type): array
    {
        $keys = array_map(static fn (array $line): string => $line[0].'|'.$line[1], $this->linesOf($type));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<array{string, string, string, string, string}> class, country, rate, net, gross
     */
    private function linesOf(string $type): array
    {
        return array_values(array_map(
            static fn (array $item): array => [(string) $item['taxClass'], (string) $item['taxCountry'], (string) $item['taxRate'], (string) $item['unitPrice'], (string) $item['lineTotal']],
            array_filter($this->items, static fn (array $item): bool => $type === $item['lineType']),
        ));
    }

    /**
     * Control case: business customer with a checked VAT ID — reverse charge
     * for the whole basket, also for the service (§ 3a Abs. 2 UStG, § 3a
     * Abs. 6 UStG 1994: to businesses, where the recipient runs his business).
     */
    public function testMitUidReverseChargeAuchFuerDieDienstleistung(): void
    {
        $this->kinds[8] = 'digital';
        $state = $this->state('DE', true, 'ATU12345678', $this->dreiArten());

        self::assertTrue($state->reverseCharge);

        $this->purchase($state, 'ATU12345678');

        self::assertSame(['0.0000'], array_values(array_unique(array_column($this->items, 'taxRate'))));
        self::assertSame($state->netTotal, $this->orders[0]['grandTotal']);
    }

    /**
     * @return list<CartItemRow>
     */
    /**
     * @return iterable<string, array{string, bool, string, bool, array<string, mixed>}>
     */
    public static function rabatte(): iterable
    {
        $effects = [
            '10 %' => ['effect' => 'percent', 'percent' => '10'],
            '7,77 €' => ['effect' => 'amount', 'amount' => '7.77', 'currency' => 'EUR'],
            'versandkostenfrei' => ['effect' => 'freeShipping'],
        ];

        foreach (self::faelle() as $case => $args) {
            foreach ($effects as $name => $rule) {
                yield $case.', '.$name => [...$args, $rule];
            }
        }
    }

    /**
     * Block H (H-4) — the whole path with a discount code: the order has to
     * arrive at the amount above the button, the discount lines are the
     * checkout's shares, and free shipping leaves no shipping line. Two tax
     * rates, a payment method discount on top, a subscription line the
     * discount never touches (H8).
     *
     * @dataProvider rabatte
     *
     * @param array<string, mixed> $rule
     */
    public function testMitRabattcodeKommtDieBestellungAufDenBetragUeberDemKnopf(string $country, bool $verifiedVatId, string $shopVatId, bool $reverseCharge, array $rule): void
    {
        $connection = \Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_cart_rule', 'tl_consho_cart_rule_redemption', 'tl_consho_product', 'tl_consho_product_property'] as $table) {
            \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($connection, $table);
        }

        $connection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Herbstrabatt', 'code' => 'Herbst', 'published' => 1, ...$rule]);

        $state = $this->state($country, $verifiedVatId, $shopVatId, null, 'Herbst', new CartDiscounts($connection, new ProductConditions($connection)));

        self::assertTrue($state->isReady());
        self::assertSame(CodeStatus::Applied, $state->codeStatus);
        self::assertSame($reverseCharge, $state->reverseCharge);

        $this->purchase($state, $shopVatId);

        self::assertSame($state->grossTotal, $this->orders[0]['grandTotal']);
        self::assertSame($state->netTotal, $this->orders[0]['netTotal']);
        // The placer counts the redemption on this draft (W5) — for free shipping too.
        self::assertSame([1, 'Herbst'], [$this->drafts[0]->discountRule, $this->drafts[0]->discountCode]);

        $discountLines = array_values(array_filter($this->items, static fn (array $item): bool => 'discount' === $item['lineType']));
        $shippingLines = array_values(array_filter($this->items, static fn (array $item): bool => 'shipping' === $item['lineType']));

        if ('freeShipping' === $rule['effect']) {
            self::assertSame([], $discountLines);
            self::assertSame([], $shippingLines, 'W4: die gewählte Versandart kostet 0');

            return;
        }

        self::assertNotSame([], $shippingLines);
        self::assertSame(
            array_map(static fn ($share): string => $share->net, $state->discountShares),
            array_column($discountLines, 'unitPrice'),
            'die Rabattzeilen sind die Anteile der Kasse, negativ',
        );
        self::assertSame(['Herbstrabatt (Herbst)'], array_values(array_unique(array_column($discountLines, 'productName'))));

        // H-5 (R1): the goods lines carry their share, and together exactly the discount line of their class.
        foreach ($discountLines as $discountLine) {
            $frozen = BigDecimal::zero();

            foreach ($this->items as $item) {
                if ('product' === $item['lineType'] && $item['taxClass'] === $discountLine['taxClass']) {
                    $frozen = $frozen->plus((string) $item['discountNet']);
                }
            }

            self::assertTrue($frozen->isEqualTo(BigDecimal::of((string) $discountLine['unitPrice'])->negated()), (string) $frozen);
        }
    }

    /**
     * Spec gift cards, open points (K16): checkout, purchase click and booking
     * were each tested on their own; that the gift card payments meet the
     * order total and the order is thereby paid, no test proved. Here the
     * whole path runs: real wallet in the calculator, real purchase, real
     * OrderFactory, real redemption, real balance.
     *
     * Nachlese K7: also a business customer under reverse charge — the gift
     * card then pays the net amount, which is also the order total.
     *
     * @return iterable<string, array{string, bool, string, bool}>
     */
    public static function gutscheine(): iterable
    {
        // Value of the gift card, does it cover the order fully? Delivery country, customer's VAT ID checked
        yield 'deckt alles' => ['1000', true, 'AT', false];
        yield 'deckt einen Teil' => ['20', false, 'AT', false];
        yield 'Reverse Charge, deckt alles' => ['1000', true, 'DE', true];
        yield 'Reverse Charge, deckt einen Teil' => ['20', false, 'DE', true];
    }

    /**
     * @dataProvider gutscheine
     */
    public function testGutscheineBezahlenDieBestellungVomRechnerBisZumSaldo(string $value, bool $covers, string $country, bool $verifiedVatId): void
    {
        $db = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_voucher', 'tl_consho_voucher_booking', 'tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($db, $table);
        }

        WithdrawalSchema::create($db);
        $db->insert('tl_consho_voucher', ['id' => 1, 'code' => 'AAAABBBBCCCCMDSN', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => $value, 'balance' => $value]);

        // Without a subscription line: above a subscription line a gift card never covers everything (E1).
        $state = $this->state($country, $verifiedVatId, 'ATU12345678', [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '3.0000', 1)], vouchers: new VoucherWallet($db), voucherCodes: ['AAAABBBBCCCCMDSN']);

        self::assertTrue($state->isReady());
        self::assertSame($verifiedVatId, $state->reverseCharge);

        if ($verifiedVatId) {
            self::assertSame($state->netTotal, $state->grossTotal, 'unter Reverse Charge ist netto die Summe');
        }
        self::assertSame($covers, $state->coveredByVouchers);
        self::assertSame($covers, CheckoutCalculator::PAYMENT_BY_VOUCHER === $state->payment?->id, 'ganz gedeckt: Zahlungsart „Gutschein"');

        $this->purchase($state, 'ATU12345678');

        $draft = $this->drafts[0];
        $grandTotal = (string) $this->orders[0]['grandTotal'];
        self::assertSame($state->grossTotal, $grandTotal);
        self::assertCount(1, $draft->vouchers);

        // The order as OrderFactory wrote it, into the redemption's database.
        $db->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-00001', 'shop' => 1, 'grandTotal' => $grandTotal]);
        (new VoucherRedemption($db, new VoucherLedger($db), new VoucherWallet($db)))->redeem(1, $draft, self::NOW);

        $balance = (new OrderBalanceReader($db, new PaymentStateCalculator(), new DeliveryStateCalculator()))->of(1);
        self::assertNotNull($balance);

        if ($covers) {
            self::assertSame(PaymentState::Paid, $balance->paymentState, 'die Gutscheinzahlungen treffen die Bestellsumme auf den Cent');
            self::assertTrue(BigDecimal::of($balance->outstanding())->isZero());
            self::assertEquals(BigDecimal::of($value)->minus($grandTotal), BigDecimal::of((string) $db->fetchOne('SELECT balance FROM tl_consho_voucher WHERE id = 1')), 'der Rest bleibt auf dem Gutschein');
        } else {
            self::assertSame(PaymentState::Partial, $balance->paymentState);
            self::assertEquals(BigDecimal::of($grandTotal)->minus('20'), BigDecimal::of($balance->outstanding()), 'offen bleibt, was die Zahlungsart einzieht');
            self::assertEquals(BigDecimal::of($state->toPay()), BigDecimal::of($balance->outstanding()), 'genau der Betrag, den die Kasse der Zahlungsart nannte');
            self::assertEquals(0, (float) $db->fetchOne('SELECT balance FROM tl_consho_voucher WHERE id = 1'));
        }
    }

    /**
     * EZ-1, F2: a single-purpose voucher of class 1 (20 % AT) pays the book
     * and nothing else — not the other class, not the shipping, though its
     * balance would. The purchase books exactly that.
     */
    public function testEinEinzweckGutscheinZahltNurSeineArtikel(): void
    {
        $db = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_voucher', 'tl_consho_voucher_booking', 'tl_consho_payment'] as $table) {
            DcaSchema::create($db, $table);
        }

        $db->insert('tl_consho_voucher', ['id' => 1, 'code' => 'AAAABBBBCCCCMDSN', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '500', 'balance' => '500', 'taxClass' => 1, 'taxRate' => '20.0000', 'taxCountry' => 'AT']);

        $state = $this->state('AT', false, 'ATU12345678', [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '3.0000', 1)], vouchers: new VoucherWallet($db), voucherCodes: ['AAAABBBBCCCCMDSN'], pots: new CoverablePots($this->taxes()));

        self::assertTrue($state->isReady());
        self::assertFalse($state->coveredByVouchers);
        self::assertSame(['60.00'], array_map(static fn ($voucher): string => $voucher->applied, $state->payingVouchers()));
        self::assertEquals(BigDecimal::of($state->grossTotal)->minus('60'), BigDecimal::of($state->toPay()));

        $this->purchase($state, 'ATU12345678');
        self::assertSame('60.00', $this->drafts[0]->vouchers[0]['amount']);
    }

    private function dreiArten(): array
    {
        return [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '1.0000', 1), new CartItemRow(3, 9, '1.0000', 1)];
    }

    /**
     * @param list<CartItemRow>|null $items
     * @param list<string>           $voucherCodes
     */
    private function state(string $country, bool $verifiedVatId, string $shopVatId, array|null $items = null, string $code = '', CartDiscounts|null $discounts = null, bool $netAudience = false, bool $taxAtDestination = false, VoucherWallet|null $vouchers = null, array $voucherCodes = [], CoverablePots|null $pots = null): CheckoutState
    {
        $data = (new CheckoutData())
            ->withBilling(new Address(lastname: 'Muster', street: 'Hauptstr. 1', postal: '1100', city: 'Wien', country: 'AT', email: 'eva@example.org'))
            ->withShipping(new Address(lastname: 'Muster', street: 'Lagerweg 9', postal: '10115', city: 'Berlin', country: $country, email: 'eva@example.org'));

        if ($verifiedVatId) {
            $data = $data->withVatId('DE123456789')->withVatCheck(new VatIdCheckResult(VatIdStatus::Valid, self::NOW, null, 'WAPI1'));
        }

        $calculator = new CheckoutCalculator(
            new CartPricer($this->tree(), $this->resolver(), $this->gross(), null, $this->kindsOf()),
            $this->shipping(),
            new ShippingMethodTypes(new ServiceLocator(['pauschale' => fn (): ShippingMethodTypeInterface => $this->flatRate()])),
            $this->payments(),
            new PaymentHandlers(new ServiceLocator(['vorkasse' => static fn () => new PaymentHandlerStub()])),
            new ShippingTaxSplitter(),
            $this->gross(),
            new AgeGate(),
            $this->kindsOf(),
            discounts: $discounts,
            vouchers: $vouchers,
            pots: $pots,
        );

        return $calculator->calculate(
            1,
            $items ?? [new CartItemRow(1, 7, '1.0000', 1), new CartItemRow(2, 8, '3.0000', 1), new CartItemRow(3, 8, '2.0000', 1, 5)],
            'EUR',
            'AT',
            ['AT', 'DE'],
            [],
            $data,
            self::NOW,
            0,
            [],
            'de',
            'de',
            $shopVatId,
            netAudience: $netAudience,
            discountCode: $code,
            voucherCodes: $voucherCodes,
            taxAtDestination: $taxAtDestination,
        );
    }

    private function purchase(CheckoutState $state, string $shopVatId): void
    {
        $factory = $this->factory();

        $record = function (OrderDraft $draft): void {
            $this->drafts[] = $draft;
        };

        $placer = new class($factory, $record) implements OrderPlacerInterface {
            public function __construct(private readonly OrderFactory $factory, private readonly \Closure $record)
            {
            }

            public function place(int $cartId, int $rootId, OrderDraft $draft, array $lines, PaymentMethod $method, int $now): PlacedOrder
            {
                ($this->record)($draft);

                return new PlacedOrder($this->factory->create($draft, $lines, $now), str_repeat('a', 64));
            }
        };

        (new PurchaseHandler($placer, $this->payments()))->place(1, 1, 1, 'AT', 'de', 'de', 0, [], $state, self::NOW, $shopVatId);
    }

    private function factory(): OrderFactory
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 1, 'prefix' => 'WEB-', 'padding' => 5, 'current' => 0]);

        $products = $this->createMock(OrderProductRepositoryInterface::class);
        $products->method('snapshot')->willReturnCallback(
            fn (int $id): array|null => match ($id) {
                7 => ['name' => 'Tee', 'sku' => 'T-1', 'taxClass' => 1, 'mainProduct' => 7, 'kind' => $this->kinds[7]],
                8 => ['name' => 'Buch', 'sku' => 'B-1', 'taxClass' => 2, 'mainProduct' => 8, 'kind' => $this->kinds[8]],
                9 => ['name' => 'Beratungspaket', 'sku' => 'BP-3', 'taxClass' => 1, 'mainProduct' => 9, 'kind' => $this->kinds[9]],
                default => null,
            },
        );

        $writer = $this->createMock(OrderWriterInterface::class);
        $writer->method('createOrder')->willReturnCallback(function (array $values): int {
            $this->orders[] = $values;

            return 1;
        });
        $writer->method('createItem')->willReturnCallback(function (array $values): void {
            $this->items[] = $values;
        });

        return new OrderFactory(new NumberRangeAllocator($connection), $this->resolver(), $this->taxes(), $products, $writer);
    }

    private function resolver(): ArticlePriceResolver
    {
        $oneTime = new InMemoryPriceRepository([
            7 => ['EUR' => [new PriceRow('50.0000', 'EUR', null, '1.0000')]],
            8 => ['EUR' => [new PriceRow('33.3333', 'EUR', null, '1.0000')]],
            9 => ['EUR' => [new PriceRow('100.0000', 'EUR', null, '1.0000')]],
        ]);

        // Abo-2: the book also comes as a monthly subscription (rule 5) at 4.1667 net
        // — four decimal places like the one-off purchase, so that rounding plays along.
        $prices = new class($oneTime) implements PriceRepositoryInterface {
            public function __construct(private readonly InMemoryPriceRepository $oneTime)
            {
            }

            public function findCandidates(int $productId, string $currency, array $customerGroupIds, int $timestamp, int $subscriptionRule = 0): array
            {
                if (0 === $subscriptionRule) {
                    return $this->oneTime->findCandidates($productId, $currency, $customerGroupIds, $timestamp);
                }

                return 5 === $subscriptionRule && 8 === $productId && 'EUR' === $currency ? [new PriceRow('4.1667', 'EUR', null, '1.0000')] : [];
            }

            public function findProductIdsWithPrices(): array
            {
                return $this->oneTime->findProductIdsWithPrices();
            }

            public function findCurrencies(): array
            {
                return $this->oneTime->findCurrencies();
            }
        };

        $classes = $this->createMock(ArticleTaxClassesInterface::class);
        $classes->method('taxClassOf')->willReturnCallback(static fn (int $id): int => 8 === $id ? 2 : 1);

        return new ArticlePriceResolver(new PriceCalculator($prices), $prices, new BasePriceCalculator(), $this->gross(), $classes);
    }

    private function kindsOf(): ArticleShippingDataInterface
    {
        $kinds = $this->kinds;

        return new class($kinds) implements ArticleShippingDataInterface {
            /**
             * @param array<int, string> $kinds
             */
            public function __construct(private readonly array $kinds)
            {
            }

            public function of(array $articleIds): array
            {
                $data = [];

                foreach ($articleIds as $id) {
                    $data[$id] = ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => $this->kinds[$id] ?? 'physical'];
                }

                return $data;
            }
        };
    }

    private function tree(): ArticleTreeInterface
    {
        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(static fn (array $ids): array => array_combine($ids, $ids));

        return $tree;
    }

    private function gross(): GrossCalculator
    {
        return new GrossCalculator($this->taxes());
    }

    private function taxes(): TaxResolver
    {
        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturnCallback(
            static fn (int $class, string $country): string|null => match ($country) {
                'AT' => 1 === $class ? '20.0000' : '10.0000',
                'DE' => 1 === $class ? '19.0000' : '7.0000',
                default => null,
            },
        );

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE']);

        return new TaxResolver($rates, new CountryProvider($countries));
    }

    private function shipping(): ShippingMethodRepositoryInterface
    {
        $repository = $this->createMock(ShippingMethodRepositoryInterface::class);
        $repository->method('forShop')->willReturn([new ShippingMethod(1, 'pauschale', 'Paket', ShippingTaxMode::Proportional, 1, ['net' => '4.9500'])]);

        return $repository;
    }

    private function payments(): PaymentMethodRepositoryInterface
    {
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('forShop')->willReturn([
            new PaymentMethod(1, 'vorkasse', 'Vorkasse mit Skonto', ['adjustment' => new PaymentAdjustment('2.0000', PaymentAdjustmentOrigin::Discount)]),
        ]);

        return $repository;
    }

    private function flatRate(): ShippingMethodTypeInterface
    {
        return new class implements ShippingMethodTypeInterface {
            public static function getType(): string
            {
                return 'pauschale';
            }

            public function isAvailable(ShippingContext $context, array $config): bool
            {
                return true;
            }

            public function calculate(ShippingContext $context, array $config): ShippingQuote
            {
                return new ShippingQuote((string) $config['net']);
            }

            public function capabilities(array $config): ShippingCapabilities
            {
                return new ShippingCapabilities(0, false);
            }
        };
    }
}
