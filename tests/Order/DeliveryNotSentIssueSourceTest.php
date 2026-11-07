<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryNotSentIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * A reported delivery whose mail did not go out is a warning, as an order
 * confirmation that did not.
 */
final class DeliveryNotSentIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_delivery'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);

        foreach ([1, 2, 3, 4] as $id) {
            $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => 1, 'orderNumber' => 'WEB-'.$id]);
        }
    }

    public function testNurGemeldetUndNichtGesendet(): void
    {
        $this->delivery(1, reportedAt: 100, notifiedAt: null);
        $this->delivery(2, reportedAt: 100, notifiedAt: 200);
        $this->delivery(3, reportedAt: 100, notifiedAt: 0);
        $this->delivery(4, reportedAt: null, notifiedAt: null);

        $issues = (new DeliveryNotSentIssueSource($this->connection))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::DeliveryNotSent, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame(['consho_order'], $issues[0]->finding->fixedIn());
        self::assertSame(['shop' => 'Wien', 'count' => '1', 'numbers' => 'WEB-1'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
    }

    /**
     * Nothing can be sent to an anonymised order any more — a warning about
     * it could never be cleared.
     */
    public function testEineAnonymisierteBestellungWarntNicht(): void
    {
        $this->connection->update('tl_consho_order', ['anonymizedAt' => 1], ['id' => 1]);
        $this->delivery(1, reportedAt: 100, notifiedAt: null);

        self::assertSame([], (new DeliveryNotSentIssueSource($this->connection))->issues());
    }

    private function delivery(int $order, int|null $reportedAt, int|null $notifiedAt): void
    {
        $this->connection->insert('tl_consho_delivery', ['pid' => $order, 'reportedAt' => $reportedAt, 'notifiedAt' => $notifiedAt]);
    }
}
