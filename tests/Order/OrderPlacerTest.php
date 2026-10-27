<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Cart\CartConversionInterface;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartRuleExhaustedAtPurchase;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalLegalTextSource;
use Webwerkwien\ContaoConshoBundle\Checkout\LegalText;
use Webwerkwien\ContaoConshoBundle\Checkout\LegalTextKind;
use Webwerkwien\ContaoConshoBundle\Checkout\ShopLegalRequirements;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Order\BasketGoneAtPurchase;
use Webwerkwien\ContaoConshoBundle\Order\LegalTextGoneAtPurchase;
use Webwerkwien\ContaoConshoBundle\Order\OrderDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderLegalWriterInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderPlacer;
use Webwerkwien\ContaoConshoBundle\Order\PurchaseSettlement;
use Webwerkwien\ContaoConshoBundle\Order\StockShortAtPurchase;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttemptRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Stock\StockAllocation;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRecorderInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionWithoutAccount;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;
use Webwerkwien\ContaoConshoBundle\Token\TokenRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherChangedAtPurchase;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherRedemption;

/**
 * The purchase click as ONE transaction (7.3 step 2).
 *
 * Against a mocked connection, not SQLite: what is under test is the ORDER of
 * begin, commit and rollback and what happens between them, and SQLite has no
 * FOR UPDATE to lock the basket with in the first place.
 */
class OrderPlacerTest extends TestCase
{
    private Connection&MockObject $connection;

    private CartConversionInterface&MockObject $carts;

    private OrderFactoryInterface&MockObject $orders;

    /** @var list<string> */
    private array $transaction = [];

    /** @var array<string, bool> token hash => confirmsEmail */
    private array $storedTokens = [];

    // Block G: the isolation level as the double holds it.
    private TransactionIsolationLevel $isolation = TransactionIsolationLevel::REPEATABLE_READ;

    protected function setUp(): void
    {
        $this->transaction = [];

        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('beginTransaction')->willReturnCallback(function (): void {
            $this->transaction[] = 'begin';
        });
        $this->connection->method('commit')->willReturnCallback(function (): void {
            $this->transaction[] = 'commit';
        });
        $this->connection->method('rollBack')->willReturnCallback(function (): void {
            $this->transaction[] = 'rollback';
        });
        $this->connection->method('isTransactionActive')->willReturnCallback(
            fn (): bool => 'begin' === ($this->transaction[array_key_last($this->transaction)] ?? ''),
        );
        $this->isolation = TransactionIsolationLevel::REPEATABLE_READ;
        $this->connection->method('getTransactionIsolation')->willReturnCallback(fn (): TransactionIsolationLevel => $this->isolation);
        $this->connection->method('setTransactionIsolation')->willReturnCallback(function (TransactionIsolationLevel $level): void {
            $this->isolation = $level;
        });

        $this->carts = $this->createMock(CartConversionInterface::class);
        $this->orders = $this->createMock(OrderFactoryInterface::class);
    }

    public function testDerKaufklickSchreibtAllesInEinerTransaktion(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $legal = $this->createMock(OrderLegalWriterInterface::class);
        $legal->expects(self::exactly(2))->method('freeze');

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->expects(self::once())->method('issueOnce')->with(77, 1, DocumentType::Invoice);

        $this->carts->expects(self::once())->method('markConverted')->with(12, 77);

        $placed = $this->placer($legal, $documents)->place(
            12,
            5,
            $this->draft(),
            [OrderLineDraft::product(1, '1')],
            $this->method(),
            1_757_000_000,
        );

        self::assertSame(77, $placed->orderId);
        self::assertFalse($placed->wasAlreadyPlaced);

        // The plain text exists here and never again — only its hash is
        // stored. A second token for the payment attempt is NO LONGER
        // created here: nobody would hold on to it, and findByHash() would
        // never find the row again.
        self::assertSame(64, \strlen($placed->viewToken));

        // C-10: two tokens — the redirect confirms nothing, only the link from
        // the mail proves the address.
        self::assertSame(64, \strlen($placed->mailToken));
        self::assertNotSame($placed->viewToken, $placed->mailToken);
        self::assertFalse($this->storedTokens[hash('sha256', $placed->viewToken)]);
        self::assertTrue($this->storedTokens[hash('sha256', $placed->mailToken)]);

        self::assertSame(['begin', 'commit'], $this->transaction);
    }

