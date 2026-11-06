<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ComplaintListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block Reklamation: the number on creation.
 */
final class ComplaintListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_complaint');
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-00077']);
    }

    public function testNumberIsOneAboveTheHighestAndLeavesGaps(): void
    {
        $this->connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => 7, 'number' => 'WEB-00077-R1']);
        $this->connection->insert('tl_consho_complaint', ['id' => 2, 'pid' => 7, 'number' => 'WEB-00077-R3']);
        $this->connection->insert('tl_consho_complaint', ['id' => 3, 'pid' => 7]);

        $this->listener()->number('tl_consho_complaint', 3, ['pid' => 7], $this->dc([]));

        self::assertSame('WEB-00077-R4', $this->connection->fetchOne('SELECT number FROM tl_consho_complaint WHERE id = 3'));
    }

    public function testFirstComplaintOfAnOrder(): void
    {
        $this->connection->insert('tl_consho_complaint', ['id' => 5, 'pid' => 7]);

        $this->listener()->number('tl_consho_complaint', 5, ['pid' => 7], $this->dc([]));

        self::assertSame('WEB-00077-R1', $this->connection->fetchOne('SELECT number FROM tl_consho_complaint WHERE id = 5'));
    }

    public function testNumbersOfOtherOrdersDoNotCount(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 8, 'orderNumber' => 'WEB-00078']);
        $this->connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => 8, 'number' => 'WEB-00078-R5']);
        $this->connection->insert('tl_consho_complaint', ['id' => 2, 'pid' => 7]);

        $this->listener()->number('tl_consho_complaint', 2, ['pid' => 7], $this->dc([]));

        self::assertSame('WEB-00077-R1', $this->connection->fetchOne('SELECT number FROM tl_consho_complaint WHERE id = 2'));
    }

    /**
     * RK-6, A5: the state changes only on the complaint page, never in the
     * mask.
     */
    public function testTheMaskHasNoResolvedDate(): void
    {
        $GLOBALS['TL_DCA']['tl_consho_complaint'] = [];
        require __DIR__.'/../../../contao/dca/tl_consho_complaint.php';
        $dca = $GLOBALS['TL_DCA']['tl_consho_complaint'];
        unset($GLOBALS['TL_DCA']['tl_consho_complaint']);

        self::assertSame('{complaint_legend},number,receivedAt,note', $dca['palettes']['default']);
        self::assertArrayNotHasKey('inputType', $dca['fields']['resolvedAt']);
        self::assertArrayNotHasKey('onsubmit_callback', $dca['config']);
        self::assertFalse(method_exists(ComplaintListener::class, 'state'));
    }

    private function listener(): ComplaintListener
    {
        return new ComplaintListener($this->connection);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function dc(array $record): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn($record);

        return $dc;
    }
}
