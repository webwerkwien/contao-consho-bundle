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

    // Block G (review B2): the isolation level as the double holds it.
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

        // Den Klartext gibt es hier und nie wieder — gespeichert ist nur sein
        // Hash. Ein zweites Token für den Zahlungsversuch entsteht hier NICHT
        // mehr (F5): den hielte niemand fest, und findByHash() fände die Zeile
        // nie wieder.
        self::assertSame(64, \strlen($placed->viewToken));

        // C-10: zwei Tokens — die Umleitung bestätigt nichts, nur der Link aus
        // der Mail belegt die Adresse.
        self::assertSame(64, \strlen($placed->mailToken));
        self::assertNotSame($placed->viewToken, $placed->mailToken);
        self::assertFalse($this->storedTokens[hash('sha256', $placed->viewToken)]);
        self::assertTrue($this->storedTokens[hash('sha256', $placed->mailToken)]);

        self::assertSame(['begin', 'commit'], $this->transaction);
    }

    /**
     * Abo-3: Die Abos entstehen in DERSELBEN Transaktion wie die Bestellung,
     * nach ihr und vor dem konvertierten Warenkorb — sonst gäbe es eine
     * Abo-Bestellung ohne Abo, und niemand könnte es kündigen.
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
     * Block G (G4): der Bestand wird in der Transaktion abgebucht, nach den
     * Abos — fehlt etwas, rollt alles zurück, auch die Bestellung.
     */
    /**
     * Block G, review B2 (zweites Review): Der Kaufklick läuft in READ
     * COMMITTED — wer auf die Bestandszeile eines Schlüsselvorrats gewartet
     * hat, zählt sonst mit dem Schnappschuss von vorher. Danach gilt wieder
     * die vorige Stufe, auch wenn der Kauf scheitert.
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
     * Block H (H-4, W5): Die Einlösung zählt in der Transaktion der
     * Bestellung, für den Code, das Mitglied und die Adresse des Käufers.
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
        // Regel 25 (Fall 42): Eine nullbare Abhängigkeit fehlt still.
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
         * F5 des Reviews vom 2026-09-23. Der Versuch entsteht dort, wo eine
         * Zahlung beginnt — in start() (C-10/C-11), das den Klartext für die
         * Rücksprung- und Benachrichtigungsadresse in der Hand hält.
         *
         * Hier entstand er mit einem Token, den der Request mitnahm und
         * niemand las: eine Zeile in `open`, die findByHash() nie wiederfindet
         * und die 7.5 („offene Versuche abbrechen") mitzählt. Was sie festhielt
         * — Art, Titel, Betrag, Währung — steht im Bestellkopf.
         */
        $this->carts->method('lockForPurchase')->willReturn(['state' => 'open', 'order' => 0]);
        $this->orders->method('create')->willReturn(77);

        /*
         * Am Konstruktor geprüft, nicht an einem Doppel mit `never`: Der
         * Placer kennt das Versuchs-Repository gar nicht mehr, und ein
         * `expects(never)` auf einem Doppel, das niemand hineinreicht, wäre
         * genau die Art Prüfung, die nichts prüft.
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
        // Die zweite Anfrage hat auf die Sperre gewartet; inzwischen hat die
        // erste festgeschrieben. Sie bekommt dieselbe Bestellung, ein eigenes
        // Ansichts-Token — und legt nichts an.
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
        // Der Fall, für den die Transaktion überhaupt da ist: Bis C-7 wären
        // hier eine Bestellung ohne Positionen und eine verbrauchte Nummer
        // zurückgeblieben — und eine Bestellnummer, die weg ist, ist weg.
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
     * D-2, E1 — Michael: „Sperre bei AGB ist schon falsch, man muss keine
     * haben." Ohne AGB-Häkchen verkauft der Shop ohne AGB, und die
     * vorhandene Belehrung wird trotzdem eingefroren.
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
        // 🔴 Nebenpunkt zu F3: Die Prüfsumme fängt einen Text, der sich
        // zwischen Anzeige und Klick geändert hat. Dies fängt den schmalen
        // Streifen danach — ein Element, dessen Zeitfenster in diesen
        // Millisekunden endet. Eine Bestellung mit Häkchen und ohne Wortlaut
        // belegt nichts und sieht aus, als belegte sie etwas.
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
        // Der Aufräum-Cron entfernt offene Warenkörbe nach dreißig Tagen. Ein
        // Checkout, der über diese Grenze offen lag, findet hier nichts mehr.
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
        // Eine Kartenzahlung wartet auf das Geld (C-6). Die Rechnung entsteht
        // dann beim Zahlungseingang, nicht hier.
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
         * ⚠️ Als MAP, nach `kind->value` benannt — so gibt `forRoot()` sie
         * heraus. Bis zum 2026-09-23 lieferte dieses Double eine Liste, und
         * das fiel nicht auf, solange der Placer nur darüber iterierte.
         * Sobald er nach einer Art fragte, war das Doppel fähiger als das
         * Original (Punkt 23 der Prüfungsnotiz, andersherum).
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
     * C-8: Das PDF für die Ablage entsteht NACH dem Commit — die Sperre auf
     * dem Nummernkreis hielte sonst jede andere Bestellung des Shops auf,
     * solange dompdf rechnet.
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
     * WG15: Eine Bestellung, die nichts schuldet, ist mit dem Kaufklick
     * bezahlt — in DERSELBEN Transaktion, nach der Bestellung und vor dem
     * konvertierten Warenkorb. Die dabei ausgestellte Rechnung geht nach dem
     * Commit in die Ablage wie die beim Kauf.
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
     * WG-5 (E4): Die Gutscheine zahlen in derselben Transaktion — vor der
     * Rechnung, damit eine Vorkasse-Rechnung sie nennt und nur den Rest
     * verlangt, und vor der Prüfung „nichts offen".
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
     * E4: Zahlt ein Gutschein nicht mehr, was die Seite zeigte, wird nichts
     * bestellt — alles davor rollt zurück.
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
