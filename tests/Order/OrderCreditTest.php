<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\OrderCredit;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * What lowers a claim (RK-4): besides accepted withdrawals, a complaint's
 * decided rescission (with quantities) and price reduction (amounts only).
 *
 * Order: 2 × hiking pole (60.00), shipping 5.00.
 */
final class OrderCreditTest extends TestCase
{
    private const ORDER = 7;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order_item');

        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Hiking pole', 'quantity' => '2.0000', 'unitPrice' => '25.0000', 'taxRate' => '20.0000', 'taxAmount' => '10.0000', 'lineTotal' => '60.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'productName' => 'Parcel', 'quantity' => '1.0000', 'unitPrice' => '4.1667', 'taxRate' => '20.0000', 'taxAmount' => '0.8333', 'lineTotal' => '5.0000', 'lineType' => 'shipping']);
    }

    public function testOhneEntscheidungZaehltDieReklamationNicht(): void
    {
        $this->complaint('rescission', null, ['rescindedQuantity' => '0']);

        self::assertSame(['0', []], $this->credit());
    }

    public function testFestgelegterRuecktrittZaehltMitMengeUndGutschrift(): void
    {
        $this->complaint('rescission', 100, ['rescindedQuantity' => '1']);

        [$credited, $withdrawn] = $this->credit();

        self::assertTrue(BigDecimal::of('30')->isEqualTo($credited));
        self::assertTrue(BigDecimal::of('1')->isEqualTo($withdrawn[1]));
    }

    public function testFestgelegteMinderungZaehltNurMitBetrag(): void
    {
        $this->complaint('reduction', 100, ['reduction' => '7.5000']);

        [$credited, $withdrawn] = $this->credit();

        self::assertTrue(BigDecimal::of('7.5')->isEqualTo($credited));
        // The goods stay with the customer.
        self::assertSame([], $withdrawn);
    }

    public function testErstattetZaehltDerGebuchteBetrag(): void
    {
        $this->complaint('rescission', 100, ['rescindedQuantity' => '1'], ['refundedAt' => 200, 'refundAmount' => '35.0000']);

        [$credited] = $this->credit();

        self::assertTrue(BigDecimal::of('35')->isEqualTo($credited));
    }

    public function testAbgelehnteReklamationZaehltNicht(): void
    {
        $this->complaint('reduction', 100, ['reduction' => '7.5000'], ['state' => 'rejected']);

        self::assertSame(['0', []], $this->credit());
    }

    public function testWiderrufUndRuecktrittZaehlenZusammen(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => self::ORDER, 'number' => 'W1', 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);
        $this->complaint('rescission', 100, ['rescindedQuantity' => '1']);

        [$credited, $withdrawn] = $this->credit();

        self::assertTrue(BigDecimal::of('60')->isEqualTo($credited));
        self::assertTrue(BigDecimal::of('2')->isEqualTo($withdrawn[1]));
    }

    /**
     * L3: a withdrawal accepted after a price reduction takes its share of it
     * along — the claim does not drop twice for the same money.
     */
    public function testEinSpaetererWiderrufNimmtDieMinderungAnteiligMit(): void
    {
        $this->complaint('reduction', 100, ['reduction' => '10.0000']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => self::ORDER, 'number' => 'W1', 'state' => 'accepted', 'decidedAt' => 200]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);

        [$credited] = $this->credit();

        self::assertTrue(BigDecimal::of('35')->isEqualTo($credited), $credited);
    }

    public function testEinFruehererWiderrufBehaeltSeineGutschrift(): void
    {
        $this->complaint('reduction', 100, ['reduction' => '10.0000']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => self::ORDER, 'number' => 'W1', 'state' => 'accepted', 'decidedAt' => 50]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);

        [$credited] = $this->credit();

        self::assertTrue(BigDecimal::of('40')->isEqualTo($credited), $credited);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $more
     */
    private function complaint(string $remedy, int|null $decidedAt, array $item, array $more = []): void
    {
        $this->connection->insert('tl_consho_complaint', [
            'id' => 1,
            'pid' => self::ORDER,
            'number' => 'WEB-00007-R1',
            'remedy' => $remedy,
            'decidedAt' => $decidedAt,
            'state' => null === $decidedAt ? 'open' : 'inProgress',
            ...$more,
        ]);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1', ...$item]);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function credit(): array
    {
        return (new OrderCredit($this->connection))->of(self::ORDER);
    }
}
