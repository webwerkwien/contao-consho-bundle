<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Command;

use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Command\DocumentVerifyCommand;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentStore;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentDelivery;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceRenderer;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\ExternalInvoicing;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\LateDocumentMail;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing\FakeInvoiceProvider;

class DocumentVerifyCommandTest extends TestCase
{
    private Connection $connection;

    /** @var array<string, string> */
    private array $files = ['a.pdf' => 'A', 'c.pdf' => 'geändert'];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_order');
    }

    public function testAllesIntaktIstErfolg(): void
    {
        $this->document(1, 'RE-1', 'stored', 'a.pdf', hash('sha256', 'A'));
        $this->document(2, 'RE-2', '', '', '');

        $tester = $this->verify(false, $this->createMock(DocumentArchiverInterface::class));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 archived document(s), all intact', $tester->getDisplay());
    }

    public function testFehlendeVeraenderteUndGescheiterteWerdenGenannt(): void
    {
        $this->document(1, 'RE-1', 'stored', 'b.pdf', 'x');
        $this->document(2, 'RE-2', 'stored', 'c.pdf', hash('sha256', 'original'));
        $this->document(3, 'RE-3', 'failed', '', '');

        $tester = $this->verify(false, $this->createMock(DocumentArchiverInterface::class));
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/RE-1\s+missing/', $display);
        self::assertMatchesRegularExpression('/RE-2\s+changed/', $display);
        self::assertMatchesRegularExpression('/RE-3\s+failed/', $display);
    }

    /**
     * 🔴 --retry ONLY files the failed ones again. Replacing a missing or
     * altered original file with a newly generated PDF would pass off a new
     * document as the original — exactly what the archive rules out.
     */
    public function testRetryLegtNurGescheiterteNach(): void
    {
        $this->document(1, 'RE-1', 'stored', 'b.pdf', 'x');
        $this->document(3, 'RE-3', 'failed', '', '');

        $archiver = $this->createMock(DocumentArchiverInterface::class);
        $archiver->expects(self::once())->method('archive')->with(3);

        $this->verify(true, $archiver);
    }

    public function testRetryBietetAusstehendeBelegeDemModulErneutAnRechnungZuerst(): void
    {
        // Block R (R18): the invoice before the cancellation that waits for it.
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 1, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'fake_books', 'externalState' => 'failed', 'payload' => '{}']);
        $this->connection->insert('tl_consho_document', ['id' => 5, 'pid' => 1, 'shop' => 1, 'type' => 'cancellation', 'relatedDocument' => 4, 'issuer' => 'fake_books', 'externalState' => 'pending', 'payload' => '{}']);
        $provider = new FakeInvoiceProvider();

        $this->verify(true, $this->createMock(DocumentArchiverInterface::class), new ExternalInvoicing(
            $this->connection,
            new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])),
            new NullLogger(),
        ));

        self::assertSame([DocumentType::Invoice, DocumentType::Cancellation], array_map(static fn ($d) => $d->type, $provider->issued));
    }

    public function testNurEinJetztAusgestellterBelegGehtDemKundenNach(): void
    {
        // Block R (R18): the order's mail went out without it.
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 1, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'fake_books', 'externalState' => 'failed', 'payload' => '{}']);
        $this->connection->insert('tl_consho_document', ['id' => 6, 'pid' => 1, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'removed_books', 'externalState' => 'pending', 'payload' => '{}']);
        $provider = new FakeInvoiceProvider();

        $late = $this->createMock(LateDocumentMail::class);
        $late->expects(self::once())->method('send')->with(4)->willReturn(true);

        $this->verify(true, $this->createMock(DocumentArchiverInterface::class), new ExternalInvoicing(
            $this->connection,
            new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])),
            new NullLogger(),
        ), $late);
    }

    public function testEinGeradeGeschriebenerBelegBleibtSeinemKaufUeberlassen(): void
    {
        // Its purchase may still be submitting it and mailing the order.
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 1, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'fake_books', 'externalState' => 'failed', 'payload' => '{}', 'tstamp' => time() - 299]);
        $this->connection->insert('tl_consho_document', ['id' => 5, 'pid' => 2, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'fake_books', 'externalState' => 'pending', 'payload' => '{}', 'tstamp' => time() - 301]);
        $provider = new FakeInvoiceProvider();

        $this->verify(true, $this->createMock(DocumentArchiverInterface::class), new ExternalInvoicing(
            $this->connection,
            new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])),
            new NullLogger(),
        ));

        self::assertSame([5], array_map(static fn ($d) => $d->id, $provider->issued));
    }

    public function testOhneRetryWirdNichtsEingereicht(): void
    {
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 1, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'fake_books', 'externalState' => 'pending', 'payload' => '{}']);
        $provider = new FakeInvoiceProvider();

        $this->verify(false, $this->createMock(DocumentArchiverInterface::class), new ExternalInvoicing(
            $this->connection,
            new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])),
            new NullLogger(),
        ));

        self::assertSame([], $provider->issued);
    }

    private function verify(bool $retry, DocumentArchiverInterface $archiver, ExternalInvoicing|null $external = null, LateDocumentMail|null $late = null): CommandTester
    {
        $files = $this->createMock(VirtualFilesystemInterface::class);
        $files->method('read')->willReturnCallback(fn (string $path): string => $this->files[$path] ?? throw VirtualFilesystemException::unableToRead($path));

        $store = new DbalDocumentStore($this->connection);
        $tester = new CommandTester(new DocumentVerifyCommand(
            $store,
            new DocumentDelivery($store, $this->createMock(InvoiceRenderer::class), $files),
            $archiver,
            $external,
            $late,
        ));
        $tester->execute($retry ? ['--retry' => true] : []);

        return $tester;
    }

    private function document(int $id, string $number, string $state, string $path, string $hash): void
    {
        $this->connection->insert('tl_consho_document', ['id' => $id, 'documentNumber' => $number, 'archiveState' => $state, 'archivedPath' => $path, 'archivedHash' => $hash]);
    }
}
