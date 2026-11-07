<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\WithdrawalItemListener;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The positions of a withdrawal in columns, quantities without the four
 * decimals the database stores.
 */
final class WithdrawalItemListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_consho_withdrawal_item']);
    }

    public function testEineZelleJeSpalteUndMengenOhneNachkommanullen(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_order_item');
        $connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => 1, 'productName' => 'Kräutersalz <fein>', 'quantity' => '2']);

        require __DIR__.'/../../../contao/dca/tl_consho_withdrawal_item.php';

        $listener = new WithdrawalItemListener($connection, new MoneyFormatter());
        $cells = $listener->label(['orderItem' => 3, 'declared' => 1, 'quantity' => '2.0000', 'acceptedQuantity' => '1.5000', 'returnedQuantity' => '1.0000', 'restockedQuantity' => '0.0000']);

        self::assertCount(\count($GLOBALS['TL_DCA']['tl_consho_withdrawal_item']['list']['label']['fields']), $cells);
        self::assertSame(['Kräutersalz &lt;fein&gt;', '2', '1,5', '1', '0'], $cells);

        // Added by the operator when accepting: the customer declared nothing.
        self::assertSame('–', $listener->label(['orderItem' => 3, 'declared' => 0, 'quantity' => '0.0000', 'acceptedQuantity' => '1.0000'])[1]);
    }
}
