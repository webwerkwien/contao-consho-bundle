<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DbalPaymentChangeRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The queries behind 7.5 against SQLite. Not lockOrder(): SQLite has no
 * FOR UPDATE, the service above it is tested with an in-memory repository.
 */
class DbalPaymentChangeRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        DcaSchema::create($this->connection, 'tl_consho_payment_attempt');
        // From the DCA (WG-7), not a copy.
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment');
        $this->connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY, "groups" BLOB NULL)');
    }

    /**
     * Built from the DCA, with the production column names: reportedAt is NULL
     * until reported, not 0.
     */
    public function testGemeldetIstNurEineLieferungMitMeldung(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        $repository = new DbalPaymentChangeRepository($this->connection);

        $this->connection->insert('tl_consho_delivery', ['pid' => 1]);
        self::assertFalse($repository->hasReportedDelivery(1), 'angelegt, nicht gemeldet');

        $this->connection->insert('tl_consho_delivery', ['pid' => 2, 'reportedAt' => 1_790_000_000]);
        self::assertTrue($repository->hasReportedDelivery(2));
        self::assertFalse($repository->hasReportedDelivery(3));
    }

    public function testKaufUndFolgebestellungGehoerenZumAbo(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        $repository = new DbalPaymentChangeRepository($this->connection);

        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090']);
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'subscription' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 92, 'orderNumber' => 'WEB-00092']);
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'firstOrder' => 90]);

        self::assertTrue($repository->belongsToSubscription(90), 'Kauf');
        self::assertTrue($repository->belongsToSubscription(91), 'Folgebestellung');
        self::assertFalse($repository->belongsToSubscription(92));
    }

    /**
     * Nachlese: one-off entitlements against the real query — subscription
     * entitlements and blocked ones do not count.
     */
    public function testEinmalBerechtigungen(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_entitlement');
        $repository = new DbalPaymentChangeRepository($this->connection);

        $this->connection->insert('tl_consho_entitlement', ['pid' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 2, 'subscription' => 4]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 3, 'revoked' => 1]);

        self::assertTrue($repository->hasOneTimeEntitlements(1));
        self::assertFalse($repository->hasOneTimeEntitlements(2), 'die eines Abos');
        self::assertFalse($repository->hasOneTimeEntitlements(3), 'gesperrt');
        self::assertFalse($repository->hasOneTimeEntitlements(4), 'keine');
    }

    public function testNurDieZahlungszeilenWerdenErsetztUndKommenAnsEnde(): void
    {
        $this->connection->insert('tl_consho_order_item', ['pid' => 1, 'sorting' => 128, 'lineType' => 'product', 'productName' => 'Tee']);
        $this->connection->insert('tl_consho_order_item', ['pid' => 1, 'sorting' => 256, 'lineType' => 'shipping', 'productName' => 'Paket']);
        $this->connection->insert('tl_consho_order_item', ['pid' => 1, 'sorting' => 384, 'lineType' => 'paymentDiscount', 'productName' => 'Alt']);
        $this->connection->insert('tl_consho_order_item', ['pid' => 2, 'sorting' => 128, 'lineType' => 'paymentDiscount', 'productName' => 'Fremd']);

        (new DbalPaymentChangeRepository($this->connection))->replacePaymentLines(1, [['lineType' => 'paymentDiscount', 'productName' => 'Neu']], 5);

        self::assertSame(
            [['Tee', 128], ['Paket', 256], ['Neu', 384]],
            array_map(static fn (array $r): array => [$r['productName'], (int) $r['sorting']], $this->connection->fetchAllAssociative('SELECT productName, sorting FROM tl_consho_order_item WHERE pid = 1 ORDER BY sorting')),
        );
        self::assertSame('Fremd', $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE pid = 2'));
    }

    public function testEinStatusHaeltBeiSperreBezahltOderStorniertNichtBeiVersendet(): void
    {
        $this->connection->insert('tl_consho_order_status', ['id' => 1, 'locksChanges' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => 2, 'isPaid' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => 3, 'isCancelled' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => 4, 'isShipped' => 1]);

        $repository = new DbalPaymentChangeRepository($this->connection);

        self::assertTrue($repository->statusHolds(1));
        self::assertTrue($repository->statusHolds(2));
        self::assertTrue($repository->statusHolds(3));
        self::assertFalse($repository->statusHolds(4));
    }

    public function testOffenSindOffeneUndWartendeVersuche(): void
    {
        foreach (['open', 'pending', 'failed', 'cancelled', 'succeeded'] as $i => $state) {
            // tokenHash is unique in the DCA; every real attempt carries one.
            $this->connection->insert('tl_consho_payment_attempt', ['id' => $i + 1, 'pid' => 1, 'methodType' => 'prepayment', 'state' => $state, 'tokenHash' => hash('sha256', $state)]);
        }

        $repository = new DbalPaymentChangeRepository($this->connection);

        self::assertSame([1, 2], array_map(static fn ($a): int => $a->id, $repository->openAttempts(1)));

        $repository->cancelAttempt(1, 9);

        self::assertSame('cancelled', $this->connection->fetchOne('SELECT state FROM tl_consho_payment_attempt WHERE id = 1'));
        self::assertSame([2], array_map(static fn ($a): int => $a->id, $repository->openAttempts(1)));
    }

    public function testGruppenUndZahlungen(): void
    {
        $this->connection->insert('tl_member', ['id' => 1, '"groups"' => serialize(['2', '3'])]);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '5.5000']);

        $repository = new DbalPaymentChangeRepository($this->connection);

        self::assertSame([2, 3], $repository->memberGroups(1));
        self::assertSame([], $repository->memberGroups(0));
        self::assertCount(1, $repository->paymentAmounts(1));
        self::assertSame([], $repository->paymentAmounts(2));
    }
}
