<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentStore;
use Webwerkwien\ContaoConshoBundle\Document\DocumentDelivery;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceRenderer;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block R (R16, R18, R21): which PDF a document has, and whether Consho's
 * mails carry it — by who issued it and how the shop's module works.
 */
class DocumentDeliveryIssuerTest extends TestCase
{
    private Connection $connection;

    private FakeInvoiceProvider $provider;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Shop']);
        $this->provider = new FakeInvoiceProvider();
    }

    public function testConshosBelegKommtVonConshoUndGehtMitDenMails(): void
    {
        $id = $this->document('consho', 'RE-1');

        self::assertSame('%PDF-consho', $this->delivery()->pdf($id)['pdf'] ?? null);
        self::assertSame('<p>consho</p>', $this->delivery()->html($id)['html'] ?? null);
        self::assertFalse($this->delivery()->sentElsewhere($id));
    }

    public function testBeiAusGibtEsKeinPdfUndConshoSchicktNichts(): void
    {
        $this->shop('off');
        $id = $this->document('off', null);

        self::assertNull($this->delivery()->pdf($id));
        self::assertNull($this->delivery()->html($id));
        self::assertTrue($this->delivery()->sentElsewhere($id));
    }

    public function testDerBelegDesModulsIstDessenPdfSobaldEsIhnAusgestelltHat(): void
    {
        $this->shop('fake_books');
        $pending = $this->document('fake_books', null, 'pending');
        $issued = $this->document('fake_books', 'FB-1', 'issued', 'ext-1');

        self::assertNull($this->delivery()->pdf($pending));
        self::assertSame(['%PDF-fake', 'FB-1.pdf'], [$this->delivery()->pdf($issued)['pdf'] ?? null, $this->delivery()->pdf($issued)['fileName'] ?? null]);
        // Consho's layout of it would be a second original that differs.
        self::assertNull($this->delivery()->html($issued));
    }

    public function testEinVerschwundenesModulLiefertKeinPdf(): void
    {
        $id = $this->document('removed_books', 'RB-1', 'issued', 'ext-9');

        self::assertNull($this->delivery()->pdf($id));
        self::assertTrue($this->delivery()->sentElsewhere($id));
    }

    public function testWerVerschicktHaengtAmModul(): void
    {
        $this->shop('fake_books');
        $issued = $this->document('fake_books', 'FB-1', 'issued', 'ext-1');

        self::assertFalse($this->delivery()->sentElsewhere($issued), 'the module leaves sending to Consho');

        $this->provider = new FakeInvoiceProvider(sends: true);
        self::assertTrue($this->delivery()->sentElsewhere($issued), 'the module sends it itself');
    }

    public function testEinWeiterreichendesModulDasSelbstVerschicktNimmtConshoDenVersandAb(): void
    {
        $this->shop('fake_books');
        $id = $this->document('consho', 'RE-1');

        $this->provider = new FakeInvoiceProvider(issues: false, sends: true);
        self::assertTrue($this->delivery()->sentElsewhere($id));
        self::assertSame('%PDF-consho', $this->delivery()->pdf($id)['pdf'] ?? null, 'the PDF stays Consho\'s');

        $this->provider = new FakeInvoiceProvider(issues: false, sends: false);
        self::assertFalse($this->delivery()->sentElsewhere($id));
    }

    public function testConshosAlterBelegGehtAuchNachDemUmstellenMitDenMails(): void
    {
        // A cancellation of an invoice from before the switch follows that invoice (R20).
        $id = $this->document('consho', 'RE-1');

        foreach (['off', 'fake_books'] as $invoicing) {
            $this->shop($invoicing);
            self::assertFalse($this->delivery()->sentElsewhere($id), $invoicing);
        }
    }

    private function shop(string $invoicing): void
    {
        $this->connection->update('tl_consho_shop', ['invoicing' => $invoicing], ['id' => 1]);
    }

    private function document(string $issuer, string|null $number, string $state = '', string $externalId = ''): int
    {
        $this->connection->insert('tl_consho_document', [
            'pid' => 1,
            'shop' => 1,
            'type' => 'invoice',
            'documentNumber' => $number,
            'payload' => '{}',
            'issuer' => $issuer,
            'externalState' => $state,
            'externalId' => $externalId,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function delivery(): DocumentDelivery
    {
        $renderer = $this->createMock(InvoiceRenderer::class);
        $renderer->method('pdf')->willReturn(['pdf' => '%PDF-consho', 'logoMissing' => false]);
        $renderer->method('html')->willReturn(['html' => '<p>consho</p>', 'logoMissing' => false]);
        $provider = $this->provider;

        return new DocumentDelivery(
            new DbalDocumentStore($this->connection),
            $renderer,
            $this->createMock(VirtualFilesystemInterface::class),
            new InvoicingPolicy($this->connection, new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider]))),
        );
    }
}
