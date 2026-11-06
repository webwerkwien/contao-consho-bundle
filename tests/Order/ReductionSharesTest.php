<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\ReductionShares;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * RK-4, L3: a price reduction is spread over the units the line had left
 * when it was decided; a unit going back later takes its share along.
 *
 * Order: 4 × hiking pole (120.00).
 */
final class ReductionSharesTest extends TestCase
{
    private const ORDER = 7;

    private Connection $connection;

    private int $complaints = 0;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order_item');

        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Hiking pole', 'quantity' => '4.0000', 'unitPrice' => '25.0000', 'taxRate' => '20.0000', 'taxAmount' => '20.0000', 'lineTotal' => '120.0000']);
    }

    public function testEineSpaetereRueckgabeNimmtIhrenAnteilMit(): void
    {
        $this->reduction('20', at: 100);

        self::assertTrue(BigDecimal::of('5')->isEqualTo($this->shares()->taken(self::ORDER, 1, '1', 200)));

        $this->withdrawal('1', at: 200);

        self::assertTrue(BigDecimal::of('15')->isEqualTo($this->shares()->carried(self::ORDER, 1)));
    }

    public function testEineFruehereRueckgabeNimmtNichtsMitUndVerteiltNichtMit(): void
    {
        $this->withdrawal('2', at: 50);
        $this->reduction('20', at: 100);

        // Spread over the two units left, not the four ordered.
        self::assertTrue(BigDecimal::of('10')->isEqualTo($this->shares()->taken(self::ORDER, 1, '1', 200)));
        self::assertTrue(BigDecimal::of('20')->isEqualTo($this->shares()->carried(self::ORDER, 1)));
        self::assertTrue(BigDecimal::of('2')->isEqualTo($this->shares()->returned(self::ORDER, 1)));
    }

    public function testInDerselbenSekundeGiltDieRueckgabeAlsFrueher(): void
    {
        $this->reduction('20', at: 100);

        self::assertTrue($this->shares()->taken(self::ORDER, 1, '1', 100)->isZero());
    }

    public function testEinRuecktrittZaehltWieEinWiderruf(): void
    {
        $this->reduction('20', at: 100);
        $this->rescission('2', at: 200);

        self::assertTrue(BigDecimal::of('10')->isEqualTo($this->shares()->carried(self::ORDER, 1)));
        self::assertTrue(BigDecimal::of('2')->isEqualTo($this->shares()->returned(self::ORDER, 1)));
    }

    public function testDieNeuFestzulegendeMinderungZaehltNicht(): void
    {
        $id = $this->reduction('20', at: 100);
        $this->reduction('8', at: 150);

        self::assertTrue(BigDecimal::of('28')->isEqualTo($this->shares()->carried(self::ORDER, 1)));
        self::assertTrue(BigDecimal::of('8')->isEqualTo($this->shares()->carried(self::ORDER, 1, $id)));
    }

    public function testAbgelehntUndUnentschiedenZaehltNicht(): void
    {
        $this->reduction('20', at: 100, state: 'rejected');
        $this->reduction('20', at: null);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 9, 'pid' => self::ORDER, 'number' => 'W9', 'state' => 'rejected', 'decidedAt' => 50]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 9, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);

        self::assertTrue($this->shares()->carried(self::ORDER, 1)->isZero());
        self::assertTrue($this->shares()->returned(self::ORDER, 1)->isZero());
    }

    private function reduction(string $amount, int|null $at, string $state = 'inProgress'): int
    {
        $id = ++$this->complaints;
        $this->connection->insert('tl_consho_complaint', ['id' => $id, 'pid' => self::ORDER, 'number' => 'R'.$id, 'remedy' => 'reduction', 'decidedAt' => $at, 'state' => null === $at ? 'open' : $state]);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => $id, 'orderItem' => 1, 'quantity' => '1', 'reduction' => null === $at ? null : $amount]);

        return $id;
    }

    private function rescission(string $quantity, int $at): void
    {
        $id = ++$this->complaints;
        $this->connection->insert('tl_consho_complaint', ['id' => $id, 'pid' => self::ORDER, 'number' => 'R'.$id, 'remedy' => 'rescission', 'decidedAt' => $at, 'state' => 'inProgress']);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => $id, 'orderItem' => 1, 'quantity' => $quantity, 'rescindedQuantity' => $quantity]);
    }

    private function withdrawal(string $quantity, int $at): void
    {
        $id = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM tl_consho_withdrawal');
        $this->connection->insert('tl_consho_withdrawal', ['id' => $id, 'pid' => self::ORDER, 'number' => 'W'.$id, 'state' => 'accepted', 'decidedAt' => $at]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => $id, 'orderItem' => 1, 'quantity' => $quantity, 'acceptedQuantity' => $quantity]);
    }

    private function shares(): ReductionShares
    {
        return new ReductionShares($this->connection);
    }
}
