<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;
use Webwerkwien\ContaoConshoBundle\Document\DbalInvoiceIssueSource;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalInvoiceIssueSourceTest extends TestCase
{
    private const FOLDER = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120077';

    private Connection $connection;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/consho-invoice-issues-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->root.'/files/rechnungen');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_payment_method');
        // Published explicitly: the DCA default is false (the hand-written
        // table had DEFAULT 1 and so hid that every method here counted).
        $this->connection->insert('tl_consho_payment_method', ['id' => 1, 'type' => 'prepayment', 'published' => 1]);
        $this->connection->insert('tl_consho_payment_method', ['id' => 2, 'type' => 'card', 'published' => 1]);
        $this->connection->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT '', path TEXT NOT NULL DEFAULT '')");
        $this->connection->insert('tl_files', ['uuid' => StringUtil::uuidToBin(self::FOLDER), 'type' => 'folder', 'path' => 'files/rechnungen']);

        $GLOBALS['TL_LANG']['CONSHO']['invoiceArchiveFolder'] = ['missing' => 'fehlt', 'public' => 'öffentlich über %s'];
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
        unset($GLOBALS['TL_LANG']);
    }

    public function testOhneAnlassKeinBefund(): void
    {
        $this->shop(['vatId' => 'ATU1', 'country' => 'AT', 'deliveryCountries' => serialize(['AT', 'DE']), 'invoiceReverseChargeNote' => 'Hinweis']);

        self::assertSame([], $this->issues());
    }

    public function testGescheiterteAblagenWerdenGezaehltUndGenannt(): void
    {
        $this->connection->insert('tl_consho_document', ['documentNumber' => 'RE-1', 'archiveState' => 'failed']);
        $this->connection->insert('tl_consho_document', ['documentNumber' => 'RE-2', 'archiveState' => 'stored']);
        $this->connection->insert('tl_consho_document', ['documentNumber' => 'RE-3', 'archiveState' => '']);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::InvoiceArchiveFailed, ['count' => '1', 'numbers' => 'RE-1'])],
            $this->issues(),
        );
    }

    public function testEinFehlenderAblageordnerWirdGemeldet(): void
    {
        $this->shop(['invoiceArchive' => 1]);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::InvoiceArchiveFolderUnsafe, ['shop' => 'Wien', 'problem' => 'fehlt'], 3)], $this->issues());
    }

    /**
     * Made public AFTER the selection — through the file manager, bypassing
     * the automatic protection.
     */
    public function testEinOeffentlicherAblageordnerWirdGemeldet(): void
    {
        touch($this->root.'/files/.public');
        $this->shop(['invoiceArchive' => 1, 'invoiceArchiveFolder' => StringUtil::uuidToBin(self::FOLDER)]);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::InvoiceArchiveFolderUnsafe, ['shop' => 'Wien', 'problem' => 'öffentlich über files'], 3)], $this->issues());
    }

    public function testEinGeschuetzterAblageordnerIstInOrdnung(): void
    {
        $this->shop(['invoiceArchive' => 1, 'invoiceArchiveFolder' => StringUtil::uuidToBin(self::FOLDER)]);

        self::assertSame([], $this->issues());
    }

    /**
     * Reverse charge needs a VAT ID AND a border. A shop that only delivers to
     * its home country never issues such an invoice and needs no notice.
     */
    public function testDerReverseChargeHinweisFehltNurWoErGebrauchtWird(): void
    {
        $this->shop(['vatId' => 'ATU1', 'country' => 'AT', 'deliveryCountries' => serialize(['AT', 'DE'])]);
        $this->shop(['id' => 4, 'title' => 'Nur Österreich', 'vatId' => 'ATU2', 'country' => 'AT', 'deliveryCountries' => serialize(['AT'])]);
        $this->shop(['id' => 5, 'title' => 'Ohne UID', 'country' => 'AT', 'deliveryCountries' => serialize(['AT', 'DE'])]);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::ReverseChargeNoteMissing, ['shop' => 'Wien'], 3)], $this->issues());
    }

    public function testOhneConshoRechnungenFragtNiemandNachDemInhaltDerRechnung(): void
    {
        // Block R (R19): reverse charge note and delivery period go on Consho's invoice.
        $this->shop(['vatId' => 'ATU1', 'country' => 'AT', 'deliveryCountries' => serialize(['AT', 'DE']), 'paymentMethods' => serialize(['1']), 'invoicing' => 'off']);

        self::assertCount(2, $this->issues());
        self::assertSame([], $this->issues(new \Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy($this->connection, new \Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders(new \Symfony\Component\DependencyInjection\ServiceLocator([])))));
    }

    /**
     * C-8, delivery date: a shop that issues the invoice at purchase (prepayment)
     * must be able to state a delivery period — without a delivery time on the
     * shop it cannot.
     */
    public function testVorkasseOhneLieferzeitWirdGemeldet(): void
    {
        $this->shop(['paymentMethods' => serialize(['1'])]);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::DeliveryTimeMissing, ['shop' => 'Wien'], 3)], $this->issues());
    }

    public function testMitLieferzeitOderOhneRechnungBeimKaufKeinBefund(): void
    {
        $this->shop(['paymentMethods' => serialize(['1']), 'deliveryDaysMax' => 5]);
        $this->shop(['id' => 4, 'title' => 'Karte', 'paymentMethods' => serialize(['2'])]);

        self::assertSame([], $this->issues());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function shop(array $row): void
    {
        $this->connection->insert('tl_consho_shop', $row + ['id' => 3, 'title' => 'Wien']);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(InvoicingPolicy|null $policy = null): array
    {
        // prepayment issues on order, card on payment — the handler says so,
        // as in production (OnOrderInvoicing asks documentTrigger()).
        $locator = $this->createMock(ServiceProviderInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturnCallback(function (string $type): PaymentHandlerInterface {
            $handler = $this->createMock(PaymentHandlerInterface::class);
            $handler->method('documentTrigger')->willReturn('prepayment' === $type ? DocumentTrigger::OnOrder : DocumentTrigger::OnPayment);

            return $handler;
        });

        return (new DbalInvoiceIssueSource($this->connection, new ArchiveFolderProtector($this->root), new PaymentHandlers($locator), $policy))->issues();
    }
}
