<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentStore;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiver;
use Webwerkwien\ContaoConshoBundle\Document\DocumentDelivery;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceRenderer;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Ablage und Auslieferung (8.3), zusammen, weil das eine nur mit dem anderen
 * etwas bedeutet: Abgelegt wird, damit ausgeliefert werden kann, was beim
 * Ausstellen entstand.
 */
class DocumentArchiveTest extends TestCase
{
    private const FOLDER_UUID = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120055';

    private Connection $connection;

    private string $root;

    /** @var array<string, string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/consho-archive-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->root.'/files/rechnungen');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT '', path TEXT NOT NULL DEFAULT '')");

        $this->connection->insert('tl_consho_order', ['id' => 9, 'language' => 'de']);
        $this->connection->insert('tl_consho_document', ['id' => 1, 'pid' => 9, 'shop' => 3, 'documentNumber' => 'RE-00007', 'payload' => '{}']);
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'invoiceArchive' => 1, 'invoiceArchiveFolder' => StringUtil::uuidToBin(self::FOLDER_UUID)]);
        $this->connection->insert('tl_files', ['uuid' => StringUtil::uuidToBin(self::FOLDER_UUID), 'type' => 'folder', 'path' => 'files/rechnungen']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testDasPdfWirdImOrdnerAbgelegtUndMitPruefsummeVermerkt(): void
    {
        $this->archiver()->archive(1);

        self::assertSame(['rechnungen/RE-00007.pdf' => '%PDF-original'], $this->written);
        self::assertSame(
            ['archiveState' => 'stored', 'archivedPath' => 'rechnungen/RE-00007.pdf', 'archivedHash' => hash('sha256', '%PDF-original')],
            $this->archiveColumns(),
        );
    }

    /**
     * Ein Shop ohne Ablage: Der Beleg ist KEINE gescheiterte Ablage — sonst
     * warnte jede Rechnung aus der Zeit vor dem Einschalten für immer.
     */
    public function testOhneAblageBleibtDerBelegUnberuehrt(): void
    {
        $this->connection->update('tl_consho_shop', ['invoiceArchive' => 0], ['id' => 3]);

        $this->archiver()->archive(1);

        self::assertSame([], $this->written);
        self::assertSame('', $this->archiveColumns()['archiveState']);
    }

    /**
     * 🔴 In einen öffentlichen Ordner wird NIE geschrieben — auch wenn er erst
     * nach der Auswahl öffentlich wurde.
     */
    public function testInEinenOeffentlichenOrdnerWirdNichtGeschrieben(): void
    {
        touch($this->root.'/files/rechnungen/.public');

        $this->archiver(expectError: true)->archive(1);

        self::assertSame([], $this->written);
        self::assertSame('failed', $this->archiveColumns()['archiveState']);
    }

    /**
     * Eine vorhandene Datei wird nicht überschrieben — zwei Shops mit
     * gemeinsamem Ordner und gleichem Präfix ersetzten sonst still einander
     * die Rechnungen.
     */
    public function testEineVorhandeneDateiWirdNichtUeberschrieben(): void
    {
        $this->written['rechnungen/RE-00007.pdf'] = '%PDF-fremd';

        $this->archiver(expectError: true)->archive(1);

        self::assertSame('%PDF-fremd', $this->written['rechnungen/RE-00007.pdf']);
        self::assertSame('failed', $this->archiveColumns()['archiveState']);
    }

    /**
     * Scheitert das Schreiben, wirft die Ablage NICHT — der Kauf, der die
     * Rechnung ausgestellt hat, steht. Vermerkt und geloggt wird es.
     */
    public function testEinFehlerBeimSchreibenWirdVermerktNichtGeworfen(): void
    {
        $this->archiver(writeFails: true, expectError: true)->archive(1);

        self::assertSame('failed', $this->archiveColumns()['archiveState']);
    }

    public function testDieAbgelegteDateiWirdAusgeliefertSolangeSieStimmt(): void
    {
        $this->archiver()->archive(1);

        $delivered = $this->delivery()->pdf(1);
        self::assertNotNull($delivered);

        self::assertSame('%PDF-original', $delivered['pdf']);
        self::assertTrue($delivered['fromArchive']);
        self::assertFalse($delivered['archiveProblem']);
    }

