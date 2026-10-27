<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\BackendUser;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentRepository;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderStatusListener;
use Webwerkwien\ContaoConshoBundle\Order\CancellationInvoice;
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
 * The status field of the order (D-1, A1).
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
        // From the DCA (WG-7), not a copy.
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
     * The case: cancelled, never shipped, money back — closable.
     *
     * Along the real path: save the status, then onsubmit. With the
     * cancellation only in the status, exactly this case failed — the closing
     * status owed the full amount again.
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

        // Cancelling only checks whether anything has been shipped.
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
     * A chargeback after "Paid" must not block saving other fields — only a
     * change is checked.
     */
    public function testUnveraenderterStatusWirdNichtGeprueft(): void
    {
        $this->connection->update('tl_consho_order', ['orderStatus' => self::PAID], ['id' => self::ORDER]);

        self::assertSame(self::PAID, $this->listener()->save(self::PAID, $this->dc()));
    }

    /**
     * Abo-7b: cancelling in the backend returns a taken-over licence — with the
     * real rollback, not a double.
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

        // A second save does not cancel again and rolls nothing back.
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 99], ['id' => 40]);
        $listener->recordCancellation($this->dc());
        self::assertSame(99, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 40'));
    }

    /**
     * Block G (G9): the cancellation returns the goods — once, even if the
     * status is saved twice.
     */
    public function testDieStornierungGibtDenBestandEinmalZurueck(): void
    {
        $stock = $this->createMock(StockReturn::class);
        // With the backend user who set the status (G-8).
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
     * Block H (H-5): the cancellation returns the redemption of a discount
     * code — once.
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
     * WG-6 (6.6, D2): the cancellation reverses the voucher payments and locks
     * purchased vouchers — once.
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
     * If the rollback fails, the cancellation does not happen either —
     * otherwise the condition `cancelledAt IS NULL` would never let it run again.
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
     * dev#1: if the order cancels the open invoice along with it, the status
     * may only stand if the cancellation document can be issued as well.
     */
    public function testStornoMitRechnungskorrekturWirdAbgelehnt(): void
    {
        $this->invoiceSchema();
        $this->connection->insert('tl_consho_document', ['id' => 30, 'pid' => self::ORDER, 'shop' => 1, 'type' => 'invoice', 'documentNumber' => 'R-1']);
        $this->connection->insert('tl_consho_document', ['id' => 31, 'pid' => self::ORDER, 'shop' => 1, 'type' => 'creditNote', 'relatedDocument' => 30, 'documentNumber' => 'RK-1']);

        $this->expectExceptionMessage('„Storniert" kann nicht gesetzt werden: es gibt bereits eine Rechnungskorrektur.');

        $this->listener($this->invoiceNeverCancelled())->save(self::CANCELLED, $this->dc());
    }

    public function testStornoOhneStornoNummernkreisWirdAbgelehnt(): void
    {
        $this->invoiceSchema();
        $this->connection->executeStatement('DELETE FROM tl_consho_number_range');
        $this->connection->insert('tl_consho_document', ['id' => 30, 'pid' => self::ORDER, 'shop' => 1, 'type' => 'invoice', 'documentNumber' => 'R-1']);

        $this->expectExceptionMessage('„Storniert" kann nicht gesetzt werden: kein Nummernkreis für Stornos.');

        $this->listener($this->invoiceNeverCancelled())->save(self::CANCELLED, $this->dc());
    }

    public function testStornoMitOffenerRechnungGeht(): void
    {
        $this->invoiceSchema();
        $this->connection->insert('tl_consho_document', ['id' => 30, 'pid' => self::ORDER, 'shop' => 1, 'type' => 'invoice', 'documentNumber' => 'R-1']);

        // The save callback only checks; issuing happens only in onsubmit (OrderCancellation).
        self::assertSame(self::CANCELLED, $this->listener($this->invoiceNeverCancelled())->save(self::CANCELLED, $this->dc()));
    }

    private function invoiceSchema(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_number_range');
        $this->connection->insert('tl_consho_number_range', ['id' => 1, 'pid' => 1, 'type' => 'cancellation', 'prefix' => 'ST-']);
        $GLOBALS['TL_LANG']['tl_consho_order']['cancelInvoiceRefused'] = [
            'corrected' => 'es gibt bereits eine Rechnungskorrektur',
            'numberRangeMissing' => 'kein Nummernkreis für Stornos',
        ];
    }

    private function invoiceNeverCancelled(): CancellationInvoice
    {
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->expects(self::never())->method('cancel');

        return new CancellationInvoice($this->connection, new DbalDocumentRepository($this->connection), $issuer);
    }

    /**
     * Like DC_Table: save callback, writing, then onsubmit.
     */
    private function storeStatus(int $status): void
    {
        $listener = $this->listener();
        $value = $listener->save($status, $this->dc());
        $this->connection->update('tl_consho_order', ['orderStatus' => $value], ['id' => self::ORDER]);
        $listener->recordCancellation($this->dc());
    }

    private function listener(CancellationInvoice|null $invoice = null): OrderStatusListener
    {
        return new OrderStatusListener(
            $this->connection,
            new DbalOrderStatusRepository($this->connection),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            new MoneyFormatter(),
            invoice: $invoice,
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
