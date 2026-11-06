<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Event\DataContainerRecordLabelEvent;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ComplaintItemListener;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block Reklamation: the lines of a complaint are goods lines of its order,
 * each once, at most what was ordered.
 */
final class ComplaintItemListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order_item', 'tl_consho_complaint', 'tl_consho_complaint_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_complaint', ['id' => 4, 'pid' => 7, 'number' => 'WEB-00077-R1']);
        $this->connection->insert('tl_consho_order_item', ['id' => 11, 'pid' => 7, 'lineType' => 'product', 'productName' => 'Hiking pole', 'quantity' => '2.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 12, 'pid' => 7, 'lineType' => 'shipping', 'productName' => 'Shipping', 'quantity' => '1.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 13, 'pid' => 8, 'lineType' => 'product', 'productName' => 'Elsewhere', 'quantity' => '1.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 14, 'pid' => 7, 'lineType' => 'product', 'productName' => 'Rope', 'quantity' => '2.5000']);
        $this->connection->insert('tl_consho_complaint_item', ['id' => 20, 'pid' => 4, 'orderItem' => 11, 'quantity' => '1.0000']);

        $GLOBALS['TL_LANGUAGE'] = 'de';
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/de/tl_consho_complaint_item.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testOffersOnlyTheGoodsLinesOfTheOrder(): void
    {
        self::assertSame([11 => 'Hiking pole (2)', 14 => 'Rope (2,5)'], $this->listener()->options($this->dc(['id' => 21, 'pid' => 4])));
    }

    public function testListColumnsNameTheProductOnceAndFormatTheQuantity(): void
    {
        self::assertSame(['Hiking pole', '2', 'lid &lt;cracked&gt;'], $this->listener()->label(['orderItem' => 11, 'quantity' => '2.0000', 'defect' => 'lid <cracked>']));
        self::assertSame(['Rope', '2,5', ''], $this->listener()->label(['orderItem' => 14, 'quantity' => '2.5000', 'defect' => null]));
    }

    public function testTheHeadlineNamesTheProduct(): void
    {
        $event = new DataContainerRecordLabelEvent('contao.db.tl_consho_complaint_item.20', ['id' => 20, 'orderItem' => 11]);
        $this->listener()->recordLabel($event);
        self::assertSame('Hiking pole', $event->getLabel());

        $other = new DataContainerRecordLabelEvent('contao.db.tl_consho_order_item.11', ['id' => 11, 'orderItem' => 11]);
        $this->listener()->recordLabel($other);
        self::assertNull($other->getLabel(), 'other tables keep their label');
    }

    public function testWithoutARecordNothingIsOffered(): void
    {
        self::assertSame([], $this->listener()->options($this->dc(null)));
    }

    public function testEachLineOnlyOnce(): void
    {
        $this->expectExceptionMessage('steht in der Reklamation schon');
        $this->listener()->orderItem('11', $this->dc(['id' => 21, 'pid' => 4]));
    }

    public function testTheLineMayStayOnItsOwnRecord(): void
    {
        self::assertSame(11, $this->listener()->orderItem('11', $this->dc(['id' => 20, 'pid' => 4])));
    }

    public function testRefusesALineOfAnotherOrder(): void
    {
        $this->expectExceptionMessage('Warenzeile dieser Bestellung');
        $this->listener()->orderItem('13', $this->dc(['id' => 21, 'pid' => 4]));
    }

    public function testRefusesAChargeLine(): void
    {
        $this->expectExceptionMessage('Warenzeile dieser Bestellung');
        $this->listener()->orderItem('12', $this->dc(['id' => 21, 'pid' => 4]));
    }

    public function testQuantityUpToWhatWasOrdered(): void
    {
        $dc = $this->dc(['id' => 20, 'pid' => 4, 'orderItem' => 14]);

        self::assertSame('2.5', $this->listener()->quantity('2.5', $dc));
        self::assertSame('0.5', $this->listener()->quantity('0.5', $dc));
    }

    public function testQuantityAboveWhatWasOrdered(): void
    {
        $this->expectExceptionMessage('höchstens 2,5 eingeben');
        $this->listener()->quantity('2.6', $this->dc(['id' => 20, 'pid' => 4, 'orderItem' => 14]));
    }

    public function testQuantityZero(): void
    {
        $this->expectExceptionMessage('über 0');
        $this->listener()->quantity('0', $this->dc(['id' => 20, 'pid' => 4, 'orderItem' => 11]));
    }

    public function testTheLinePostedWithTheQuantityCounts(): void
    {
        // A new line: the stored record has no line yet, the form has one.
        $stack = new RequestStack();
        $stack->push(new Request([], ['orderItem' => '11']));
        $listener = new ComplaintItemListener($this->connection, $stack, new MoneyFormatter());

        self::assertSame('2', $listener->quantity('2', $this->dc(['id' => 21, 'pid' => 4, 'orderItem' => 0])));
    }

    private function listener(): ComplaintItemListener
    {
        return new ComplaintItemListener($this->connection, new RequestStack(), new MoneyFormatter());
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function dc(array|null $record): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn($record);

        return $dc;
    }
}