    /**
     * 🔴 Stimmt die Prüfsumme nicht mehr, wird NEU ERZEUGT und so
     * gekennzeichnet — nie still ersetzt.
     */
    public function testEineVeraenderteDateiWirdNichtAusgeliefert(): void
    {
        $this->archiver()->archive(1);
        $this->written['rechnungen/RE-00007.pdf'] = '%PDF-manipuliert';

        $delivered = $this->delivery(expectRegenerated: true)->pdf(1);
        self::assertNotNull($delivered);

        self::assertSame('%PDF-neu', $delivered['pdf']);
        self::assertFalse($delivered['fromArchive']);
        self::assertTrue($delivered['archiveProblem']);
    }

    /**
     * D-5 (Fable-Review S2): Nach der Anonymisierung ist die Datei mit Absicht
     * weg (`removed`). Der Beleg wird aus dem Schnappschuss erzeugt — ohne
     * Hinweis auf ein Problem, das keines ist.
     */
    public function testNachDerAnonymisierungKeinArchivproblem(): void
    {
        $this->connection->update('tl_consho_document', ['archiveState' => 'removed', 'archivedPath' => '', 'archivedHash' => ''], ['id' => 1]);

        // Rendered from the snapshot WITHOUT the problem flag.
        $delivered = $this->delivery(expectRegenerated: false)->pdf(1);

        self::assertNotNull($delivered);
        self::assertFalse($delivered['fromArchive']);
        self::assertFalse($delivered['archiveProblem']);
    }

    public function testEineFehlendeDateiWirdNeuErzeugtUndGekennzeichnet(): void
    {
        $this->archiver()->archive(1);
        unset($this->written['rechnungen/RE-00007.pdf']);

        self::assertTrue($this->delivery(expectRegenerated: true)->pdf(1)['archiveProblem'] ?? null);
    }

    public function testEineGescheiterteAblageWirdNeuErzeugtUndGekennzeichnet(): void
    {
        $this->connection->update('tl_consho_document', ['archiveState' => 'failed'], ['id' => 1]);

        self::assertTrue($this->delivery(expectRegenerated: true)->pdf(1)['archiveProblem'] ?? null);
    }

    /**
     * Ohne Ablage ist das Neuerzeugen der Normalfall (C8) — kein Vermerk.
     */
    public function testOhneAblageWirdUngekennzeichnetErzeugt(): void
    {
        $delivered = $this->delivery(expectRegenerated: false)->pdf(1);
        self::assertNotNull($delivered);

        self::assertFalse($delivered['archiveProblem']);
        self::assertSame('RE-00007.pdf', $delivered['fileName']);
    }

    public function testEinDateinameWirdAusDerNummerOhneUnterordner(): void
    {
        self::assertSame('RE_2026_7.pdf', DocumentArchiver::fileNameOf('RE/2026/7'));
        self::assertSame('document.pdf', DocumentArchiver::fileNameOf('..'));
    }

    private function archiver(bool $writeFails = false, bool $expectError = false): DocumentArchiver
    {
        $renderer = $this->createMock(InvoiceRenderer::class);
        $renderer->method('pdf')->willReturn(['pdf' => '%PDF-original', 'logoMissing' => false]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectError ? self::once() : self::never())->method('error');

        return new DocumentArchiver(
            new DbalDocumentStore($this->connection),
            $renderer,
            $this->files($writeFails),
            new ArchiveFolderProtector($this->root),
            'files',
            $logger,
        );
    }

    private function delivery(bool|null $expectRegenerated = null): DocumentDelivery
    {
        $renderer = $this->createMock(InvoiceRenderer::class);

        if (null === $expectRegenerated) {
            $renderer->expects(self::never())->method('pdf');
        } else {
            $renderer->expects(self::once())->method('pdf')->with(self::anything(), $expectRegenerated)->willReturn(['pdf' => '%PDF-neu', 'logoMissing' => false]);
        }

        return new DocumentDelivery(new DbalDocumentStore($this->connection), $renderer, $this->files(false));
    }

    private function files(bool $writeFails): VirtualFilesystemInterface
    {
        $files = $this->createMock(VirtualFilesystemInterface::class);
        $files->method('fileExists')->willReturnCallback(fn (string $path): bool => isset($this->written[$path]));
        $files->method('read')->willReturnCallback(function (string $path): string {
            return $this->written[$path] ?? throw VirtualFilesystemException::unableToRead($path);
        });
        $files->method('write')->willReturnCallback(function (string $path, string $contents) use ($writeFails): void {
            if ($writeFails) {
                throw VirtualFilesystemException::unableToWrite($path);
            }

            $this->written[$path] = $contents;
        });

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function archiveColumns(): array
    {
        return $this->connection->fetchAssociative('SELECT archiveState, archivedPath, archivedHash FROM tl_consho_document WHERE id = 1') ?: [];
    }
}