    /**
     * Abo-3: the subscriptions are created in the SAME transaction as the
     * order, after it and before the converted basket — otherwise there would
     * be a subscription order without a subscription, and nobody could cancel
     * it.
     */
    public function testDieAbosEntstehenInDerTransaktionNachDerBestellung(): void
    {
        $steps = [];

        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturnCallback(function () use (&$steps): int {
            $steps[] = 'create';

            return 77;
        });
        $this->carts->method('markConverted')->willReturnCallback(function () use (&$steps): void {
            $steps[] = 'convert';
        });

        $subscriptions = $this->createMock(SubscriptionRecorderInterface::class);
        $subscriptions->expects(self::once())->method('record')->willReturnCallback(function (int $orderId) use (&$steps): array {
            $steps[] = \sprintf('record %d %s', $orderId, $this->connection->isTransactionActive() ? 'in transaction' : 'OUTSIDE');

            return [5];
        });

        $this->placer(subscriptions: $subscriptions)->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1', 3)], $this->method(), 1_757_000_000);

        self::assertSame(['create', 'record 77 in transaction', 'convert'], $steps);
        self::assertSame(['begin', 'commit'], $this->transaction);
    }

    /**
     * Block G (G4): the stock is booked off inside the transaction, after the
     * subscriptions — if something is missing, everything rolls back, the
     * order too.
     */
    /**
     * Block G: the purchase click runs in READ COMMITTED — whoever waited for
     * the stock row of a key pool would otherwise count with the snapshot from
     * before. Afterwards the previous level applies again, even when the
     * purchase fails.
     */
    public function testDerKaufklickLaeuftInReadCommitted(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $seen = [];
        $stock = $this->createMock(StockAllocation::class);
        $stock->method('bookOrder')->willReturnCallback(function () use (&$seen): void {
            $seen[] = $this->isolation;
        });

        $this->placer(stock: $stock)->place(12, 5, $this->draft(), [OrderLineDraft::product(1)], $this->method(), 1_757_000_000);

        self::assertSame([TransactionIsolationLevel::READ_COMMITTED], $seen);
        self::assertSame(TransactionIsolationLevel::REPEATABLE_READ, $this->isolation);

        $failing = $this->createStub(StockAllocation::class);
        $failing->method('bookOrder')->willThrowException(StockShortAtPurchase::forArticle(1));

        try {
            $this->placer(stock: $failing)->place(12, 5, $this->draft(), [OrderLineDraft::product(1)], $this->method(), 1_757_000_000);
        } catch (StockShortAtPurchase) {
        }

        self::assertSame(TransactionIsolationLevel::REPEATABLE_READ, $this->isolation, 'auch nach einem Fehler zurück');
    }

    public function testDerBestandWirdInDerTransaktionAbgebucht(): void
    {
        $steps = [];

        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);
        $this->carts->method('markConverted')->willReturnCallback(function () use (&$steps): void {
            $steps[] = 'convert';
        });

        $stock = $this->createMock(StockAllocation::class);
        $stock->expects(self::once())->method('bookOrder')->willReturnCallback(function (int $orderId, int $now) use (&$steps): void {
            $steps[] = \sprintf('stock %d %d %s', $orderId, $now, $this->connection->isTransactionActive() ? 'in transaction' : 'OUTSIDE');
        });

        $this->placer(stock: $stock)->place(12, 5, $this->draft(), [OrderLineDraft::product(1)], $this->method(), 1_757_000_000);

