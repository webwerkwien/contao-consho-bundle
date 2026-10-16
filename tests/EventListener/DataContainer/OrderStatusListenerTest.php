<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\BackendUser;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderStatusListener;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderStatusRepository;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderCancellation;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquency;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPurchaseCancellation;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Das Statusfeld der Bestellung (D-1, Entscheidung A1).
 */
final class OrderStatusListenerTest extends TestCase
{
    private const ORDER = 7;

    private const PAID = 1;

    private const CANCELLED = 2;

    private const COMPLETED = 3;

    private const IN_REVIEW = 4;

    private const OTHER_SHOP = 9;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_order_status', ['id' => self::PAID, 'pid' => 1, 'sorting' => 10, 'title' => 'Bezahlt', 'isPaid' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => self::CANCELLED, 'pid' => 1, 'sorting' => 20, 'title' => 'Storniert', 'isCancelled' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => self::COMPLETED, 'pid' => 1, 'sorting' => 30, 'title' => 'Abgeschlossen', 'isCompleted' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => self::IN_REVIEW, 'pid' => 1, 'sorting' => 40, 'title' => 'In Klärung']);
        $this->connection->insert('tl_consho_order_status', ['id' => self::OTHER_SHOP, 'pid' => 2, 'title' => 'Bezahlt (Shop 2)', 'isPaid' => 1]);

        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'grandTotal' => '100.0000', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Wanderstock', 'quantity' => '2.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'productName' => 'Versand', 'quantity' => '1.0000', 'lineType' => 'shipping']);

        $GLOBALS['TL_LANGUAGE'] = 'de';
        $GLOBALS['TL_LANG']['tl_consho_order']['statusRefused'] = 'Der Status „%s" kann nicht gesetzt werden: %s.';
        $GLOBALS['TL_LANG']['tl_consho_order']['statusUnmet'] = [
            'paymentSettled' => 'es sind noch %s offen',
            'refundDue' => '%s sind zu erstatten',
            'deliverySettled' => 'nicht als versandt gemeldet: %s',
            'nothingShipped' => 'es ist bereits Ware versandt',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testNurStatusDesEigenenShopsStehenZurWahl(): void
    {
        $options = $this->listener()->options($this->dc());

        self::assertSame([self::PAID, self::CANCELLED, self::COMPLETED, self::IN_REVIEW], array_keys($options));
    }

    public function testBezahltOhneZahlungWirdAbgelehntMitDemOffenenBetrag(): void
    {
        $this->expectExceptionMessageMatches('/„Bezahlt" kann nicht gesetzt werden: es sind noch 100,00\s€ offen\./u');

        $this->listener()->save(self::PAID, $this->dc());
    }

    public function testBezahltMitVollerZahlungGeht(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '100.0000']);

        self::assertSame(self::PAID, $this->listener()->save(self::PAID, $this->dc()));
    }

    public function testAbgeschlossenNenntZahlungUndDieNichtVersandteWare(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '100.0000']);

        $this->expectExceptionMessage('nicht als versandt gemeldet: 2 × Wanderstock');

        $this->listener()->save(self::COMPLETED, $this->dc());
    }

    /**
     * Michaels Fall: storniert, nie versandt, Geld zurück — abschließbar.
     *
     * Über den echten Weg: Status speichern, dann onsubmit. Mit der
     * Stornierung nur im Status scheiterte genau dieser Fall — der
     * abschließende Status schuldete wieder den vollen Betrag.
     */
    public function testStorniertUndErstattetLaesstSichAbschliessen(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '100.0000']);
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '-100.0000']);
        $this->storeStatus(self::CANCELLED);

        self::assertSame(self::COMPLETED, $this->listener()->save(self::COMPLETED, $this->dc()));
    }

    public function testDieStornierungBleibtNachDemNaechstenStatus(): void
    {
        $this->storeStatus(self::CANCELLED);
        $this->storeStatus(self::IN_REVIEW);

        self::assertNotNull($this->connection->fetchOne('SELECT cancelledAt FROM tl_consho_order WHERE id = ?', [self::ORDER]));
        self::assertSame(self::COMPLETED, $this->listener()->save(self::COMPLETED, $this->dc()));
    }

    public function testStornierenMitNochNichtErstatteterZahlungGehtAberAbschliessenNicht(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '100.0000']);

        // Stornieren prüft nur, ob etwas versandt ist.
        $this->storeStatus(self::CANCELLED);

        $this->expectExceptionMessageMatches('/100,00\s€ sind zu erstatten/u');

        $this->listener()->save(self::COMPLETED, $this->dc());
    }

    public function testNachDemVersandWirdNichtStorniert(): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => self::ORDER, 'reportedAt' => 1_790_000_000]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1']);

        $this->expectExceptionMessage('es ist bereits Ware versandt');

        $this->listener()->save(self::CANCELLED, $this->dc());
    }

    public function testEinStatusOhneFlagsIstFrei(): void
    {
        self::assertSame(self::IN_REVIEW, $this->listener()->save(self::IN_REVIEW, $this->dc()));
    }

    /**
     * Eine Rückbuchung nach „Bezahlt" darf das Speichern anderer Felder
     * nicht blockieren — geprüft wird nur ein Wechsel.
     */
    public function testUnveraenderterStatusWirdNichtGeprueft(): void
    {
        $this->connection->update('tl_consho_order', ['orderStatus' => self::PAID], ['id' => self::ORDER]);

        self::assertSame(self::PAID, $this->listener()->save(self::PAID, $this->dc()));
    }

    /**
     * Abo-7b, Review S1: Die Stornierung im Backend gibt eine übernommene
     * Lizenz zurück — mit dem echten Rückbau, nicht einem Doppel.
     */
    public function testDieStornierungEinerVerlaengerungGibtDieLizenzZurueck(): void
    {
        // Both tables come with WithdrawalSchema (from the DCAs).
        $this->connection->insert('tl_consho_subscription', ['id' => 40, 'subscriptionNumber' => 'WEB-00003-1', 'firstOrder' => 3, 'orderItem' => 7, 'periodUntil' => 500, 'renewedBy' => 44]);
        $this->connection->insert('tl_consho_subscription', ['id' => 44, 'subscriptionNumber' => 'WEB-00005-1', 'firstOrder' => self::ORDER, 'predecessor' => 40]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 70, 'pid' => self::ORDER, 'subscription' => 44, 'validUntil' => 900]);

        $cancelled = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_order_status WHERE isCancelled = 1');
        $listener = new OrderStatusListener(
            $this->connection,
            new DbalOrderStatusRepository($this->connection),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            new MoneyFormatter(),
            // The rollback is real; its question to the delinquency check is
            // SubscriptionPurchaseCancellationTest's (this order table is too thin).
            new OrderCancellation($this->connection, new SubscriptionPurchaseCancellation($this->connection, $this->createStub(SubscriptionDelinquency::class))),
        );
        $this->connection->update('tl_consho_order', ['orderStatus' => $cancelled], ['id' => self::ORDER]);
        $listener->recordCancellation($this->dc());

        self::assertSame([40, 500], array_map('intval', array_values($this->connection->fetchAssociative('SELECT subscription, validUntil FROM tl_consho_entitlement') ?: [])));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 40'));

        // Ein zweites Speichern storniert nicht noch einmal und baut nichts zurück.
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 99], ['id' => 40]);
        $listener->recordCancellation($this->dc());
        self::assertSame(99, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 40'));
    }

    /**
     * Block G (G9): Die Stornierung gibt die Ware zurück — einmal, auch wenn
     * der Status zweimal gespeichert wird.
     */
    public function testDieStornierungGibtDenBestandEinmalZurueck(): void
    {
        $stock = $this->createMock(StockReturn::class);
        // Mit dem Backend-Benutzer, der den Status gesetzt hat (Abnahme G-8).
        $stock->expects(self::once())->method('afterCancellation')->with(self::ORDER, self::anything(), 5);

        $user = $this->createStub(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 5 : null);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $listener = new OrderStatusListener(
            $this->connection,
            new DbalOrderStatusRepository($this->connection),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            new MoneyFormatter(),
            new OrderCancellation($this->connection, stock: $stock),
            $security,
        );
        $this->connection->update('tl_consho_order', ['orderStatus' => self::CANCELLED], ['id' => self::ORDER]);

        $listener->recordCancellation($this->dc());
        $listener->recordCancellation($this->dc());
    }

    /**
     * Block H (H-5): Die Stornierung gibt die Einlösung eines Rabattcodes
     * zurück — einmal.
     */
    public function testDieStornierungGibtDieEinloesungZurueck(): void
    {
        $discounts = $this->createMock(CartDiscounts::class);
        $discounts->expects(self::once())->method('afterCancellation')->with(self::ORDER, self::anything());

        $listener = new OrderStatusListener(
            $this->connection,
            new DbalOrderStatusRepository($this->connection),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            new MoneyFormatter(),
            new OrderCancellation($this->connection, discounts: $discounts),
        );
        $this->connection->update('tl_consho_order', ['orderStatus' => self::CANCELLED], ['id' => self::ORDER]);

        $listener->recordCancellation($this->dc());
        $listener->recordCancellation($this->dc());
    }

    /**
     * WG-6 (6.6, D2): Die Stornierung bucht die Gutscheinzahlungen zurück und
     * sperrt gekaufte Gutscheine — einmal.
     */
    public function testDieStornierungNimmtDieGutscheineMit(): void
    {
        $vouchers = $this->createMock(VoucherReturn::class);
        $vouchers->expects(self::once())->method('afterCancellation')->with(self::ORDER, self::anything());

        $listener = new OrderStatusListener(
            $this->connection,
            new DbalOrderStatusRepository($this->connection),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            new MoneyFormatter(),
            new OrderCancellation($this->connection, vouchers: $vouchers),
        );
        $this->connection->update('tl_consho_order', ['orderStatus' => self::CANCELLED], ['id' => self::ORDER]);

        $listener->recordCancellation($this->dc());
        $listener->recordCancellation($this->dc());
    }

    /**
     * Zweites Review, S-3: Scheitert der Rückbau, bleibt auch die Stornierung
     * aus — sonst ließe die Bedingung `cancelledAt IS NULL` ihn nie wieder laufen.
     */
    public function testScheitertDerRueckbauBleibtDieStornierungAus(): void
    {
        $rollback = $this->createStub(SubscriptionPurchaseCancellation::class);
        $rollback->method('afterCancellation')->willThrowException(new \RuntimeException('database gone'));

        $listener = new OrderStatusListener(
            $this->connection,
            new DbalOrderStatusRepository($this->connection),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            new MoneyFormatter(),
            new OrderCancellation($this->connection, $rollback),
        );
        $this->connection->update('tl_consho_order', ['orderStatus' => self::CANCELLED], ['id' => self::ORDER]);

        try {
            $listener->recordCancellation($this->dc());
            self::fail('the exception goes on');
        } catch (\RuntimeException $e) {
            self::assertSame('database gone', $e->getMessage());
        }

        self::assertNull($this->connection->fetchOne('SELECT cancelledAt FROM tl_consho_order WHERE id = ?', [self::ORDER]));
    }

    /**
     * Wie DC_Table: Speicher-Callback, Schreiben, dann onsubmit.
     */
    private function storeStatus(int $status): void
    {
        $listener = $this->listener();
        $value = $listener->save($status, $this->dc());
        $this->connection->update('tl_consho_order', ['orderStatus' => $value], ['id' => self::ORDER]);
        $listener->recordCancellation($this->dc());
    }

    private function listener(): OrderStatusListener
    {
        return new OrderStatusListener(
            $this->connection,
            new DbalOrderStatusRepository($this->connection),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            new MoneyFormatter(),
        );
    }

    private function dc(): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? self::ORDER : null);
        $dc->method('getCurrentRecord')->willReturn(['id' => self::ORDER, 'shop' => 1]);

        return $dc;
    }
}
