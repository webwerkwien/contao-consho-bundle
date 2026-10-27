<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\StockListListener;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\PurchaseLists;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block G, G-7 (G16): the columns of the "Stock level" page — stock,
 * ordered (E1), state, wishes, supplier; for keys the free codes.
 */
class StockListListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach ([
            'tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_stock_movement', 'tl_consho_stock_notice',
            'tl_consho_purchase_list', 'tl_consho_purchase_item', 'tl_consho_key', 'tl_consho_order', 'tl_consho_order_item',
            'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item',
        ] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Wanderstock', 'stockTracked' => 1, 'stockWarning' => '5.0000', 'supplier' => 'Bergsport KG']);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'title' => 'rot', 'sku' => 'WS-R']);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'title' => 'Lizenz', 'productType' => 2, 'digitalDelivery' => 'key']);
        (new StockRows($this->connection))->sync(1, 100);
        (new StockRows($this->connection))->sync(3, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '3.0000'], ['article' => 2]);
        $this->connection->insert('tl_consho_key', ['pid' => 3, 'code' => 'A']);
        $this->connection->insert('tl_consho_key', ['pid' => 3, 'code' => 'B']);
        $this->connection->insert('tl_consho_stock_notice', ['article' => 2, 'member' => 5]);

        $GLOBALS['TL_LANG']['tl_consho_stock']['keysFree'] = '%s frei';
        $GLOBALS['TL_LANG']['CONSHO']['stock'] = ['available' => 'lieferbar', 'few' => 'nur noch wenige'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDieSpalten(): void
    {
        (new PurchaseLists($this->connection, new StockBooking($this->connection)))->create([2 => '10'], 7, 1000);

        self::assertSame(
            ['Wanderstock › rot', 'WS-R', '3', '10', 'nur noch wenige', '', '1', 'Bergsport KG'],
            $this->listener()->label($this->row(2), '', $this->createStub(DataContainer::class), []),
        );

        self::assertSame(
            ['Lizenz', '', '2 frei', '', 'lieferbar', '', '', ''],
            $this->listener()->label($this->row(3), '', $this->createStub(DataContainer::class), []),
        );
    }

    /**
     * Nachlese K4 (G-7): the columns of all rows come with the first row, not
     * four queries per row. Proven by a later row showing what applied at the
     * first load.
     */
    public function testDieSpaltenAllerZeilenKommenMitDerErsten(): void
    {
        $listener = $this->listener();
        $listener->label($this->row(3), '', $this->createStub(DataContainer::class), []);

        $this->connection->delete('tl_consho_stock_notice', ['article' => 2]);

        self::assertSame('1', $listener->label($this->row(2), '', $this->createStub(DataContainer::class), [])[6]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $article): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_stock WHERE article = ?', [$article]);
        self::assertIsArray($row);

        return $row;
    }

    private function listener(): StockListListener
    {
        return new StockListListener(
            $this->connection,
            new Availability($this->connection),
            new PurchaseLists($this->connection, new StockBooking($this->connection)),
            $this->createStub(UrlGeneratorInterface::class),
        );
    }
}