        self::assertSame(['stock 77 1757000000 in transaction', 'convert'], $steps);
        self::assertSame(['begin', 'commit'], $this->transaction);
    }

    /**
     * Block H (H-4, W5): the redemption counts inside the order's transaction,
     * for the code, the member and the buyer's address.
     */
    public function testDieEinloesungZaehltInDerTransaktion(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $discounts = $this->createMock(CartDiscounts::class);
        $discounts->expects(self::once())->method('redeem')->willReturnCallback(function (int $rule, string $code, int $order, int $member, string $email): void {
            self::assertSame([3, 'Herbst', 77, 9, 'Eva@Example.org'], [$rule, $code, $order, $member, $email]);
            self::assertTrue($this->connection->isTransactionActive(), 'in der Transaktion');
        });

        $this->placer(discounts: $discounts)->place(12, 5, $this->draft(3, 'Herbst'), [OrderLineDraft::product(1)], $this->method(), 1_757_000_000);

        self::assertSame(['begin', 'commit'], $this->transaction);
    }

    public function testOhneRegelWirdNichtsEingeloest(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $discounts = $this->createMock(CartDiscounts::class);
        $discounts->expects(self::never())->method('redeem');

        $this->placer(discounts: $discounts)->place(12, 5, $this->draft(), [OrderLineDraft::product(1)], $this->method(), 1_757_000_000);
    }

    public function testIstDerRabattAufgebrauchtEntstehtKeineBestellung(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);
        $this->carts->expects(self::never())->method('markConverted');

        $discounts = $this->createStub(CartDiscounts::class);
        $discounts->method('redeem')->willThrowException(CartRuleExhaustedAtPurchase::for(3));

        try {
            $this->placer(discounts: $discounts)->place(12, 5, $this->draft(3, 'Herbst'), [OrderLineDraft::product(1)], $this->method(), 1_757_000_000);
            self::fail('The purchase went through with the discount used up.');
        } catch (CartRuleExhaustedAtPurchase) {
        }

        self::assertSame(['begin', 'rollback'], $this->transaction);
    }

    public function testFehltBestandEntstehtKeineBestellung(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);
        $this->carts->expects(self::never())->method('markConverted');

        $stock = $this->createStub(StockAllocation::class);
        $stock->method('bookOrder')->willThrowException(StockShortAtPurchase::forArticle(1));

        try {
            $this->placer(stock: $stock)->place(12, 5, $this->draft(), [OrderLineDraft::product(1)], $this->method(), 1_757_000_000);
            self::fail('The purchase went through without stock.');
        } catch (StockShortAtPurchase) {
        }

        self::assertSame(['begin', 'rollback'], $this->transaction);
    }

    public function testScheitertDasAboScheitertDerKauf(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);
        $this->carts->expects(self::never())->method('markConverted');

        $subscriptions = $this->createStub(SubscriptionRecorderInterface::class);
        $subscriptions->method('record')->willThrowException(SubscriptionWithoutAccount::forOrder(77));

        try {
            $this->placer(subscriptions: $subscriptions)->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1', 3)], $this->method(), 1_757_000_000);
            self::fail('The purchase went through without its subscription.');
        } catch (SubscriptionWithoutAccount) {
        }

        self::assertSame(['begin', 'rollback'], $this->transaction);
    }

    public function testDerDoppelklickLegtKeinZweitesAboAn(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'converted', 'order' => 77]);

        $subscriptions = $this->createMock(SubscriptionRecorderInterface::class);
        $subscriptions->expects(self::never())->method('record');

        $placed = $this->placer(subscriptions: $subscriptions)->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1', 3)], $this->method(), 1_757_000_000);

        self::assertTrue($placed->wasAlreadyPlaced);
    }

    public function testDerRecorderIstPflicht(): void
    {
        // Checked on the constructor the container calls: a nullable dependency goes missing silently.
        $parameter = array_values(array_filter(
            (new \ReflectionClass(OrderPlacer::class))->getConstructor()?->getParameters() ?? [],
            static fn (\ReflectionParameter $p): bool => SubscriptionRecorderInterface::class === $p->getType()?->__toString(),
        ));

        self::assertCount(1, $parameter);
        self::assertFalse($parameter[0]->allowsNull());
        self::assertFalse($parameter[0]->isOptional());
    }

    public function testDerKaufklickLegtKeinenZahlungsversuchAn(): void
    {
        /*
         * The attempt is created where a payment begins — in start()
         * (C-10/C-11), which holds the plain text for the return and
         * notification address in its hand.
         *
         * Here it used to be created with a token that the request took along
         * and nobody read: a row in `open` that findByHash() never finds again
         * and that 7.5 ("cancel open attempts") counts. What it held — method,
         * title, amount, currency — is in the order header.
         */
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        /*
         * Checked on the constructor, not on a double with `never`: the
         * placer does not know the attempt repository any more, and an
         * `expects(never)` on a double that nobody passes in would be
         * exactly the kind of check that checks nothing.
         */
        self::assertNotContains(
            PaymentAttemptRepositoryInterface::class,
            array_map(
                static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                (new \ReflectionClass(OrderPlacer::class))->getConstructor()?->getParameters() ?? [],
            ),
        );

        $placed = $this->placer()->place(
            12,
            5,
            $this->draft(),
            [OrderLineDraft::product(1, '1')],
            $this->method(),
            1_757_000_000,
        );

        self::assertSame(77, $placed->orderId);
    }

    public function testEinDoppelklickErzeugtKeineZweiteBestellung(): void
    {
        // The second request waited for the lock; meanwhile the first one
        // committed. It gets the same order, a view token of its own —
        // and creates nothing.
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'converted', 'order' => 77]);
        $this->orders->expects(self::never())->method('create');

        $legal = $this->createMock(OrderLegalWriterInterface::class);
        $legal->expects(self::never())->method('freeze');

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->expects(self::never())->method('issueOnce');

        $placed = $this->placer($legal, $documents)->place(
            12,
            5,
            $this->draft(),
            [OrderLineDraft::product(1, '1')],
            $this->method(),
            1_757_000_000,
        );

        self::assertSame(77, $placed->orderId);
        self::assertTrue($placed->wasAlreadyPlaced);
        self::assertSame(64, \strlen($placed->viewToken));
        self::assertSame(['begin', 'commit'], $this->transaction);
    }

    public function testEinFehlerNachDerBestellungRolltAllesZurueck(): void
    {
        // The case the transaction exists for in the first place: until C-7
        // an order without items and a used-up number would have been left
        // behind here — and an order number that is gone is gone.
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $legal = $this->createMock(OrderLegalWriterInterface::class);
        $legal->method('freeze')->willThrowException(new \RuntimeException('database went away'));

        $this->carts->expects(self::never())->method('markConverted');

        $this->expectException(\RuntimeException::class);

        try {
            $this->placer($legal)->place(
                12,
                5,
                $this->draft(),
                [OrderLineDraft::product(1, '1')],
                $this->method(),
                1_757_000_000,
            );
        } finally {
            self::assertSame(['begin', 'rollback'], $this->transaction);
        }
    }

    /**
     * D-2, E1: blocking on missing terms and conditions is wrong — a shop
     * does not have to have any. Without a terms tick the shop sells without
     * terms, and the withdrawal information that exists is frozen all the
     * same.
     */
    public function testOhnePflichtFuerAgbEntstehtDieBestellungOhneAgb(): void
    {
        $this->required = [LegalTextKind::Withdrawal];
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $legal = $this->createMock(OrderLegalWriterInterface::class);
        $legal->expects(self::once())->method('freeze')->with(77, self::callback(static fn (LegalText $text): bool => LegalTextKind::Withdrawal === $text->kind), 1_757_000_000);

        $placed = $this->placer($legal, legalTexts: [
            LegalTextKind::Withdrawal->value => LegalText::of(LegalTextKind::Withdrawal, 807, 244, 'Withdrawal', 'How to change your mind.'),
        ])->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1')], $this->method(), 1_757_000_000);

        self::assertSame(77, $placed->orderId);
    }

    public function testOhneBeideRechtstexteEntstehtKeineBestellung(): void
    {
        // 🔴 Next to the checksum: it catches a text that changed between
        // display and click. This catches the narrow strip after it — an
        // element whose time window ends in those milliseconds. An order with
        // a tick and without the wording proves nothing and looks as if it
        // proved something.
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);
        $this->carts->expects(self::never())->method('markConverted');

        $this->expectException(LegalTextGoneAtPurchase::class);

        try {
            $this->placer(legalTexts: [
                LegalTextKind::Terms->value => LegalText::of(LegalTextKind::Terms, 806, 243, 'Terms', 'What we agree on.'),
            ])->place(
                12,
                5,
                $this->draft(),
                [OrderLineDraft::product(1, '1')],
                $this->method(),
                1_757_000_000,
            );
        } finally {
            self::assertSame(['begin', 'rollback'], $this->transaction);
        }
    }

    public function testOhneWarenkorbEntstehtKeineBestellung(): void
    {
        // The clean-up cron removes open baskets after thirty days. A
        // checkout that stayed open beyond that limit finds nothing here any more.
        $this->carts->method('lockForPurchase')->willReturn(null);
        $this->orders->expects(self::never())->method('create');

        $this->expectException(BasketGoneAtPurchase::class);

        try {
            $this->placer()->place(
                12,
                5,
                $this->draft(),
                [OrderLineDraft::product(1, '1')],
                $this->method(),
                1_757_000_000,
            );
        } finally {
            self::assertSame(['begin', 'rollback'], $this->transaction);
        }
    }

    public function testOhneAusloeserBeiBestellungEntstehtNochKeineRechnung(): void
    {
        // A card payment waits for the money (C-6). The invoice is then created
        // when the payment comes in, not here.
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->expects(self::never())->method('issueOnce');

        $this->placer(documents: $documents, trigger: DocumentTrigger::OnPayment)->place(
            12,
            5,
            $this->draft(),
            [OrderLineDraft::product(1, '1')],
            $this->method(),
            1_757_000_000,
        );

        self::assertSame(['begin', 'commit'], $this->transaction);
    }

    private function placer(
        OrderLegalWriterInterface|null $legal = null,
        DocumentIssuerInterface|null $documents = null,
        DocumentTrigger $trigger = DocumentTrigger::OnOrder,
        array|null $legalTexts = null,
        DocumentArchiverInterface|null $archiver = null,
        SubscriptionRecorderInterface|null $subscriptions = null,
        StockAllocation|null $stock = null,
        CartDiscounts|null $discounts = null,
        PurchaseSettlement|null $settlement = null,
        VoucherRedemption|null $vouchers = null,
    ): OrderPlacer {
        /*
         * ⚠️ As a MAP, keyed by `kind->value` — that is how `forRoot()` hands
         * them out. This double used to deliver a list, and that went
         * unnoticed as long as the placer only iterated over it. As soon as
         * it asked for one kind, the double was more capable than the
         * original (for every property a double answers: does the original
         * ALWAYS have it — here the other way round).
         */
        $texts = $this->createMock(DbalLegalTextSource::class);
        $texts->method('forRoot')->willReturn(
            null === $legalTexts
                ? [
                    LegalTextKind::Terms->value => LegalText::of(LegalTextKind::Terms, 806, 243, 'Terms', 'What we agree on.'),
                    LegalTextKind::Withdrawal->value => LegalText::of(LegalTextKind::Withdrawal, 807, 244, 'Withdrawal', 'How to change your mind.'),
                ]
                : $legalTexts,
        );

        $tokenRepository = $this->createMock(TokenRepositoryInterface::class);
        $tokenRepository->method('store')->willReturnCallback(function (int $o, $p, string $hash, int $c, int|null $e, bool $confirmsEmail): void {
            $this->storedTokens[$hash] = $confirmsEmail;
        });

        $handler = $this->createMock(PaymentHandlerInterface::class);
        $handler->method('documentTrigger')->willReturn($trigger);

        $locator = $this->createMock(\Symfony\Contracts\Service\ServiceProviderInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($handler);

        return new OrderPlacer(
            $this->connection,
            $this->carts,
            $this->orders,
            $texts,
            $legal ?? $this->createMock(OrderLegalWriterInterface::class),
            new TokenGenerator($tokenRepository),
            $documents ?? $this->createMock(DocumentIssuerInterface::class),
            new PaymentHandlers($locator),
            $archiver ?? $this->createMock(DocumentArchiverInterface::class),
            $this->requirements(),
            $subscriptions ?? $this->createStub(SubscriptionRecorderInterface::class),
            $stock ?? $this->createStub(StockAllocation::class),
            $settlement ?? $this->createStub(PurchaseSettlement::class),
            $vouchers ?? $this->createStub(VoucherRedemption::class),
            $discounts,
        );
    }

    /** @var list<LegalTextKind> what the shop has to have (D-2, E1); both by default, as before */
    private array $required = [LegalTextKind::Terms, LegalTextKind::Withdrawal];

    private function requirements(): ShopLegalRequirements
    {
        $requirements = $this->createStub(ShopLegalRequirements::class);
        $requirements->method('forShop')->willReturnCallback(fn (): array => $this->required);

        return $requirements;
    }

    private function draft(int $discountRule = 0, string $discountCode = ''): OrderDraft
    {
        return new OrderDraft(
            shopId: 1,
            currency: 'EUR',
            billing: new Address(street: 'Hauptstraße 5', postal: '1100', city: 'Wien', country: 'AT', email: 'Eva@Example.org'),
            shipping: new Address(street: 'Hauptstraße 5', postal: '1100', city: 'Wien', country: 'AT'),
            shopCountry: 'AT',
            memberId: 9,
            language: 'de',
            discountRule: $discountRule,
            discountCode: $discountCode,
        );
    }

    private function method(): PaymentMethod
    {
        return new PaymentMethod(6, 'prepayment', 'Vorkasse', []);
    }

    /**
     * C-8: the PDF for the archive is created AFTER the commit — otherwise
     * the lock on the number range would hold up every other order of the
     * shop while dompdf computes.
     */
    public function testDieAblageLaeuftNachDemCommitMitDerAusgestelltenRechnung(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->method('issueOnce')->willReturn(501);

        $archiver = $this->createMock(DocumentArchiverInterface::class);
        $archiver->expects(self::once())->method('archive')->with(501)->willReturnCallback(function (): void {
            self::assertFalse($this->connection->isTransactionActive(), 'Die Ablage lief in der Kauftransaktion.');
        });

        $this->placer(documents: $documents, archiver: $archiver)
            ->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1')], $this->method(), 1_757_000_000);
    }

    public function testOhneRechnungBeimKaufWirdNichtsAbgelegt(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $archiver = $this->createMock(DocumentArchiverInterface::class);
        $archiver->expects(self::never())->method('archive');

        $this->placer(trigger: DocumentTrigger::OnPayment, archiver: $archiver)
            ->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1')], $this->method(), 1_757_000_000);
    }

    /**
     * WG15: an order that owes nothing is paid with the purchase click — in
     * the SAME transaction, after the order and before the converted basket.
     * The invoice issued with it goes into the archive after the commit like
     * the one at purchase.
     */
    public function testEineBestellungOhneOffenenBetragIstMitDemKaufklickBezahlt(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);
        $this->carts->method('markConverted')->willReturnCallback(function (): void {
            $this->transaction[] = 'converted';
        });

        $settlement = $this->createMock(PurchaseSettlement::class);
        $settlement->expects(self::once())->method('settleIfNothingOpen')
            ->with(77, 1, self::isInstanceOf(PaymentMethod::class), 1_757_000_000)
            ->willReturnCallback(function (): int {
                $this->transaction[] = 'settled';

                return 502;
            });

        $archiver = $this->createMock(DocumentArchiverInterface::class);
        $archiver->expects(self::once())->method('archive')->with(502);

        $this->placer(trigger: DocumentTrigger::OnPayment, archiver: $archiver, settlement: $settlement)
            ->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1')], $this->method(), 1_757_000_000);

        self::assertSame(['begin', 'settled', 'converted', 'commit'], $this->transaction);
    }

    /**
     * WG-5 (E4): the gift cards pay in the same transaction — before the
     * invoice, so that a prepayment invoice names them and asks only for the
     * rest, and before the "nothing open" check.
     */
    public function testDieGutscheineZahlenVorRechnungUndAbschluss(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        $vouchers = $this->createMock(VoucherRedemption::class);
        $vouchers->expects(self::once())->method('redeem')
            ->with(77, self::isInstanceOf(OrderDraft::class), 1_757_000_000)
            ->willReturnCallback(function (): void {
                $this->transaction[] = 'vouchers';
            });

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->method('issueOnce')->willReturnCallback(function (): int {
            $this->transaction[] = 'invoice';

            return 501;
        });

        $settlement = $this->createMock(PurchaseSettlement::class);
        $settlement->method('settleIfNothingOpen')->willReturnCallback(function (): null {
            $this->transaction[] = 'settled';

            return null;
        });

        $this->placer(documents: $documents, settlement: $settlement, vouchers: $vouchers)
            ->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1')], $this->method(), 1_757_000_000);

        self::assertSame(['begin', 'vouchers', 'invoice', 'settled', 'commit'], $this->transaction);
    }

    /**
     * E4: if a gift card no longer pays what the page showed, nothing is
     * ordered — everything before rolls back.
     */
    public function testEinGeaenderterGutscheinRolltZurueck(): void
    {
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);
        $this->carts->expects(self::never())->method('markConverted');

        $vouchers = $this->createStub(VoucherRedemption::class);
        $vouchers->method('redeem')->willThrowException(VoucherChangedAtPurchase::for(3));

        try {
            $this->placer(vouchers: $vouchers)->place(12, 5, $this->draft(), [OrderLineDraft::product(1, '1')], $this->method(), 1_757_000_000);
            self::fail('Ein geänderter Gutschein darf nicht bestellen.');
        } catch (VoucherChangedAtPurchase) {
        }

        self::assertSame(['begin', 'rollback'], $this->transaction);
    }
}
