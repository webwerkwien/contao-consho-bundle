<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Handoff;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentStore;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\Handoff\HandingOverArchiver;
use Webwerkwien\ContaoConshoBundle\Document\Handoff\IssuedDocument;
use Webwerkwien\ContaoConshoBundle\Document\Handoff\IssuedDocumentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block R (R13): jeder ausgestellte Beleg geht nach der Ablage an die
 * Buchhaltungsmodule — mit Schnappschuss und PDF, einmal, und ein Modul, das
 * scheitert, hält weder den Beleg noch das nächste Modul auf.
 */
class HandingOverArchiverTest extends TestCase
{
    private Connection $connection;

    /** @var list<string> */
    private array $calls = [];

    private int $rendered = 0;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_document');
        $this->connection->insert('tl_consho_order', ['id' => 7, 'tstamp' => 1, 'shop' => 2, 'orderNumber' => 'WEB-00007', 'language' => 'de']);
        $this->connection->insert('tl_consho_document', [
            'id' => 3, 'pid' => 7, 'tstamp' => 1, 'shop' => 2, 'type' => 'invoice', 'documentNumber' => 'RE-00003',
            'issuedAt' => 1_790_000_000, 'payload' => json_encode(['formatVersion' => 10, 'eInvoice' => true]),
        ]);
    }

    public function testErstAblegenDannUebergebenMitAllemWasEinModulBraucht(): void
    {
        $received = null;
        $handler = $this->handler('buchhaltung', function (IssuedDocument $document) use (&$received): void {
            $received = $document;
        });

        $this->archiver([$handler])->archive(3);

        self::assertSame(['archive 3', 'buchhaltung RE-00003'], $this->calls);
        self::assertInstanceOf(IssuedDocument::class, $received);
        self::assertSame([3, 2, 7, DocumentType::Invoice, 1_790_000_000], [$received->id, $received->shopId, $received->orderId, $received->type, $received->issuedAt]);
        self::assertSame(['formatVersion' => 10, 'eInvoice' => true], $received->payload);

        // Das PDF erst auf Nachfrage, und nur einmal.
        self::assertSame(0, $this->rendered);
        self::assertSame('%PDF-3', $received->pdf());
        self::assertSame('%PDF-3', $received->pdf());
        self::assertSame(1, $this->rendered);
    }

    public function testEinScheiterndesModulHaeltDasNaechsteNichtAuf(): void
    {
        $errors = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string|\Stringable $message) use (&$errors): void {
            $errors[] = (string) $message;
        });

        $failing = $this->handler('kaputt', static function (): void {
            throw new \RuntimeException('sevDesk antwortet nicht');
        });
        $working = $this->handler('zweites', static function (): void {
        });

        $this->archiver([$failing, $working], $logger)->archive(3);

        self::assertSame(['archive 3', 'kaputt RE-00003', 'zweites RE-00003'], $this->calls);
        self::assertCount(1, $errors);
        self::assertStringContainsString('RE-00003: sevDesk antwortet nicht', $errors[0]);
    }

    /**
     * dev#2, Review: Der Lieferschein wird abgelegt, aber nicht übergeben —
     * den Modulen sind Rechnung, Korrektur und Storno versprochen, und ein
     * Beleg ohne Betrag bucht nichts.
     */
    public function testDerLieferscheinWirdAbgelegtAberNichtUebergeben(): void
    {
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 7, 'tstamp' => 1, 'shop' => 2, 'type' => 'deliveryNote', 'documentNumber' => 'LS-00001', 'payload' => '{}']);
        $handler = $this->handler('buchhaltung', static function (): void {
        });

        $this->archiver([$handler])->archive(4);

        self::assertSame(['archive 4'], $this->calls);
    }

    public function testOhneModulWirdNichtsGelesen(): void
    {
        $this->connection->delete('tl_consho_document', ['id' => 3]);

        $this->archiver([])->archive(3);

        self::assertSame(['archive 3'], $this->calls);
    }

    /**
     * @param \Closure(IssuedDocument): void $body
     */
    private function handler(string $name, \Closure $body): IssuedDocumentHandlerInterface
    {
        $record = function (string $call): void {
            $this->calls[] = $call;
        };

        return new class($name, $body, $record) implements IssuedDocumentHandlerInterface {
            public function __construct(private readonly string $name, private readonly \Closure $body, private readonly \Closure $record)
            {
            }

            public function handle(IssuedDocument $document): void
            {
                ($this->record)($this->name.' '.$document->number);
                ($this->body)($document);
            }
        };
    }

    /**
     * @param list<IssuedDocumentHandlerInterface> $handlers
     */
    private function archiver(array $handlers, LoggerInterface|null $logger = null): HandingOverArchiver
    {
        $calls = &$this->calls;
        $inner = $this->createMock(DocumentArchiverInterface::class);
        $inner->method('archive')->willReturnCallback(static function (int $id) use (&$calls): void {
            $calls[] = 'archive '.$id;
        });

        $pdfs = $this->createMock(DocumentPdfInterface::class);
        $pdfs->method('pdf')->willReturnCallback(function (int $id): array {
            ++$this->rendered;

            return ['pdf' => '%PDF-'.$id, 'fileName' => 're.pdf', 'fromArchive' => false, 'archiveProblem' => false, 'logoMissing' => false];
        });

        return new HandingOverArchiver($inner, $handlers, new DbalDocumentStore($this->connection), $pdfs, $logger ?? $this->createMock(LoggerInterface::class));
    }
}
