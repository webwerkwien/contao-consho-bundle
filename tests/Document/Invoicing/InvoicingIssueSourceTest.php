<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class InvoicingIssueSourceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_document');
    }

    public function testConshoUndEinInstalliertesModulMeldenNichts(): void
    {
        $this->shop(1, 'consho');
        $this->shop(2, 'fake_books');

        self::assertSame([], $this->issues());
    }

    public function testAusIstEinHinweisUndEinFehlendesModulEineWarnung(): void
    {
        $this->shop(1, 'off');
        $this->shop(2, 'removed_books');

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::InvoicingOff, ['shop' => 'Shop 1'], 1),
            new ConfigurationIssue(ConfigurationFinding::InvoiceProviderMissing, ['shop' => 'Shop 2', 'module' => 'removed_books'], 2),
        ], $this->issues());
        self::assertFalse(ConfigurationFinding::InvoicingOff->isWarning());
        self::assertTrue(ConfigurationFinding::InvoiceProviderMissing->isWarning());
    }

    public function testGescheiterteUndHaengendeBelegeWerdenGezaehlt(): void
    {
        $this->document('failed', self::NOW);
        $this->document('pending', self::NOW - 7200);
        $this->document('submitting', self::NOW - 7200);
        // Still within the request that issued it, and done ones.
        $this->document('pending', self::NOW - 60);
        $this->document('issued', self::NOW - 7200);
        $this->document('', self::NOW - 7200);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::ExternalInvoiceFailed, ['count' => '3'])], $this->issues());
        self::assertTrue(ConfigurationFinding::ExternalInvoiceFailed->isWarning());
    }

    private function shop(int $id, string $invoicing): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'title' => 'Shop '.$id, 'invoicing' => $invoicing]);
    }

    private function document(string $state, int $tstamp): void
    {
        $this->connection->insert('tl_consho_document', ['pid' => 1, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'fake_books', 'externalState' => $state, 'tstamp' => $tstamp, 'payload' => '{}']);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        $provider = new FakeInvoiceProvider();

        return (new InvoicingIssueSource(
            $this->connection,
            new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])),
            static fn (): int => self::NOW,
        ))->issues();
    }
}
