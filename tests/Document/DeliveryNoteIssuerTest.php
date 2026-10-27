<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentRepository;
use Webwerkwien\ContaoConshoBundle\Document\DeliveryNoteIssuer;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentSourceInterface;
use Webwerkwien\ContaoConshoBundle\Order\NumberAllocatorInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * dev#2: One delivery note per parcel, only with a number range, only
 * physical items, with what follows separately.
 */
final class DeliveryNoteIssuerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_document', 'tl_consho_number_range'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 2, 'orderNumber' => 'WEB-00081', 'firstname' => 'Erika', 'lastname' => 'Muster', 'grandTotal' => '99.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'sorting' => 1, 'productName' => 'Wanderstock', 'sku' => 'WS-1', 'quantity' => '2.0000', 'unitPrice' => '20.0000', 'kind' => 'physical']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 7, 'sorting' => 2, 'productName' => 'Rucksack', 'sku' => 'RS-1', 'quantity' => '1.0000', 'kind' => 'physical']);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => 7, 'sorting' => 3, 'productName' => 'Tourenbuch (PDF)', 'quantity' => '1.0000', 'kind' => 'digital']);
        $this->connection->insert('tl_consho_order_item', ['id' => 4, 'pid' => 7, 'sorting' => 4, 'productName' => 'Paket', 'quantity' => '1.0000', 'lineType' => 'shipping']);
        $this->connection->insert('tl_consho_number_range', ['id' => 1, 'pid' => 2, 'type' => 'deliveryNote', 'prefix' => 'LS-']);

        $this->connection->insert('tl_consho_delivery', ['id' => 11, 'pid' => 7, 'shippedAt' => mktime(12, 0, 0, 10, 21, 2026), 'carrier' => 'Post AT', 'trackingNumber' => 'CA1AT']);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 11, 'orderItem' => 1, 'quantity' => '2.0000']);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 11, 'orderItem' => 3, 'quantity' => '1.0000']);
    }

    public function testDasPaketBekommtEinenLieferscheinMitDemWasFolgt(): void
    {
        $id = $this->issuer($this->allocatorOnce())->issue(11, [2 => '1']);

        self::assertNotNull($id);
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_document WHERE id = ?', [$id]);
        self::assertSame(['deliveryNote', 'LS-00001', 11, 7, 2], [$row['type'], $row['documentNumber'], (int) $row['delivery'], (int) $row['pid'], (int) $row['shop']]);

        $payload = json_decode((string) $row['payload'], true);
        self::assertSame([['productName' => 'Wanderstock', 'sku' => 'WS-1', 'quantity' => '2.0000']], $payload['items'], 'nur dieses Paket, nur Physisches');
        self::assertSame([['productName' => 'Rucksack', 'sku' => 'RS-1', 'quantity' => '1.0000']], $payload['rest']);
        self::assertTrue($payload['partial']);
        self::assertSame(['shippedAt' => '2026-10-21', 'carrier' => 'Post AT', 'trackingNumber' => 'CA1AT'], $payload['shipment']);
        self::assertSame('Erika', $payload['order']['firstname']);
        self::assertArrayNotHasKey('grandTotal', $payload['order'], 'kein Betrag');
        self::assertStringNotContainsString('unitPrice', (string) $row['payload']);
    }

    public function testNachDemLetztenPaketFolgtNichts(): void
    {
        $id = $this->issuer($this->allocatorOnce())->issue(11, []);

        $payload = json_decode((string) $this->connection->fetchOne('SELECT payload FROM tl_consho_document WHERE id = ?', [$id]), true);
        self::assertSame([], $payload['rest']);
        self::assertFalse($payload['partial']);
    }

    public function testOffeneDigitaleWareFolgtNichtImPaket(): void
    {
        $id = $this->issuer($this->allocatorOnce())->issue(11, [3 => '1']);

        $payload = json_decode((string) $this->connection->fetchOne('SELECT payload FROM tl_consho_document WHERE id = ?', [$id]), true);
        self::assertSame([], $payload['rest'], 'wird freigegeben, nicht nachgeschickt');
        self::assertFalse($payload['partial']);
    }

    public function testOhneNummernkreisKeinLieferschein(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_number_range');
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::never())->method('next');

        self::assertNull($this->issuer($allocator)->issue(11, []));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_document'));
    }

    public function testEinPaketOhnePhysischeWareBekommtKeinen(): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => 12, 'pid' => 7, 'shippedAt' => 1]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 12, 'orderItem' => 3, 'quantity' => '1.0000']);
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::never())->method('next');

        self::assertNull($this->issuer($allocator)->issue(12, []), 'keine Nummer verbraucht');
    }

    public function testZweimalGefragtBleibtEsEiner(): void
    {
        $issuer = $this->issuer($this->allocatorOnce());

        $first = $issuer->issue(11, []);

        self::assertSame($first, $issuer->issue(11, []));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_document'));
    }

    private function allocatorOnce(): NumberAllocatorInterface
    {
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::once())->method('next')->with(2, 'deliveryNote')->willReturn('LS-00001');

        return $allocator;
    }

    private function issuer(NumberAllocatorInterface $allocator): DeliveryNoteIssuer
    {
        $source = $this->createStub(DocumentSourceInterface::class);
        $source->method('contextFor')->willReturn([
            'issuer' => ['company' => 'Wiener Wandern'],
            'logo' => null,
            'payment' => ['instructions' => '', 'details' => []],
            'notes' => ['footer' => 'Danke', 'reverseCharge' => ''],
        ]);

        return new DeliveryNoteIssuer($this->connection, new DbalDocumentRepository($this->connection), $allocator, new DocumentPayloadBuilder(), $source);
    }
}
