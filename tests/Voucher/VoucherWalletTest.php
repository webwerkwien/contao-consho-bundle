<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\AppliedVoucher;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherStatus;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;

/**
 * WG-5 (6.5): Gutscheine decken in der Reihenfolge der Eingabe, jeder bis zu
 * seinem Saldo, bis nichts mehr offen ist — und nur, wer gerade zahlen kann.
 */
class VoucherWalletTest extends TestCase
{
    // 2027-01-15 11:00 in Wien
    private const NOW = 1_800_007_200;

    private Connection $connection;

    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_voucher');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testDecktInDerReihenfolgeDerEingabe(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '20.0000');
        $this->voucher(2, 'BBBBBBBBBBBBBBBB', '50.0000');

        $applied = $this->wallet()->apply(['BBBBBBBBBBBBBBBB', 'AAAAAAAAAAAAAAAA'], 1, 'EUR', '60.00', 2, self::NOW);

        self::assertSame([['BBBBBBBBBBBBBBBB', '50.00', '0.0000'], ['AAAAAAAAAAAAAAAA', '10.00', '10.0000']], $this->summary($applied));
    }

    public function testNieMehrAlsOffenIst(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '20.0000');
        $this->voucher(2, 'BBBBBBBBBBBBBBBB', '50.0000');

        $applied = $this->wallet()->apply(['AAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBB'], 1, 'EUR', '15.50', 2, self::NOW);

        self::assertSame([['AAAAAAAAAAAAAAAA', '15.50', '4.5000'], ['BBBBBBBBBBBBBBBB', '0.00', '50.0000']], $this->summary($applied));
    }

    /**
     * Ein Saldo mit vier Stellen zahlt nur, was die Währung kennt — nie einen
     * Bruchteil mehr, als er hat.
     */
    public function testRundetAbNieAuf(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '9.9950');

        $applied = $this->wallet()->apply(['AAAAAAAAAAAAAAAA'], 1, 'EUR', '30.00', 2, self::NOW);

        self::assertSame([['AAAAAAAAAAAAAAAA', '9.99', '0.0050']], $this->summary($applied));
    }

    public function testImWarenkorbNurDerSaldo(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '20.0000');

        $applied = $this->wallet()->apply(['AAAAAAAAAAAAAAAA'], 1, 'EUR', '0', 2, self::NOW);

        self::assertSame('20.0000', $applied[0]->balance);
        self::assertSame('0.00', $applied[0]->applied);
        self::assertSame(VoucherStatus::Usable, $applied[0]->status);
    }

    /**
     * Wer nicht zahlen kann, zahlt nichts — und der Nächste rückt nach.
     */
    public function testNichtVerwendbareZahlenNichts(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '20.0000', blocked: true);
        $this->voucher(2, 'BBBBBBBBBBBBBBBB', '20.0000', currency: 'CHF');
        $this->voucher(3, 'CCCCCCCCCCCCCCCC', '0.0000');
        $this->voucher(4, 'DDDDDDDDDDDDDDDD', '20.0000', shop: 2);
        // Letzter Tag gestern
        $this->voucher(5, 'EEEEEEEEEEEEEEEE', '20.0000', validUntil: (int) strtotime('2027-01-14 00:00'));
        $this->voucher(6, 'FFFFFFFFFFFFFFFF', '20.0000');

        $applied = $this->wallet()->apply(['AAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBB', 'CCCCCCCCCCCCCCCC', 'DDDDDDDDDDDDDDDD', 'EEEEEEEEEEEEEEEE', 'GGGGGGGGGGGGGGGG', 'FFFFFFFFFFFFFFFF'], 1, 'EUR', '10.00', 2, self::NOW);

        self::assertSame(
            [VoucherStatus::Blocked, VoucherStatus::OtherCurrency, VoucherStatus::Spent, VoucherStatus::Unknown, VoucherStatus::Expired, VoucherStatus::Unknown, VoucherStatus::Usable],
            array_map(static fn (AppliedVoucher $v): VoucherStatus => $v->status, $applied),
        );
        self::assertSame(['0.00', '0.00', '0.00', '0.00', '0.00', '0', '10.00'], array_map(static fn (AppliedVoucher $v): string => $v->applied, $applied));
    }

    /**
     * WG10: der letzte Tag gilt noch, ganz — erst um Mitternacht danach ist
     * Schluss. Auch über die Umstellung auf Sommerzeit.
     */
    public function testDerLetzteTagGiltNoch(): void
    {
        $lastDay = (int) strtotime('2027-03-28 00:00');

        self::assertFalse(VoucherWallet::expired($lastDay, (int) strtotime('2027-03-28 23:59:59')));
        self::assertTrue(VoucherWallet::expired($lastDay, (int) strtotime('2027-03-29 00:00:00')));
        self::assertFalse(VoucherWallet::expired(null, self::NOW));
        self::assertFalse(VoucherWallet::expired('', self::NOW));
    }

    public function testFindetNurGutscheineDiesesShops(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '20.0000', shop: 2, shops: serialize(['3']));

        self::assertNull($this->wallet()->findForShop('AAAA-AAAA-AAAA-AAAA', 1));
        self::assertSame(1, (int) $this->wallet()->findForShop('aaaa aaaa aaaa aaaa', 3)['id']);
        self::assertNull($this->wallet()->findForShop('kein Code', 2));
    }

    /**
     * @param list<AppliedVoucher> $applied
     *
     * @return list<array{string, string, string}>
     */
    private function summary(array $applied): array
    {
        return array_map(static fn (AppliedVoucher $v): array => [$v->code, $v->applied, $v->after], $applied);
    }

    private function voucher(int $id, string $code, string $balance, bool $blocked = false, string $currency = 'EUR', int $shop = 1, string|null $shops = null, int|null $validUntil = null): void
    {
        $this->connection->insert('tl_consho_voucher', [
            'id' => $id,
            'code' => $code,
            'shop' => $shop,
            'shops' => $shops,
            'currency' => $currency,
            'faceValue' => '20',
            'balance' => $balance,
            'blocked' => $blocked ? 1 : 0,
            'validUntil' => $validUntil,
        ]);
    }

    private function wallet(): VoucherWallet
    {
        return new VoucherWallet($this->connection);
    }
}
