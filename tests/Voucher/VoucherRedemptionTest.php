<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Order\OrderDraft;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherChangedAtPurchase;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherRedemption;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;

/**
 * WG-5 (6.5, E4, E5): Beim Kaufklick zahlt jeder Gutschein, was die Seite
 * zeigte — Bewegung `redeem` und Zahlung an der Bestellung, die aufeinander
 * zeigen. Kann er es nicht mehr, wird nichts gebucht.
 */
class VoucherRedemptionTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_voucher', 'tl_consho_voucher_booking', 'tl_consho_payment'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_voucher', ['id' => 1, 'code' => 'AAAABBBBCCCCMDSN', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '50', 'balance' => '50']);
        $this->connection->insert('tl_consho_voucher', ['id' => 2, 'code' => 'EEEEFFFFGGGGHHHH', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '10', 'balance' => '10']);
    }

    public function testJederGutscheinZahltUndBuchtMitVerweis(): void
    {
        $this->redemption()->redeem(77, $this->draft([[1, '30.00'], [2, '10.00']]), self::NOW);

        // Zahlen, nicht Zeichenketten: SQLite gibt '20' zurück, MySQL '20.0000' (Fall 30).
        self::assertEquals([20, 0], $this->connection->fetchFirstColumn('SELECT balance FROM tl_consho_voucher ORDER BY id'));

        $payments = $this->connection->fetchAllAssociative('SELECT id, pid, amount, method, reference, receivedAt FROM tl_consho_payment ORDER BY id');
        self::assertCount(2, $payments);
        self::assertSame(77, (int) $payments[0]['pid']);
        self::assertEquals(30, $payments[0]['amount']);
        // E5: nur die letzte Gruppe.
        self::assertSame('Gift voucher …MDSN', $payments[0]['method']);
        self::assertSame('voucher:1', $payments[0]['reference']);
        self::assertSame(self::NOW, (int) $payments[0]['receivedAt']);

        $bookings = $this->connection->fetchAllAssociative('SELECT pid, type, amount, orderId, paymentId FROM tl_consho_voucher_booking ORDER BY id');
        self::assertSame(['redeem', 'redeem'], array_column($bookings, 'type'));
        self::assertEquals(-30, $bookings[0]['amount']);
        self::assertSame(77, (int) $bookings[0]['orderId']);
        self::assertSame((int) $payments[0]['id'], (int) $bookings[0]['paymentId']);
    }

    /**
     * E4: zwischen Seite und Klick anderswo eingelöst — der Saldo reicht
     * nicht mehr. Die Ausnahme rollt in OrderPlacer alles zurück.
     */
    public function testReichtDerSaldoNichtMehrWirdNichtBestellt(): void
    {
        $this->connection->update('tl_consho_voucher', ['balance' => '5'], ['id' => 2]);

        $this->expectException(VoucherChangedAtPurchase::class);

        $this->redemption()->redeem(77, $this->draft([[2, '10.00']]), self::NOW);
    }

    public function testEinGesperrterGutscheinZahltNicht(): void
    {
        $this->connection->update('tl_consho_voucher', ['blocked' => 1], ['id' => 1]);

        try {
            $this->redemption()->redeem(77, $this->draft([[1, '30.00']]), self::NOW);
            self::fail('Ein gesperrter Gutschein darf nicht zahlen.');
        } catch (VoucherChangedAtPurchase) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_voucher_booking'));
    }

    /**
     * Review WG-7, L1: erst alle Gutscheine gesperrt, in Id-Reihenfolge —
     * auch wenn der Korb sie andersherum nennt.
     */
    public function testSperrtZuerstAlleInIdReihenfolge(): void
    {
        SqliteWithoutRowLocks::$locked = [];

        $this->redemption()->redeem(77, $this->draft([[2, '5.00'], [1, '5.00']]), self::NOW);

        $locked = $this->lockedStatements();
        self::assertStringContainsString('WHERE id IN (?, ?) ORDER BY id FOR UPDATE', $locked[0] ?? '');
    }

    /**
     * @return list<string>
     */
    private function lockedStatements(): array
    {
        return SqliteWithoutRowLocks::$locked;
    }

    public function testEinGutscheinEinesAnderenShopsZahltNicht(): void
    {
        $this->expectException(VoucherChangedAtPurchase::class);

        $this->redemption()->redeem(77, $this->draft([[1, '30.00']], shop: 2), self::NOW);
    }

    /**
     * @param list<array{int, string}> $vouchers
     */
    private function draft(array $vouchers, int $shop = 1): OrderDraft
    {
        return new OrderDraft(
            shopId: $shop,
            currency: 'EUR',
            billing: new Address(),
            shipping: new Address(),
            vouchers: array_map(static fn (array $v): array => ['id' => $v[0], 'code' => '', 'amount' => $v[1]], $vouchers),
        );
    }

    private function redemption(): VoucherRedemption
    {
        return new VoucherRedemption($this->connection, new VoucherLedger($this->connection), new VoucherWallet($this->connection));
    }
}
