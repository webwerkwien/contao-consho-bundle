<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dashboard;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Dashboard\SalesFigures;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Dashboard DB5: orders and order value today, 7 days, current month.
 *
 * The boundaries are written down as fixed points in time (UTC), not
 * computed — otherwise an error in the calculation would sit on both sides.
 */
final class SalesFiguresTest extends TestCase
{
    /** 2026-10-20 12:00 UTC. */
    private const NOW = 1_792_497_600;

    /** 2026-10-20 00:00 UTC — today. */
    private const TODAY = 1_792_454_400;

    /** 2026-10-14 00:00 UTC — the first of the seven days. */
    private const WEEK = 1_791_936_000;

    /** 2026-10-01 00:00 UTC — the month. */
    private const MONTH = 1_790_812_800;

    private Connection $connection;

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        $this->connection->insert('tl_consho_order_status', ['id' => 9, 'isCancelled' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => 4, 'isCancelled' => 0]);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testHeuteSiebenTageMonat(): void
    {
        $this->order(1, self::TODAY, '10.0000');
        $this->order(1, self::NOW, '5.5000');
        $this->order(1, self::TODAY - 1, '20.0000', 'EUR', 'gestern: Woche und Monat');
        $this->order(1, self::WEEK, '1.0000', 'EUR', 'genau an der Wochengrenze');
        $this->order(1, self::WEEK - 1, '100.0000', 'EUR', 'nur Monat');
        $this->order(1, self::MONTH, '2.0000', 'EUR', 'genau am Monatsersten');
        $this->order(1, self::MONTH - 1, '999.0000', 'EUR', 'Vormonat');
        $this->order(1, self::NOW, '50.0000', 'EUR', 'storniert', cancelled: true);
        $this->order(1, self::NOW, '60.0000', 'EUR', 'über den Status storniert, cancelledAt leer', status: 9);
        $this->order(1, self::NOW - 1, '0.0000', 'EUR', 'Status, der nicht storniert', status: 4);
        $this->order(1, self::NOW, '7.0000', 'CHF');
        $this->order(2, self::NOW, '3.0000');

        $figures = (new SalesFigures($this->connection))->byShop(self::NOW);

        self::assertSame(['orders' => 3, 'total' => '15.5'], $this->normalised($figures[1]['EUR'][SalesFigures::TODAY]));
        self::assertSame(['orders' => 5, 'total' => '36.5'], $this->normalised($figures[1]['EUR'][SalesFigures::WEEK]));
        self::assertSame(['orders' => 7, 'total' => '138.5'], $this->normalised($figures[1]['EUR'][SalesFigures::MONTH]));
        self::assertSame(['orders' => 1, 'total' => '7'], $this->normalised($figures[1]['CHF'][SalesFigures::TODAY]), 'je Währung');
        self::assertSame(1, $figures[2]['EUR'][SalesFigures::TODAY]['orders'], 'je Shop');
    }

    /**
     * At the start of a month the seven days reach into the previous month —
     * the query has to read from the earlier of the two points in time.
     */
    public function testDieWocheReichtInDenVormonat(): void
    {
        // 2026-10-03 12:00 UTC; the week starts on 2026-09-27.
        $now = 1_791_028_800;
        $this->order(1, 1_790_726_400, '4.0000', 'EUR', '2026-09-30 00:00');

        $figures = (new SalesFigures($this->connection))->byShop($now);

        self::assertSame(1, $figures[1]['EUR'][SalesFigures::WEEK]['orders']);
        self::assertSame(0, $figures[1]['EUR'][SalesFigures::MONTH]['orders']);
    }

    /**
     * @param array{orders: int, total: string} $figure
     *
     * @return array{orders: int, total: string}
     */
    private function normalised(array $figure): array
    {
        // SQLite sums decimals as float, MariaDB exactly; compared as numbers.
        return ['orders' => $figure['orders'], 'total' => rtrim(rtrim(number_format((float) $figure['total'], 4, '.', ''), '0'), '.')];
    }

    private function order(int $shop, int $createdAt, string $total, string $currency = 'EUR', string $note = '', bool $cancelled = false, int $status = 0): void
    {
        static $id = 0;
        ++$id;

        $this->connection->insert('tl_consho_order', [
            'shop' => $shop,
            'orderNumber' => 'WEB-'.$id,
            'createdAt' => $createdAt,
            'grandTotal' => $total,
            'currency' => $currency,
            'language' => 'de',
            'cancelledAt' => $cancelled ? $createdAt : null,
            'orderStatus' => $status,
        ]);
    }
}
