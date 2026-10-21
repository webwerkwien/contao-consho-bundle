<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Document\DeferredArchive;
use Webwerkwien\ContaoConshoBundle\Document\DeliveryNoteIssuer;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderEvent;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * „Als versandt melden" (D-1, Spec D 5.4, Entscheidungen Z2, Z4, A2).
 */
final class DeliveryReportedTest extends TestCase
{
    private const ORDER = 7;

    private Connection $connection;

    private DocumentTrigger $trigger = DocumentTrigger::OnShipment;

    /** @var list<int> */
    private array $issued = [];

    /** @var list<OrderEvent> */
    private array $advanced = [];

    /** @var list<array{array<string, mixed>, int|null}> */
    private array $mails = [];

    /** @var list<int> */
    private array $archived = [];

    /** @var list<string> what Contao's Versions was asked, in order */
    private array $versions = [];

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

        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'orderNumber' => 'WEB-00081', 'grandTotal' => '60', 'paymentMethod' => 3]);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Wanderstock', 'quantity' => '2']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'productName' => 'Rucksack', 'quantity' => '1']);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => self::ORDER, 'productName' => 'Paket', 'quantity' => '1', 'lineType' => 'shipping']);
    }

    public function testDieErsteMeldungStelltDieRechnungAusUndSagtWasImPaketIst(): void
    {
        $this->parcel(1, [1 => '2'], 'Post AT', 'CA1AT');

        $report = $this->reported()->report(1, 100);

        self::assertSame('reported', $report->outcome);
        self::assertTrue($report->mailSent);
        self::assertSame([self::ORDER], $this->issued);
        self::assertSame([OrderEvent::DeliveryReported], $this->advanced);

        [$delivery, $invoiceId] = $this->mails[0];
        self::assertSame(55, $invoiceId);
        // Außerhalb jeder Transaktion archiviert die Meldung selbst.
        self::assertSame([55], $this->archived);
        self::assertNull($report->toArchive);
        self::assertSame([['quantity' => '2', 'name' => 'Wanderstock']], $delivery['items']);
        self::assertSame('CA1AT', $delivery['trackingNumber']);
        self::assertFalse($delivery['complete'], 'der Rucksack fehlt noch');

        $row = $this->connection->fetchAssociative('SELECT reportedAt, lockedAt FROM tl_consho_delivery WHERE id = 1');
        self::assertSame([100, 100], [(int) $row['reportedAt'], (int) $row['lockedAt']]);
    }

    /**
     * F-4: Die elektronische Freigabe meldet ohne „Versendet"-Mail — und
     * innerhalb ihrer eigenen Transaktion, die DBAL zum Savepoint macht.
     * Rechnung und Statuswechsel laufen wie beim Paket.
     */
    public function testOhneMailInnerhalbEinerTransaktion(): void
    {
        $this->parcel(1, [1 => '2']);

        $this->connection->beginTransaction();
        $report = $this->reported()->report(1, 100, false);
        $this->connection->commit();

        self::assertSame('reported', $report->outcome);
        self::assertFalse($report->mailSent);
        self::assertSame([], $this->mails);
        self::assertSame([self::ORDER], $this->issued);
        self::assertSame([OrderEvent::DeliveryReported], $this->advanced);
        self::assertSame(100, (int) $this->connection->fetchOne('SELECT reportedAt FROM tl_consho_delivery WHERE id = 1'));

        // Review Abo-4, S2: innerhalb der Transaktion des Aufrufers kein PDF —
        // ein späterer Rollback gäbe die Nummer frei, die Datei bliebe. Der
        // Aufrufer archiviert nach SEINEM Commit.
        self::assertSame([], $this->archived);
        self::assertSame(55, $report->toArchive);
    }

    /**
     * Zweites Review Abo-4: auch der Zweig MIT Mail gibt einem Aufrufer in
     * einer Transaktion die Rechnung zurück — sonst ginge sie dort still
     * ohne PDF durch.
     */
    public function testMitMailInnerhalbEinerTransaktionAuchZurueckgegeben(): void
    {
        $this->parcel(1, [1 => '2']);

        $this->connection->beginTransaction();
        $report = $this->reported()->report(1, 100);
        $this->connection->commit();

        self::assertTrue($report->mailSent);
        self::assertSame([], $this->archived);
        self::assertSame(55, $report->toArchive);
    }

    public function testDieZweiteSendungSchliesstAbOhneZweiteRechnung(): void
    {
        $this->parcel(1, [1 => '2']);
        $this->reported()->report(1, 100);

        $this->parcel(2, [2 => '1']);
        $this->reported()->report(2, 200);

        self::assertCount(1, $this->issued);
        self::assertNull($this->mails[1][1]);
        self::assertTrue($this->mails[1][0]['complete']);
    }

    public function testBeiVorkasseEntstehtBeimVersandKeineRechnung(): void
    {
        $this->trigger = DocumentTrigger::OnOrder;
        $this->parcel(1, [1 => '2']);

        $this->reported()->report(1, 100);

        self::assertSame([], $this->issued);
        self::assertCount(1, $this->mails);
    }

    public function testMehrAlsOffenIstWirdNichtGemeldet(): void
    {
        $this->parcel(1, [1 => '3']);

        $report = $this->reported()->report(1, 100);

        self::assertSame('tooMuch', $report->outcome);
        self::assertSame(['Wanderstock'], $report->tooMuch);
        self::assertNull($this->connection->fetchOne('SELECT reportedAt FROM tl_consho_delivery WHERE id = 1'));
        self::assertSame([], $this->mails);
    }

    /**
     * Spec D, offene Punkte (Review 1, K1): Die Meldung schrieb keine Version
     * der Lieferung, nur die Bestellung bekam eine. Jetzt wie dort: Contaos
     * Versions mit dem Namen „Consho", der Stand vorher und nachher.
     */
    public function testDieMeldungSchreibtEineVersionDerLieferung(): void
    {
        $this->parcel(1, [1 => '1']);

        $this->reported()->report(1, 100);

        self::assertSame(['user:Consho', 'initialize:', 'create:100'], $this->versions, 'initialize vor, create nach dem Schreiben');
    }

    public function testEineAbgelehnteMeldungSchreibtKeineVersion(): void
    {
        $this->parcel(1, [1 => '3']);

        $this->reported()->report(1, 100);

        self::assertSame([], array_values(array_filter($this->versions, static fn (string $call): bool => str_starts_with($call, 'create:'))));
    }

    public function testEineLeereLieferungWirdNichtGemeldet(): void
    {
        $this->parcel(1, [1 => '0']);

        self::assertSame('empty', $this->reported()->report(1, 100)->outcome);
    }

    public function testZweimalMeldenSchicktEineMail(): void
    {
        $this->parcel(1, [1 => '2']);

        $this->reported()->report(1, 100);

        self::assertSame('alreadyReported', $this->reported()->report(1, 101)->outcome);
        self::assertCount(1, $this->mails);
    }

    public function testEineStornierteBestellungHatNichtsZuVersenden(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 50], ['id' => self::ORDER]);
        $this->parcel(1, [1 => '1']);

        self::assertSame('tooMuch', $this->reported()->report(1, 100)->outcome);
        self::assertSame([], $this->issued);
    }

    /**
     * dev#2: Der Lieferschein entsteht in der Meldung, mit dem, was NACH
     * diesem Paket offen ist, und wird nach dem Commit abgelegt.
     */
    public function testDieMeldungStelltDenLieferscheinAusMitDemRest(): void
    {
        $this->parcel(1, [1 => '2']);
        $notes = $this->createMock(DeliveryNoteIssuer::class);
        $notes->expects(self::once())->method('issue')
            ->willReturnCallback(function (int $deliveryId, array $openGoods): int {
                self::assertSame(1, $deliveryId);
                self::assertSame([2], array_keys(array_filter($openGoods, static fn (string $q): bool => (float) $q > 0)), 'der Rucksack folgt');
                self::assertTrue($this->connection->isTransactionActive(), 'in der Transaktion der Meldung');

                return 77;
            });

        $this->reported($notes)->report(1, 100);

        self::assertSame([55, 77], $this->archived, 'Rechnung und Lieferschein, nach dem Commit');
    }

    public function testEineAbgelehnteMeldungStelltKeinenLieferscheinAus(): void
    {
        $this->parcel(1, [1 => '3']);
        $notes = $this->createMock(DeliveryNoteIssuer::class);
        $notes->expects(self::never())->method('issue');

        self::assertSame('tooMuch', $this->reported($notes)->report(1, 100)->outcome);
    }

    /**
     * @param array<int, string> $items
     */
    private function parcel(int $id, array $items, string $carrier = '', string $tracking = ''): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => $id, 'pid' => self::ORDER, 'carrier' => $carrier, 'trackingNumber' => $tracking]);

        foreach ($items as $item => $quantity) {
            $this->connection->insert('tl_consho_delivery_item', ['pid' => $id, 'orderItem' => $item, 'quantity' => $quantity]);
        }
    }

    private function reported(DeliveryNoteIssuer|null $notes = null): DeliveryReported
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturnCallback(function (int $id): array|null {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]);

            return false === $row ? null : array_map(static fn ($v): string => (string) $v, $row);
        });

        $methods = $this->createStub(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturn(new PaymentMethod(3, 'fake', 'Nachnahme', []));

        $handler = $this->createStub(PaymentHandlerInterface::class);
        $handler->method('documentTrigger')->willReturnCallback(fn (): DocumentTrigger => $this->trigger);
        $handlers = new PaymentHandlers(new ServiceLocator(['fake' => static fn () => $handler]), '1');

        $documents = $this->createStub(DocumentIssuerInterface::class);
        $documents->method('hasDocument')->willReturnCallback(fn (): bool => [] !== $this->issued);
        $documents->method('issueOnce')->willReturnCallback(function (int $orderId): int {
            $this->issued[] = $orderId;

            return 55;
        });

        $statuses = $this->createStub(OrderStatusAdvancer::class);
        $statuses->method('advance')->willReturnCallback(function (int $orderId, OrderEvent $event): null {
            $this->advanced[] = $event;

            return null;
        });

        $archiver = $this->createStub(DocumentArchiverInterface::class);
        $archiver->method('archive')->willReturnCallback(function (int $id): void {
            $this->archived[] = $id;
        });

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/x');

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('shipped')->willReturnCallback(function (int $orderId, array $delivery, int|null $invoiceId): bool {
            $this->mails[] = [$delivery, $invoiceId];

            return true;
        });

        return new DeliveryReported(
            $this->connection,
            $orders,
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            $methods,
            $handlers,
            $documents,
            $archiver,
            $statuses,
            $links,
            $mail,
            new MoneyFormatter(),
            $this->createStub(LoggerInterface::class),
            $this->framework(),
            $this->createStub(UrlGeneratorInterface::class),
            $notes,
            new DeferredArchive($this->connection, static fn () => $archiver),
        );
    }

    private function framework(): ContaoFramework
    {
        $reportedAt = fn (): string => (string) $this->connection->fetchOne('SELECT reportedAt FROM tl_consho_delivery WHERE id = 1');

        $versions = $this->createStub(Versions::class);
        $versions->method('setUsername')->willReturnCallback(function (string $name): void {
            $this->versions[] = 'user:'.$name;
        });
        $versions->method('initialize')->willReturnCallback(function () use ($reportedAt): void {
            $this->versions[] = 'initialize:'.$reportedAt();
        });
        $versions->method('create')->willReturnCallback(function () use ($reportedAt): void {
            $this->versions[] = 'create:'.$reportedAt();
        });

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('createInstance')->willReturn($versions);

        return $framework;
    }
}
