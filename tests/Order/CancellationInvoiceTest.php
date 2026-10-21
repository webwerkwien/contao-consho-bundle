<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentRepository;
use Webwerkwien\ContaoConshoBundle\Document\DeferredArchive;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Order\CancellationInvoice;
use Webwerkwien\ContaoConshoBundle\Order\CancellationInvoiceRefusal;
use Webwerkwien\ContaoConshoBundle\Order\OrderCancellation;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * dev#1: Ein Storno über den Status storniert die offene Rechnung mit
 * (Spec A: `isCancelled` → Stornobeleg). Bis dahin blieb eine Vorkasse-Rechnung
 * zu einer stornierten Bestellung stehen.
 */
final class CancellationInvoiceTest extends TestCase
{
    private Connection $connection;

    /** @var list<int> */
    private array $archived = [];

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_number_range');
        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 2, 'orderNumber' => 'B-1']);
        $this->connection->insert('tl_consho_number_range', ['id' => 1, 'pid' => 2, 'type' => 'cancellation', 'prefix' => 'ST-']);
    }

    public function testOhneRechnungGibtEsNichtsZuStornieren(): void
    {
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->expects(self::never())->method('cancel');

        self::assertNull($this->invoice($issuer)->refusal(7));
        self::assertNull($this->invoice($issuer)->issue(7));
    }

    public function testDieOffeneRechnungWirdStorniert(): void
    {
        $this->document(30, 'invoice');
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->expects(self::once())->method('cancel')->with(30)->willReturn(31);

        self::assertNull($this->invoice($issuer)->refusal(7));
        self::assertSame(31, $this->invoice($issuer)->issue(7));
    }

    public function testEineSchonStornierteRechnungBleibtInRuhe(): void
    {
        // Der Vollwiderruf hat sie schon storniert: findIdByOrderAndType findet keine offene.
        $this->document(30, 'invoice');
        $this->document(31, 'cancellation', 30);
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->expects(self::never())->method('cancel');

        self::assertNull($this->invoice($issuer)->issue(7));
    }

    public function testMitRechnungskorrekturWirdAbgelehnt(): void
    {
        $this->document(30, 'invoice');
        $this->document(32, 'creditNote', 30);
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->expects(self::never())->method('cancel');

        self::assertSame(CancellationInvoiceRefusal::Corrected, $this->invoice($issuer)->refusal(7));
        self::assertNull($this->invoice($issuer)->issue(7), 'automatisch: Rechnung bleibt, Fehlerprotokoll sagt warum');
    }

    public function testOhneStornoNummernkreisWirdAbgelehnt(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_number_range');
        $this->document(30, 'invoice');
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->expects(self::never())->method('cancel');

        self::assertSame(CancellationInvoiceRefusal::NumberRangeMissing, $this->invoice($issuer)->refusal(7));
    }

    public function testDerStornoBelegWirdNachDemCommitAbgelegt(): void
    {
        $this->document(30, 'invoice');
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->method('cancel')->willReturn(31);
        $archiver = $this->createMock(DocumentArchiverInterface::class);
        $archiver->expects(self::once())->method('archive')->with(31)
            ->willReturnCallback(fn () => self::assertSame(0, $this->connection->getTransactionNestingLevel(), 'erst nach dem Commit'));

        $cancellation = new OrderCancellation($this->connection, invoice: $this->invoice($issuer), archive: new DeferredArchive($this->connection, static fn () => $archiver));

        self::assertTrue($cancellation->record(7, 1000));
        self::assertFalse($cancellation->record(7, 2000), 'ein zweiter Storno stellt nichts mehr aus');
    }

    /**
     * Review dev#1, Befund 1: In „Mehrere bearbeiten" (eine Transaktion um
     * alles) nicht in der Transaktion ablegen, aber auch nicht nur auf
     * `--retry` vormerken — das übergibt nicht an die Buchhaltung. Abgelegt
     * wird am Ende der Anfrage, über den dekorierten Archivierer.
     */
    public function testInEinerFremdenTransaktionWirdErstAmEndeAbgelegt(): void
    {
        $this->document(30, 'invoice');
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->method('cancel')->willReturn(31);
        $archiver = $this->createMock(DocumentArchiverInterface::class);
        $archiver->method('archive')->willReturnCallback(function (int $id): void {
            self::assertFalse($this->connection->isTransactionActive());
            $this->archived[] = $id;
        });
        $deferred = new DeferredArchive($this->connection, static fn () => $archiver);

        $this->connection->beginTransaction();
        (new OrderCancellation($this->connection, invoice: $this->invoice($issuer), archive: $deferred))->record(7, 1000);
        $deferred->archivePending();
        self::assertSame([], $this->archived, 'solange die Transaktion offen ist, nichts');

        $this->connection->commit();
        $deferred->archivePending();

        self::assertSame([31], $this->archived);
    }

    public function testEineSchonStornierteBestellungWirdNichtAbgelehnt(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 500], ['id' => 7]);
        $this->connection->executeStatement('DELETE FROM tl_consho_number_range');
        $this->document(30, 'invoice');

        self::assertNull($this->invoice($this->createMock(DocumentIssuerInterface::class))->refusal(7), 'record() täte ohnehin nichts mehr');
    }

    public function testScheitertDerStornoBelegBleibtDieBestellungUnstorniert(): void
    {
        $this->document(30, 'invoice');
        $issuer = $this->createMock(DocumentIssuerInterface::class);
        $issuer->method('cancel')->willThrowException(new \RuntimeException('kaputt'));

        try {
            (new OrderCancellation($this->connection, invoice: $this->invoice($issuer)))->record(7, 1000);
            self::fail('erwartet: Ausnahme');
        } catch (\RuntimeException) {
        }

        self::assertNull($this->connection->fetchOne('SELECT cancelledAt FROM tl_consho_order WHERE id = 7'), 'Rollback nimmt cancelledAt mit');
    }

    private function invoice(DocumentIssuerInterface $issuer): CancellationInvoice
    {
        return new CancellationInvoice($this->connection, new DbalDocumentRepository($this->connection), $issuer);
    }

    private function document(int $id, string $type, int $related = 0): void
    {
        $this->connection->insert('tl_consho_document', ['id' => $id, 'pid' => 7, 'shop' => 2, 'type' => $type, 'relatedDocument' => $related, 'documentNumber' => 'X-'.$id]);
    }
}
