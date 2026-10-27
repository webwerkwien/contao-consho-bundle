<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentRepository;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuer;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentSourceInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;
use Webwerkwien\ContaoConshoBundle\Document\PaymentTerm;
use Webwerkwien\ContaoConshoBundle\Order\NumberAllocatorInterface;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeNotConfigured;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Who issues the invoice (block R, R16, R17, R20) at the one place every
 * trigger runs through.
 */
class DocumentIssuerInvoicingTest extends TestCase
{
    private const LINES = [['productName' => 'Ware', 'quantity' => '-1', 'unitPrice' => '12.0000', 'taxRate' => '20.00', 'taxAmount' => '-2.0000', 'lineTotal' => '-12.0000', 'taxCountry' => 'AT']];

    private Connection $connection;

    /** @var list<string> */
    private array $drawn = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_document', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_shop'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Shop', 'invoicing' => 'consho']);
        $this->connection->insert('tl_consho_order', ['id' => 10, 'shop' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '120.0000', 'currency' => 'EUR']);
    }

    public function testAusBehaeltDieRechnungsdatenOhneNummer(): void
    {
        $this->setInvoicing('off');

        $id = $this->issuer()->issue(10, 1, DocumentType::Invoice);
        $row = $this->row($id);

        self::assertNull($row['documentNumber']);
        self::assertSame(['off', ''], [$row['issuer'], $row['externalState']]);
        self::assertSame([], $this->drawn, 'no number may be drawn');
        self::assertSame('WEB-1', json_decode((string) $row['payload'], true)['order']['orderNumber'] ?? null);
    }

    public function testAusHatKeinenGiroCode(): void
    {
        $this->setInvoicing('off');

        $payload = json_decode((string) $this->row($this->issuer()->issue(10, 1, DocumentType::Invoice))['payload'], true);

        self::assertArrayNotHasKey('giroCode', $payload['payment'] ?? []);
    }

    public function testAuchBeiAusGiltDasZahlungsziel(): void
    {
        // R17: without it nothing issued elsewhere is ever overdue.
        $this->setInvoicing('off');
        $before = time();

        $this->issuer()->issue(10, 1, DocumentType::Invoice);

        $dueAt = (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order WHERE id = 10');
        self::assertContains($dueAt, [PaymentTerm::of($before, 14)['endsAt'], PaymentTerm::of(time(), 14)['endsAt']]);
    }

    public function testOhneConshoNummerBrauchtEsKeinenNummernkreis(): void
    {
        $this->setInvoicing('off');
        $allocator = $this->createStub(NumberAllocatorInterface::class);
        $allocator->method('next')->willThrowException(NumberRangeNotConfigured::for(1, 'invoice'));

        self::assertGreaterThan(0, $this->issuer($allocator)->issue(10, 1, DocumentType::Invoice));
    }

    public function testEinAusstellendesModulLaesstDenBelegAusstehen(): void
    {
        $this->setInvoicing('fake_books');

        $row = $this->row($this->issuer()->issue(10, 1, DocumentType::Invoice));

        self::assertNull($row['documentNumber']);
        self::assertSame(['fake_books', 'pending'], [$row['issuer'], $row['externalState']]);
        self::assertSame([], $this->drawn);
    }

    public function testEinNichtInstalliertesModulLaesstDenBelegAuchAusstehen(): void
    {
        $this->setInvoicing('removed_books');

        $row = $this->row($this->issuer()->issue(10, 1, DocumentType::Invoice));

        self::assertSame(['removed_books', 'pending'], [$row['issuer'], $row['externalState']]);
    }

    public function testEinWeiterreichendesModulBehaeltConshoNummern(): void
    {
        $this->setInvoicing('fake_books');

        $row = $this->row($this->issuer(provider: new FakeInvoiceProvider(issues: false))->issue(10, 1, DocumentType::Invoice));

        self::assertSame(['RE-1', 'consho', ''], [$row['documentNumber'], $row['issuer'], $row['externalState']]);
    }

    public function testDerLieferscheinBleibtImmerBeiConsho(): void
    {
        $this->setInvoicing('off');

        $row = $this->row($this->issuer()->issue(10, 1, DocumentType::DeliveryNote));

        self::assertSame(['LS-1', 'consho'], [$row['documentNumber'], $row['issuer']]);
    }

    public function testDerStornoEinerConshoRechnungBleibtBeiConshoAuchNachDemUmstellen(): void
    {
        // R20: the invoice range would otherwise hold an invoice nothing reversed.
        $invoice = $this->issuer()->issue(10, 1, DocumentType::Invoice);
        $this->setInvoicing('off');

        $row = $this->row($this->issuer()->cancel($invoice));

        self::assertSame(['ST-1', 'consho', ''], [$row['documentNumber'], $row['issuer'], $row['externalState']]);
    }

    public function testDerStornoEinerAusRechnungBleibtOhneNummerAuchNachDemUmstellen(): void
    {
        $this->setInvoicing('off');
        $invoice = $this->issuer()->issue(10, 1, DocumentType::Invoice);
        $this->setInvoicing('consho');

        $row = $this->row($this->issuer()->cancel($invoice));

        self::assertNull($row['documentNumber']);
        self::assertSame(['off', ''], [$row['issuer'], $row['externalState']]);
        self::assertNotContains('cancellation', $this->drawn);
    }

    public function testDieKorrekturEinerModulRechnungGehtAnDasModul(): void
    {
        $this->setInvoicing('fake_books');
        $invoice = $this->issuer()->issue(10, 1, DocumentType::Invoice);
        $this->setInvoicing('consho');

        $row = $this->row($this->issuer()->correct($invoice, self::LINES, 'W-1'));

        self::assertNull($row['documentNumber']);
        self::assertSame(['fake_books', 'pending'], [$row['issuer'], $row['externalState']]);
        self::assertNotContains('creditNote', $this->drawn);
    }

    public function testDieKorrekturEinerConshoRechnungNummeriertConsho(): void
    {
        $invoice = $this->issuer()->issue(10, 1, DocumentType::Invoice);
        $this->setInvoicing('fake_books');

        $row = $this->row($this->issuer()->correct($invoice, self::LINES, 'W-1'));

        self::assertSame(['GS-1', 'consho'], [$row['documentNumber'], $row['issuer']]);
    }

    private function setInvoicing(string $invoicing): void
    {
        $this->connection->update('tl_consho_shop', ['invoicing' => $invoicing], ['id' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_consho_document WHERE id = ?', [$id]) ?: [];
    }

    private function issuer(NumberAllocatorInterface|null $allocator = null, FakeInvoiceProvider|null $provider = null): DocumentIssuer
    {
        if (null === $allocator) {
            $allocator = $this->createStub(NumberAllocatorInterface::class);
            $prefixes = ['invoice' => 'RE', 'cancellation' => 'ST', 'creditNote' => 'GS', 'deliveryNote' => 'LS'];
            $counts = [];
            $allocator->method('next')->willReturnCallback(function (int $shop, string $range) use ($prefixes, &$counts): string {
                $this->drawn[] = $range;
                $counts[$range] = ($counts[$range] ?? 0) + 1;

                return $prefixes[$range].'-'.$counts[$range];
            });
        }

        $source = $this->createStub(DocumentSourceInterface::class);
        $source->method('contextFor')->willReturn([
            'issuer' => ['company' => 'Firma'],
            'logo' => null,
            'payment' => ['instructions' => 'Bitte überweisen', 'details' => ['iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'holder' => 'Firma'], 'giroCode' => true, 'dueDays' => 14],
            'notes' => ['footer' => '', 'reverseCharge' => ''],
        ]);

        $provider ??= new FakeInvoiceProvider();

        return new DocumentIssuer(
            new DbalDocumentRepository($this->connection),
            $allocator,
            new DocumentPayloadBuilder(),
            $source,
            new InvoicingPolicy($this->connection, new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider]))),
        );
    }
}
