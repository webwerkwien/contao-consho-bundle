<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\ExternalInvoicing;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\ExternalIssuingArchiver;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\PendingDocument;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class ExternalInvoicingTest extends TestCase
{
    private Connection $connection;

    private FakeInvoiceProvider $provider;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        $this->provider = new FakeInvoiceProvider();
    }

    public function testDasModulStelltDenAusstehendenBelegAusUndConshoMerktSichDieNummer(): void
    {
        $id = $this->document('invoice', ['order' => ['orderNumber' => 'WEB-7']]);

        self::assertTrue($this->external()->submit($id), 'issued now');

        self::assertCount(1, $this->provider->issued);
        self::assertSame(DocumentType::Invoice, $this->provider->issued[0]->type);
        self::assertSame(['order' => ['orderNumber' => 'WEB-7']], $this->provider->issued[0]->snapshot);
        self::assertSame(['FB-1', 1_800_000_001, 'ext-1', 'issued'], $this->state($id));
    }

    public function testEinAusgestellterBelegWirdNichtNochEinmalAngeboten(): void
    {
        // A second invoice in the accounting system is worse than a late one.
        $id = $this->document('invoice');

        $this->external()->submit($id);
        self::assertFalse($this->external()->submit($id), 'issued before, not now');

        self::assertCount(1, $this->provider->issued);
    }

    public function testEinBelegImEinreichenWirdNichtEinZweitesMalEingereicht(): void
    {
        $id = $this->document('invoice', state: 'submitting');

        $this->external()->submit($id);

        self::assertSame([], $this->provider->issued);
        self::assertSame([], $this->external()->idsToSubmit());
    }

    public function testDerStornoWartetAufDieRechnung(): void
    {
        $invoice = $this->document('invoice');
        $cancellation = $this->document('cancellation', related: $invoice);

        $this->external()->submit($cancellation);
        self::assertCount(0, $this->provider->issued, 'not before its invoice');

        $this->external()->submit($invoice);
        $this->external()->submit($cancellation);

        $second = $this->issuedAt(1);
        self::assertNotNull($second);
        self::assertSame('ext-1', $second->relatedExternalId);
        self::assertSame(DocumentType::Cancellation, $second->type);
    }

    public function testScheitertDasModulBleibtDerBelegZumNaechstenVersuch(): void
    {
        $id = $this->document('invoice');
        $this->provider->failWith = new \RuntimeException('offline');

        self::assertFalse($this->external()->submit($id));

        self::assertSame([null, 0, '', 'failed'], $this->state($id));
        self::assertSame([$id], $this->external()->idsToSubmit());

        $this->provider->failWith = null;
        $this->external()->submit($id);

        self::assertSame('issued', $this->state($id)[3]);
    }

    public function testEinNichtInstalliertesModulLaesstDenBelegWarten(): void
    {
        $id = $this->document('invoice', issuer: 'removed_books');

        $this->external()->submit($id);

        self::assertSame('pending', $this->state($id)[3]);
        self::assertSame([$id], $this->external()->idsToSubmit());
    }

    public function testConshoUndAusBelegeGehenNichtAnDasModul(): void
    {
        $consho = $this->document('invoice', issuer: 'consho', state: '');
        $off = $this->document('invoice', issuer: 'off', state: '');

        $this->external()->submit($consho);
        $this->external()->submit($off);

        self::assertSame([], $this->provider->issued);
        self::assertSame([], $this->external()->idsToSubmit());
    }

    public function testEineDoppelteNummerBehaeltDieExterneIdUndWirdNichtNeuEingereicht(): void
    {
        // The module has the document; offering it again would issue it twice.
        $this->connection->insert('tl_consho_document', ['pid' => 1, 'shop' => 1, 'type' => 'invoice', 'documentNumber' => 'FB-1']);
        $id = $this->document('invoice');

        self::assertFalse($this->external()->submit($id), 'the number clashed, nothing was issued in the shop');
        $this->external()->submit($id);

        self::assertSame([null, 0, 'ext-1', 'failed'], $this->state($id));
        self::assertCount(1, $this->provider->issued);
        self::assertSame([], $this->external()->idsToSubmit());
    }

    public function testDerArchivierungsschrittReichtErstEinUndArchiviertDann(): void
    {
        $id = $this->document('invoice');
        $seen = null;
        $inner = $this->createMock(DocumentArchiverInterface::class);
        $inner->expects(self::once())->method('archive')->with($id)->willReturnCallback(function () use ($id, &$seen): void {
            $seen = $this->state($id)[0];
        });

        (new ExternalIssuingArchiver($inner, $this->external()))->archive($id);

        self::assertSame('FB-1', $seen, 'the inner archivers see the numbered document');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function document(string $type, array $payload = [], int $related = 0, string $issuer = 'fake_books', string $state = 'pending'): int
    {
        $this->connection->insert('tl_consho_document', [
            'pid' => 1,
            'shop' => 1,
            'type' => $type,
            'relatedDocument' => $related,
            'payload' => json_encode($payload),
            'issuer' => $issuer,
            'externalState' => $state,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array{0: string|null, 1: int, 2: string, 3: string}
     */
    private function state(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT documentNumber, issuedAt, externalId, externalState FROM tl_consho_document WHERE id = ?', [$id]) ?: [];

        return [null === $row['documentNumber'] ? null : (string) $row['documentNumber'], (int) $row['issuedAt'], (string) $row['externalId'], (string) $row['externalState']];
    }

    private function issuedAt(int $index): PendingDocument|null
    {
        return $this->provider->issued[$index] ?? null;
    }

    private function external(): ExternalInvoicing
    {
        $provider = $this->provider;

        return new ExternalInvoicing($this->connection, new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])), new NullLogger());
    }
}
