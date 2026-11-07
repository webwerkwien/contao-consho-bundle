<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DeliveryCreateButtonListener;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * "Neue Lieferung" stood on every order's delivery list, also where nothing
 * is left to ship, and its click ended in a 403.
 */
final class DeliveryCreateButtonListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        // Order 7: one hiking pole open. Order 8: shipped in full.
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '10']);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'orderNumber' => 'WEB-8', 'grandTotal' => '10']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Wanderstock', 'quantity' => '1']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 8, 'productName' => 'Rucksack', 'quantity' => '1']);
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 8, 'reportedAt' => 100]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 2, 'quantity' => '1']);

        unset($GLOBALS['TL_DCA']['tl_consho_delivery']['config']['notCreatable']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_consho_delivery']['config']['notCreatable']);
    }

    public function testMitOffenerWareBleibtDerKnopf(): void
    {
        $this->listener('/contao?do=consho_order&table=tl_consho_delivery&id=7')();

        self::assertArrayNotHasKey('notCreatable', $GLOBALS['TL_DCA']['tl_consho_delivery']['config'] ?? []);
    }

    public function testVollstaendigVersandtOhneKnopf(): void
    {
        $this->listener('/contao?do=consho_order&table=tl_consho_delivery&id=8')();

        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_delivery']['config']['notCreatable'] ?? false);
    }

    /**
     * Only the list: in the edit mask `id` is the delivery, not the order.
     */
    public function testInDerMaskeNichts(): void
    {
        $this->listener('/contao?do=consho_order&table=tl_consho_delivery&act=edit&id=8')();

        self::assertArrayNotHasKey('notCreatable', $GLOBALS['TL_DCA']['tl_consho_delivery']['config'] ?? []);
    }

    private function listener(string $uri): DeliveryCreateButtonListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create($uri));
        $balances = new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());

        return new DeliveryCreateButtonListener($requests, new DeliveryContents($this->connection, $balances));
    }
}
