<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\ServicePerformed;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * DL3: "Service performed" only on an unlocked service without a
 * subscription, and only once.
 *
 * Lines of order 7: 1 consulting package (unlocked), 2 consulting
 * (not unlocked), 3 membership (subscription), 4 e-book, 5 shipping,
 * 6 consulting with a revoked entitlement.
 */
class ServicePerformedTest extends TestCase
{
    private const NOW = 1_759_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_entitlement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7']);

        foreach ([
            1 => ['kind' => 'service'],
            2 => ['kind' => 'service'],
            3 => ['kind' => 'service', 'subscriptionRule' => 4],
            4 => ['kind' => 'digital'],
            5 => ['kind' => '', 'lineType' => 'shipping'],
            6 => ['kind' => 'service'],
        ] as $id => $values) {
            $this->connection->insert('tl_consho_order_item', ['id' => $id, 'pid' => 7, 'productName' => 'Zeile '.$id, ...$values]);
        }

        foreach ([1, 3, 4] as $item) {
            $this->connection->insert('tl_consho_entitlement', ['pid' => 7, 'orderItem' => $item]);
        }

        $this->connection->insert('tl_consho_entitlement', ['pid' => 7, 'orderItem' => 6, 'revoked' => 1]);
    }

    public function testEineFreigeschalteteDienstleistungWirdGemeldet(): void
    {
        self::assertSame(ServicePerformed::PERFORMED, $this->service()->report(1, self::NOW));
        self::assertSame(self::NOW, (int) $this->connection->fetchOne('SELECT performedAt FROM tl_consho_order_item WHERE id = 1'));
    }

    public function testNurEinmal(): void
    {
        $this->service()->report(1, self::NOW);

        self::assertSame(ServicePerformed::ALREADY_PERFORMED, $this->service()->report(1, self::NOW + 60));
        self::assertSame(self::NOW, (int) $this->connection->fetchOne('SELECT performedAt FROM tl_consho_order_item WHERE id = 1'), 'nicht überschrieben');
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function abgelehnt(): iterable
    {
        yield 'nicht freigeschaltet' => [2, ServicePerformed::NOT_RELEASED];
        yield 'Abo' => [3, ServicePerformed::SUBSCRIPTION];
        yield 'digital' => [4, ServicePerformed::NOT_SERVICE];
        yield 'Versand' => [5, ServicePerformed::NOT_SERVICE];
        yield 'widerrufen' => [6, ServicePerformed::WITHDRAWN];
        yield 'unbekannt' => [99, ServicePerformed::NOT_SERVICE];
    }

    /**
     * @dataProvider abgelehnt
     */
    public function testAbgelehntOhneZuSchreiben(int $item, string $outcome): void
    {
        self::assertSame($outcome, $this->service()->report($item, self::NOW));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order_item WHERE performedAt IS NOT NULL'));
    }

    private function service(): ServicePerformed
    {
        // SQLite has no FOR UPDATE; the lock returns the row like the
        // real one does: every value as a string.
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturnCallback(fn (int $id): array|null => 7 === $id ? ['id' => '7', 'orderNumber' => 'WEB-7'] : null);

        return new ServicePerformed($this->connection, $orders);
    }
}
