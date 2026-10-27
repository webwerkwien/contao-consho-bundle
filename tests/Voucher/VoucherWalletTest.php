<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\AppliedVoucher;
use Webwerkwien\ContaoConshoBundle\Voucher\CoverablePot;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherStatus;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;

/**
 * WG-5 (6.5): Vouchers cover in the order they were entered, each up to
 * its balance, until nothing is left open — and only those that can pay right now.
 */
class VoucherWalletTest extends TestCase
{
    // 2027-01-15 11:00 in Vienna
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
     * A balance with four decimal places only pays what the currency knows —
     * never a fraction more than it has.
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
     * Whoever cannot pay pays nothing — and the next one moves up.
     */
    public function testNichtVerwendbareZahlenNichts(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '20.0000', blocked: true);
        $this->voucher(2, 'BBBBBBBBBBBBBBBB', '20.0000', currency: 'CHF');
        $this->voucher(3, 'CCCCCCCCCCCCCCCC', '0.0000');
        $this->voucher(4, 'DDDDDDDDDDDDDDDD', '20.0000', shop: 2);
        // Last day yesterday
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
     * WG10: the last day still counts, in full — it only ends at the midnight
     * after it. Also across the switch to summer time.
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
    /**
     * EZ-1, F2, F3: a single-purpose voucher pays only its pot and pays first,
     * whatever the typed order; the multi-purpose one takes the rest of the
     * total, shipping included. The list keeps the typed order.
     */
    public function testEinzweckZuerstUndNurAusSeinemTopf(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '100.0000');
        $this->voucher(2, 'BBBBBBBBBBBBBBBB', '100.0000');
        $this->connection->update('tl_consho_voucher', ['taxClass' => 3, 'taxRate' => '20.0000', 'taxCountry' => 'AT'], ['id' => 2]);

        // 70 total: 40 of class 3 in AT, the rest goods of another class and shipping.
        $applied = $this->wallet()->apply(['AAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBB'], 1, 'EUR', '70.00', 2, self::NOW, [
            new CoverablePot(3, 'AT', '20.0000', '40.0000'),
            new CoverablePot(1, 'AT', '20.0000', '25.0000'),
        ]);

        self::assertSame([['AAAAAAAAAAAAAAAA', '30.00', '70.0000'], ['BBBBBBBBBBBBBBBB', '40.00', '60.0000']], $this->summary($applied));
        self::assertSame([false, true], array_map(static fn (AppliedVoucher $v): bool => $v->singlePurpose, $applied));
    }

    /**
     * Two single-purpose vouchers share their pot; nothing fitting — another
     * class, another country, a rate changed since the sale (F4) — reads
     * "passt nicht" and pays nothing.
     */
    public function testEinzweckOhnePassendenTopf(): void
    {
        foreach ([1 => ['AAAAAAAAAAAAAAAA', 3, 'AT', '20.0000'], 2 => ['BBBBBBBBBBBBBBBB', 3, 'AT', '20.0000'], 3 => ['CCCCCCCCCCCCCCCC', 4, 'AT', '20.0000'], 4 => ['DDDDDDDDDDDDDDDD', 3, 'DE', '20.0000'], 5 => ['EEEEEEEEEEEEEEEE', 3, 'AT', '10.0000']] as $id => [$code, $class, $country, $rate]) {
            $this->voucher($id, $code, '30.0000');
            $this->connection->update('tl_consho_voucher', ['taxClass' => $class, 'taxRate' => $rate, 'taxCountry' => $country], ['id' => $id]);
        }

        $applied = $this->wallet()->apply(['AAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBB', 'CCCCCCCCCCCCCCCC', 'DDDDDDDDDDDDDDDD', 'EEEEEEEEEEEEEEEE'], 1, 'EUR', '100.00', 2, self::NOW, [
            new CoverablePot(3, 'AT', '20.0000', '40.0000'),
        ]);

        self::assertSame(['30.00', '10.00', '0.00', '0.00', '0.00'], array_map(static fn (AppliedVoucher $v): string => $v->applied, $applied));
        self::assertSame(
            [VoucherStatus::Usable, VoucherStatus::Usable, VoucherStatus::NotApplicable, VoucherStatus::NotApplicable, VoucherStatus::NotApplicable],
            array_map(static fn (AppliedVoucher $v): VoucherStatus => $v->status, $applied),
        );
    }

    /**
     * In the basket nothing is applied yet, but a voucher with no fitting
     * article already says so; an empty pot is no fitting one.
     */
    public function testImWarenkorbSagtErSchonDassErNichtPasst(): void
    {
        $this->voucher(1, 'AAAAAAAAAAAAAAAA', '30.0000');
        $this->connection->update('tl_consho_voucher', ['taxClass' => 3, 'taxRate' => '20.0000', 'taxCountry' => 'AT'], ['id' => 1]);

        self::assertSame(VoucherStatus::Usable, $this->wallet()->apply(['AAAAAAAAAAAAAAAA'], 1, 'EUR', '0', 2, self::NOW, [new CoverablePot(3, 'AT', '20.0000', '40.0000')])[0]->status);
        self::assertSame(VoucherStatus::NotApplicable, $this->wallet()->apply(['AAAAAAAAAAAAAAAA'], 1, 'EUR', '0', 2, self::NOW, [new CoverablePot(3, 'AT', '20.0000', '0.0000')])[0]->status);
        self::assertSame(VoucherStatus::NotApplicable, $this->wallet()->apply(['AAAAAAAAAAAAAAAA'], 1, 'EUR', '0', 2, self::NOW)[0]->status);
    }

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
