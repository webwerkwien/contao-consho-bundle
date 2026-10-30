<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ShopDefaultStatusListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * A new shop starts with five order statuses, named in the back end
 * language and freely editable — without them its order list has nothing
 * to show.
 */
class ShopDefaultStatusListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order_status');

        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testEinNeuerShopBekommtFuenfStatus(): void
    {
        $this->listener()('tl_consho_shop', 7, [], $this->createStub(DataContainer::class));

        self::assertSame(
            [
                ['Neu', 'open', 0, 0, 0, 0],
                ['Bezahlt', 'progress', 1, 0, 0, 0],
                ['Versendet', 'progress', 0, 1, 0, 0],
                ['Abgeschlossen', 'done', 0, 0, 1, 0],
                ['Storniert', 'attention', 0, 0, 0, 1],
            ],
            array_map(
                static fn (array $row): array => [$row['title'], $row['colour'], (int) $row['isPaid'], (int) $row['isShipped'], (int) $row['isCompleted'], (int) $row['isCancelled']],
                $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_order_status WHERE pid = 7 ORDER BY sorting'),
            ),
        );
    }

    /**
     * Never a second set — whatever reaches the callback twice.
     */
    public function testHatDerShopSchonStatusKommtNichtsDazu(): void
    {
        $this->connection->insert('tl_consho_order_status', ['pid' => 7, 'title' => 'Eigener']);

        $this->listener()('tl_consho_shop', 7, [], $this->createStub(DataContainer::class));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order_status WHERE pid = 7'));
    }

    private function listener(): ShopDefaultStatusListener
    {
        return new ShopDefaultStatusListener($this->connection);
    }
}
