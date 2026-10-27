<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlers;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalFileIssueSource;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalFiles;
use Webwerkwien\ContaoConshoBundle\Digital\Entitlement;
use Webwerkwien\ContaoConshoBundle\Digital\FileDeliveryHandler;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPoolHandler;
use Webwerkwien\ContaoConshoBundle\Digital\OrderPageDownloads;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DigitalDeliveryListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * F-5, F18: The file of a digital article — only from a protected folder,
 * streamed, only with a valid entitlement of one's own order.
 */
class DigitalFilesTest extends TestCase
{
    private const PROTECTED = '0123456789abcdef';
    private const PUBLIC = 'fedcba9876543210';
    private const GONE = 'aaaaaaaaaaaaaaaa';

    private string $dir;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/consho-f5-'.bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $fs->dumpFile($this->dir.'/files/shop/ebook.pdf', '%PDF E-Book');
        $fs->dumpFile($this->dir.'/files/public/flyer.pdf', '%PDF Flyer');
        $fs->dumpFile($this->dir.'/files/public/.public', '');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $c = $this->connection;
        $c->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB, path TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'file')");
        foreach (['tl_consho_product', 'tl_consho_order_item', 'tl_consho_order', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item', 'tl_consho_download'] as $table) {
            DcaSchema::create($c, $table);
        }
        // orderNumber is unique in the DCA, as every real order has one.
        $c->insert('tl_consho_order', ['id' => 5, 'orderNumber' => 'WEB-5']);
        $c->insert('tl_consho_order', ['id' => 6, 'orderNumber' => 'WEB-6']);
        DigitalSchema::create($c);

        $c->insert('tl_files', ['uuid' => self::PROTECTED, 'path' => 'files/shop/ebook.pdf']);
        $c->insert('tl_files', ['uuid' => self::PUBLIC, 'path' => 'files/public/flyer.pdf']);
        $c->insert('tl_files', ['uuid' => self::GONE, 'path' => 'files/shop/weg.pdf']);

        // 1: e-book with file; 2: variant without one of its own → inherits;
        // 3: public file; 4: file missing on the disk; 5: none selected.
        // Published explicitly: the DCA default is 0 (the hand-written table
        // had DEFAULT 1), and the issue source only counts published articles.
        $c->insert('tl_consho_product', ['id' => 1, 'title' => 'E-Book', 'published' => 1, 'digitalDelivery' => 'file', 'digitalFile' => self::PROTECTED]);
        $c->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'title' => 'E-Book EPUB', 'published' => 1]);
        $c->insert('tl_consho_product', ['id' => 3, 'title' => 'Flyer', 'published' => 1, 'digitalDelivery' => 'file', 'digitalFile' => self::PUBLIC]);
        $c->insert('tl_consho_product', ['id' => 4, 'title' => 'Weg', 'published' => 1, 'digitalDelivery' => 'file', 'digitalFile' => self::GONE]);
        $c->insert('tl_consho_product', ['id' => 5, 'title' => 'Ohne', 'published' => 1, 'digitalDelivery' => 'file']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testNurEineVorhandeneDateiAusEinemGeschuetztenOrdner(): void
    {
        $files = $this->files();

        self::assertSame('files/shop/ebook.pdf', $files->of(1)['path'] ?? null);
        self::assertSame('ebook.pdf', $files->of(2)['name'] ?? null, 'die Variante erbt die Datei');
        self::assertNull($files->of(3), 'öffentlicher Ordner');
        self::assertNull($files->of(4), 'fehlt auf der Platte');
        self::assertNull($files->of(5), 'keine gewählt');

        $handler = new FileDeliveryHandler($files);
        self::assertTrue($handler->release($this->entitlement(product: 1)));
        self::assertFalse($handler->release($this->entitlement(product: 3)), 'die Freigabe wartet');
    }

    public function testDerDownloadGehtNurMitGueltigerBerechtigungDerEigenenBestellung(): void
    {
        $now = 2_000;
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 5, 'productName' => 'E-Book']);
        $this->connection->insert('tl_consho_entitlement', ['id' => 1, 'pid' => 5, 'orderItem' => 10, 'product' => 1, 'quantity' => '1', 'deliveryType' => 'file', 'validFrom' => 1_000]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 2, 'pid' => 5, 'orderItem' => 10, 'product' => 1, 'quantity' => '1', 'deliveryType' => 'file', 'validFrom' => 1_000, 'validUntil' => 1_500]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 3, 'pid' => 5, 'orderItem' => 10, 'product' => 1, 'quantity' => '1', 'deliveryType' => 'file', 'validFrom' => 1_000, 'revoked' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 4, 'pid' => 6, 'orderItem' => 10, 'product' => 1, 'quantity' => '1', 'deliveryType' => 'file', 'validFrom' => 1_000]);

        $downloads = new OrderPageDownloads($this->connection, $this->files(), new KeyPoolHandler($this->connection));

        $response = $downloads->download(5, 1, $now);
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(str_replace('\\', '/', $this->dir.'/files/shop/ebook.pdf'), str_replace('\\', '/', $response->getFile()->getPathname()));
        self::assertStringContainsString('attachment; filename=ebook.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'), 'nicht public');
        self::assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertSame([[1, $now]], array_map(static fn (array $r): array => [(int) $r['pid'], (int) $r['downloadedAt']], $this->connection->fetchAllAssociative('SELECT pid, downloadedAt FROM tl_consho_download')), 'protokolliert, ohne IP');

        self::assertNull($downloads->download(5, 2, $now), 'abgelaufen');
        self::assertNull($downloads->download(5, 3, $now), 'gesperrt');
        self::assertNull($downloads->download(5, 4, $now), 'fremde Bestellung');
        self::assertNull($downloads->download(5, 99, $now));

        $view = $downloads->view(5, $now);
        self::assertSame([true, false, false], array_column($view, 'active'));
        self::assertSame([false, false, false], array_column($view, 'resting'), 'abgelaufen und gesperrt ruhen nicht');
        self::assertSame([true, false, false], array_column($view, 'file'));
        self::assertSame('E-Book', $view[0]['name']);
    }

    /**
     * Abo-5 (P33): A suspended entitlement hands out nothing — and when it
     * revives, it works without anything further being done.
     */
    public function testEineRuhendeBerechtigungGibtNichtsHeraus(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 5, 'productName' => 'E-Book']);
        $this->connection->insert('tl_consho_entitlement', ['id' => 1, 'pid' => 5, 'orderItem' => 10, 'product' => 1, 'quantity' => '1', 'deliveryType' => 'file', 'validFrom' => 1_000, 'suspendedAt' => 1_800]);

        $downloads = new OrderPageDownloads($this->connection, $this->files(), new KeyPoolHandler($this->connection));

        self::assertNull($downloads->download(5, 1, 2_000));
        self::assertFalse($downloads->view(5, 2_000)[0]['active']);
        // Abo-5: "suspended", not "no longer valid".
        self::assertTrue($downloads->view(5, 2_000)[0]['resting']);

        $this->connection->update('tl_consho_entitlement', ['suspendedAt' => null], ['id' => 1]);

        $again = new OrderPageDownloads($this->connection, $this->files(), new KeyPoolHandler($this->connection));

        self::assertInstanceOf(BinaryFileResponse::class, $again->download(5, 1, 2_000));
    }

    /**
     * DL3: The order page recognises a service — without a file, with a
     * subscription or without, and with the day it was reported as performed.
     */
    public function testDieBestellseiteKenntDieDienstleistung(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 5, 'productName' => 'Beratung', 'kind' => 'service', 'performedAt' => 1_500]);
        $this->connection->insert('tl_consho_order_item', ['id' => 11, 'pid' => 5, 'productName' => 'Mitgliedschaft', 'kind' => 'service']);
        $this->connection->insert('tl_consho_entitlement', ['id' => 1, 'pid' => 5, 'orderItem' => 10, 'product' => 1, 'quantity' => '1', 'deliveryType' => '', 'validFrom' => 1_000]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 2, 'pid' => 5, 'orderItem' => 11, 'product' => 1, 'quantity' => '1', 'deliveryType' => '', 'validFrom' => 1_000, 'subscription' => 4]);

        $view = (new OrderPageDownloads($this->connection, $this->files(), new KeyPoolHandler($this->connection)))->view(5, 2_000);

        self::assertSame([[true, false, 1_500, false], [true, true, null, false]], array_map(
            static fn (array $e): array => [$e['service'], $e['subscription'], $e['performedAt'], $e['file']],
            $view,
        ));
    }

    /**
     * After a cancellation or an accepted withdrawal of the whole line the
     * order page hands out nothing any more — a withdrawal not yet decided
     * changes nothing.
     */
    public function testNachStornoOderAngenommenemWiderrufKeinDownload(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 5, 'productName' => 'E-Book', 'quantity' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 1, 'pid' => 5, 'orderItem' => 10, 'product' => 1, 'quantity' => '1', 'deliveryType' => 'file', 'validFrom' => 1_000]);
        $downloads = new OrderPageDownloads($this->connection, $this->files(), new KeyPoolHandler($this->connection));

        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 5, 'state' => 'received']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 10, 'quantity' => 1]);
        self::assertNotNull($downloads->download(5, 1, 2_000), 'nicht entschieden: noch da');

        $this->connection->update('tl_consho_withdrawal', ['state' => 'accepted'], ['id' => 1]);
        self::assertNull($downloads->download(5, 1, 2_000), 'angenommen');
        self::assertFalse($downloads->view(5, 2_000)[0]['active']);

        $this->connection->update('tl_consho_withdrawal', ['state' => 'rejected'], ['id' => 1]);
        self::assertNotNull($downloads->download(5, 1, 2_000), 'abgelehnt: wieder da');

        $this->connection->update('tl_consho_order', ['cancelledAt' => 1_500], ['id' => 5]);
        self::assertNull($downloads->download(5, 1, 2_000), 'storniert');
    }

    public function testEinHinweisNenntArtikelOhneAbrufbareDatei(): void
    {
        $issues = (new DigitalFileIssueSource($this->connection, $this->files()))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::DigitalFileMissing, $issues[0]->finding);
        self::assertSame(['count' => '3', 'products' => 'Flyer, Weg, Ohne'], $issues[0]->context);
    }

    public function testDieMaskeLehntEineOeffentlicheDateiAb(): void
    {
        $listener = new DigitalDeliveryListener(new DeliveryHandlers(new \Symfony\Component\DependencyInjection\ServiceLocator([])), $this->connection, new ArchiveFolderProtector($this->dir));

        self::assertSame(self::PROTECTED, $listener->refusePublicFile(self::PROTECTED));
        self::assertNull($listener->refusePublicFile(null));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#files/public#');
        $listener->refusePublicFile(self::PUBLIC);
    }

    private function files(): DigitalFiles
    {
        return new DigitalFiles($this->connection, new ArchiveFolderProtector($this->dir), $this->dir);
    }

    private function entitlement(int $product): Entitlement
    {
        return new Entitlement(1, 5, 10, 0, $product, '1.0000', 'file', 1_000, null);
    }
}
