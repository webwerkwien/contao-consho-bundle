<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveredItem;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlers;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalFiles;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPoolHandler;
use Webwerkwien\ContaoConshoBundle\Digital\OrderPageDownloads;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block Lizenz, LZ-1:
 * What a module has delivered, on the order page — text, download,
 * button. Only for a usable entitlement of one's own order (E4), only what
 * the module offers on exactly this entry (E1, E2).
 */
class PresentableDeliveryTest extends TestCase
{
    private Connection $connection;

    private FakePresentableHandler $handler;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_order_item', 'tl_consho_order', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item', 'tl_consho_download', 'tl_consho_key'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        DigitalSchema::create($this->connection);
        $this->connection->insert('tl_consho_order', ['id' => 5, 'orderNumber' => 'WEB-5']);
        $this->connection->insert('tl_consho_order', ['id' => 6, 'orderNumber' => 'WEB-6']);
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 5, 'productName' => 'Connect', 'quantity' => 1]);

        $base = ['pid' => 5, 'orderItem' => 10, 'product' => 1, 'quantity' => '1', 'validFrom' => 1_000];
        // 1 active, 2 suspended, 3 blocked, 4 foreign order, 5 other delivery type
        $this->connection->insert('tl_consho_entitlement', ['id' => 1, 'deliveryType' => 'testlicence'] + $base);
        $this->connection->insert('tl_consho_entitlement', ['id' => 2, 'deliveryType' => 'testlicence', 'suspendedAt' => 1_500] + $base);
        $this->connection->insert('tl_consho_entitlement', ['id' => 3, 'deliveryType' => 'testlicence', 'revoked' => 1] + $base);
        $this->connection->insert('tl_consho_entitlement', ['id' => 4, 'deliveryType' => 'testlicence', 'pid' => 6] + $base);
        $this->connection->insert('tl_consho_entitlement', ['id' => 5, 'deliveryType' => 'file'] + $base);

        $this->handler = new FakePresentableHandler();
    }

    public function testDieSeiteZeigtWasDasModulAusgeliefertHatNurSolangeAktiv(): void
    {
        $view = $this->downloads()->view(5, 2_000);

        self::assertSame([
            ['id' => 'lic-1', 'label' => 'Lizenz 1', 'text' => 'KEY-1', 'download' => true, 'action' => 'release', 'actionLabel' => 'Installation freigeben'],
            ['id' => 'note', 'label' => 'Hinweis', 'text' => 'nur Text', 'download' => false, 'action' => null, 'actionLabel' => null],
        ], $view[0]['items']);
        self::assertSame([], $view[1]['items'], 'ruht: nichts (E4)');
        self::assertSame([], $view[2]['items'], 'gesperrt: nichts');
        self::assertSame([], $view[3]['items'], 'andere Auslieferungsart: nichts');
        self::assertSame([[], [], [], []], array_column((new OrderPageDownloads($this->connection, $this->files(), new KeyPoolHandler($this->connection)))->view(5, 2_000), 'items'), 'ohne Module: nichts');
    }

    public function testDerDownloadNurFuerEinAngebotenesStueckPrivatUndProtokolliert(): void
    {
        $response = $this->downloads()->download(5, 1, 2_000, 'lic-1');

        self::assertNotNull($response);
        self::assertSame('ZIP-lic-1', $response->getContent());
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'), 'das Modul setzte public');
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_download WHERE pid = 1'));

        $downloads = $this->downloads();
        self::assertNull($downloads->download(5, 1, 2_000, 'note'), 'ohne Download angeboten');
        self::assertNull($downloads->download(5, 1, 2_000, 'lic-9'), 'unbekanntes Stück');
        self::assertNull($downloads->download(5, 2, 2_000, 'lic-2'), 'ruht');
        self::assertNull($downloads->download(5, 3, 2_000, 'lic-3'), 'gesperrt');
        self::assertNull($downloads->download(5, 4, 2_000, 'lic-4'), 'fremde Bestellung');
        self::assertNull($downloads->download(5, 5, 2_000, 'lic-5'), 'Datei-Auslieferung hat keine Stücke');
        self::assertSame(['download lic-1'], $this->handler->calls, 'das Modul wird nur für das Angebotene gefragt');
    }

    public function testDieAktionNurWoSieAngebotenIst(): void
    {
        $downloads = $this->downloads();

        self::assertSame('testReleased', $downloads->act(5, 1, 'lic-1', 'release', 2_000));
        self::assertNull($downloads->act(5, 1, 'lic-1', 'delete', 2_000), 'nicht angebotene Aktion');
        self::assertNull($downloads->act(5, 1, 'note', 'release', 2_000), 'Stück ohne Aktion');
        self::assertNull($downloads->act(5, 2, 'lic-2', 'release', 2_000), 'ruht (E4)');
        self::assertNull($downloads->act(5, 4, 'lic-4', 'release', 2_000), 'fremde Bestellung');
        self::assertSame(['act lic-1 release'], $this->handler->calls);
    }

    public function testEinStueckBrauchtEineSaubereKennungUndEineBeschriftung(): void
    {
        self::assertTrue((new DeliveredItem('a.b_c-1', 'X', null, false, 'release', 'Los'))->offers('release'));
        self::assertFalse((new DeliveredItem('a', 'X'))->offers(''));

        foreach ([fn () => new DeliveredItem('a b', 'X'), fn () => new DeliveredItem('', 'X'), fn () => new DeliveredItem('a', 'X', null, false, 'release'), fn () => new DeliveredItem('a', 'X', null, false, 're lease', 'Los')] as $build) {
            try {
                $build();
                self::fail('abgelehnt erwartet');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function downloads(): OrderPageDownloads
    {
        return new OrderPageDownloads(
            $this->connection,
            $this->files(),
            new KeyPoolHandler($this->connection),
            null,
            new DeliveryHandlers(new ServiceLocator([FakePresentableHandler::getType() => fn (): FakePresentableHandler => $this->handler])),
        );
    }

    private function files(): DigitalFiles
    {
        return new DigitalFiles($this->connection, new ArchiveFolderProtector(sys_get_temp_dir()), sys_get_temp_dir());
    }
}
