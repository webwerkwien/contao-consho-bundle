<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherBalanceTooLow;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherBookingType;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherCode;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;

/**
 * WG9: A voucher's balance only changes through a movement — and is
 * always the sum of its movements.
 */
class VoucherLedgerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        SqliteWithoutRowLocks::$locked = [];
        DcaSchema::create($this->connection, 'tl_consho_voucher');
        DcaSchema::create($this->connection, 'tl_consho_voucher_booking');
    }

    public function testDieAusgabeIstDieErsteBewegung(): void
    {
        $id = $this->ledger()->issue(3, 'eur', '50', '45', null, 'purchase', self::NOW, 77, 12);

        $voucher = $this->voucher($id);
        self::assertSame(VoucherCode::LENGTH, \strlen((string) $voucher['code']));
        self::assertSame($voucher['code'], VoucherCode::normalise((string) $voucher['code']), 'nur Zeichen des Alphabets');
        self::assertSame(['EUR', '50.0000', '45.0000', '50.0000', 'purchase', 77, 12, null], [
            $voucher['currency'], $this->decimal($voucher['faceValue']), $this->decimal($voucher['paidAmount']), $this->decimal($voucher['balance']),
            $voucher['source'], (int) $voucher['orderId'], (int) $voucher['orderItem'], $voucher['validUntil'],
        ]);

        self::assertSame([['issue', '50.0000', '50.0000', 77]], $this->bookings($id));
    }

    public function testEinloesenUndZurueckbuchen(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->issue(3, 'EUR', '50', '50', null, 'purchase', self::NOW);

        self::assertSame('20.0000', $ledger->book($id, '-30', VoucherBookingType::Redeem, self::NOW + 1, 80, 5));
        self::assertSame('30.0000', $ledger->book($id, '10', VoucherBookingType::Refund, self::NOW + 2, 80));

        self::assertSame('30.0000', $this->decimal($this->voucher($id)['balance']));
        self::assertSame([['issue', '50.0000', '50.0000', 0], ['redeem', '-30.0000', '20.0000', 80], ['refund', '10.0000', '30.0000', 80]], $this->bookings($id));
        self::assertSame($this->decimal($this->voucher($id)['balance']), $this->sumOfBookings($id), 'Saldo = Summe der Bewegungen');
    }

    /**
     * Whoever comes last finds less — and gets no more than there is.
     * Nothing is booked.
     */
    public function testUnterNullWirdNichtGebucht(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->issue(3, 'EUR', '20', '20', null, 'purchase', self::NOW);

        try {
            $ledger->book($id, '-20.01', VoucherBookingType::Redeem, self::NOW + 1, 80);
            self::fail('Der Saldo wurde negativ.');
        } catch (VoucherBalanceTooLow) {
        }

        self::assertSame('20.0000', $this->decimal($this->voucher($id)['balance']));
        self::assertCount(1, $this->bookings($id));

        // Exactly down to zero works.
        self::assertSame('0.0000', $ledger->book($id, '-20', VoucherBookingType::Redeem, self::NOW + 2, 81));
    }

    /**
     * The balance is read under a lock — otherwise the second buyer would see
     * the state before the first.
     */
    public function testDerSaldoWirdUnterSperreGelesen(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->issue(3, 'EUR', '20', '20', null, 'purchase', self::NOW);
        SqliteWithoutRowLocks::$locked = [];

        $ledger->book($id, '-5', VoucherBookingType::Redeem, self::NOW + 1);

        // Read through a method: right after the reset, static analysis would
        // take the property for the empty array it was just given.
        $locked = $this->lockedStatements();
        self::assertCount(1, $locked);
        self::assertStringContainsString('tl_consho_voucher', $locked[0]);
    }

    public function testEinGutscheinOhneWertEntstehtNicht(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ledger()->issue(3, 'EUR', '0', '0', null, 'manual', self::NOW);
    }

    /**
     * WG5, refined: codes are typed however they come — lower case, with
     * hyphens, with spaces.
     */
    public function testDerCodeWirdSoGefundenWieErGetipptWird(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->issue(3, 'EUR', '20', '20', null, 'purchase', self::NOW);
        $code = (string) $this->voucher($id)['code'];

        self::assertSame($id, (int) ($ledger->findByCode(strtolower(VoucherCode::format($code)))['id'] ?? 0));
        self::assertSame($id, (int) ($ledger->findByCode(' '.implode(' ', str_split($code, 4)).' ')['id'] ?? 0));
        self::assertNull($ledger->findByCode(substr($code, 0, 15)));
        self::assertNull($ledger->findByCode(''));
    }

    public function testZweiGutscheineHabenVerschiedeneCodes(): void
    {
        $ledger = $this->ledger();
        $codes = [];

        for ($i = 0; $i < 20; ++$i) {
            $codes[] = $this->voucher($ledger->issue(3, 'EUR', '5', '5', null, 'purchase', self::NOW))['code'];
        }

        self::assertCount(20, array_unique($codes));
    }

    /**
     * @return list<string>
     */
    private function lockedStatements(): array
    {
        return SqliteWithoutRowLocks::$locked;
    }

    private function ledger(): VoucherLedger
    {
        return new VoucherLedger($this->connection);
    }

    /**
     * @return array<string, mixed>
     */
    private function voucher(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_voucher WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function bookings(int $id): array
    {
        return array_map(
            fn (array $row): array => [(string) $row['type'], $this->decimal($row['amount']), $this->decimal($row['balanceAfter']), (int) $row['orderId']],
            $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_voucher_booking WHERE pid = ? ORDER BY id', [$id]),
        );
    }

    private function sumOfBookings(int $id): string
    {
        $sum = BigDecimal::zero();

        foreach ($this->connection->fetchFirstColumn('SELECT amount FROM tl_consho_voucher_booking WHERE pid = ?', [$id]) as $amount) {
            $sum = $sum->plus((string) $amount);
        }

        return (string) $sum->toScale(4);
    }

    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(4);
    }
}
