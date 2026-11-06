<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order\Refund;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Order\Refund\CreditLines;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * The lines an order gives back by quantity, without a withdrawal around
 * them: the caller names the quantities.
 *
 * Order: 2 × hiking pole (60.00), 1 × backpack (35.00), two parcels
 * (3.00 and 2.00), 20 % each.
 */
final class CreditLinesTest extends TestCase
{
    private const ORDER = 7;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order_item');

        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Hiking pole', 'quantity' => '2.0000', 'unitPrice' => '25.0000', 'taxRate' => '20.0000', 'taxAmount' => '10.0000', 'lineTotal' => '60.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'productName' => 'Backpack', 'quantity' => '1.0000', 'unitPrice' => '29.1667', 'taxRate' => '20.0000', 'taxAmount' => '5.8333', 'lineTotal' => '35.0000', 'kind' => ProductKind::Service->value]);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => self::ORDER, 'productName' => 'Parcel', 'quantity' => '1.0000', 'unitPrice' => '2.5000', 'taxRate' => '20.0000', 'taxAmount' => '0.5000', 'lineTotal' => '3.0000', 'lineType' => 'shipping']);
        $this->connection->insert('tl_consho_order_item', ['id' => 4, 'pid' => self::ORDER, 'productName' => 'Parcel', 'quantity' => '1.0000', 'unitPrice' => '1.6667', 'taxRate' => '20.0000', 'taxAmount' => '0.3333', 'lineTotal' => '2.0000', 'lineType' => 'shipping']);
    }

    public function testEineMengeWirdAnteiligGutgeschriebenNegativMitPositiverMenge(): void
    {
        $lines = $this->creditLines()->lines(self::ORDER, [1 => '1'], BigDecimal::zero());

        self::assertCount(1, $lines);
        self::assertSame('Hiking pole', $lines[0]['productName']);
        self::assertSame('1', $lines[0]['quantity']);
        self::assertSame('-30.0000', $lines[0]['lineTotal']);
        self::assertSame('-5.0000', $lines[0]['taxAmount']);
        self::assertTrue(BigDecimal::of('-30')->isEqualTo($this->creditLines()->total($lines, 'EUR')));
    }

    public function testVersandWirdNachAnteilAufDieVersandzeilenVerteilt(): void
    {
        $lines = $this->creditLines()->lines(self::ORDER, [], BigDecimal::of('2.50'));

        self::assertCount(2, $lines);
        self::assertSame('-1.5000', $lines[0]['lineTotal']);
        // The last shipping line takes the rest, so nothing is lost to rounding.
        self::assertSame('-1.0000', $lines[1]['lineTotal']);
    }

    public function testMengeNullUndUnbekannteZeilenFallenWeg(): void
    {
        self::assertSame([], $this->creditLines()->lines(self::ORDER, [1 => '0', 99 => '1'], BigDecimal::zero()));
        self::assertSame([], $this->creditLines()->credits(self::ORDER, [1 => '0', 99 => '1']));
    }

    public function testGutschriftJeZeileNachArt(): void
    {
        $credits = $this->creditLines()->credits(self::ORDER, [1 => '1', 2 => '1'], ProductKind::Service);

        self::assertSame([2], array_keys($credits));
        self::assertTrue(BigDecimal::of('35')->isEqualTo($credits[2]));
    }

    public function testVersandOffenAbzueglichErstatteterWiderrufe(): void
    {
        self::assertTrue(BigDecimal::of('5')->isEqualTo($this->creditLines()->shippingLeft(self::ORDER)));

        $this->connection->insert('tl_consho_withdrawal', ['pid' => self::ORDER, 'number' => 'W1', 'state' => 'accepted', 'refundedAt' => 1, 'refundShipping' => '3.0000']);

        self::assertTrue(BigDecimal::of('2')->isEqualTo($this->creditLines()->shippingLeft(self::ORDER)));

        // RK-4 (RK-E2): a complaint's refund gives back delivery costs too.
        $this->connection->insert('tl_consho_complaint', ['pid' => self::ORDER, 'number' => 'R1', 'refundedAt' => 2, 'refundShipping' => '1.5000']);

        self::assertTrue(BigDecimal::of('0.5')->isEqualTo($this->creditLines()->shippingLeft(self::ORDER)));
    }

    /**
     * RK-4, L3: what a line is still worth caps a price reduction.
     */
    public function testWertEinerPositionNachRueckgabeUndMinderung(): void
    {
        self::assertTrue(BigDecimal::of('60')->isEqualTo($this->creditLines()->worth(self::ORDER, 1)));
        // Shipping is no goods line.
        self::assertTrue($this->creditLines()->worth(self::ORDER, 3)->isZero());

        $this->connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => self::ORDER, 'number' => 'R1', 'remedy' => 'reduction', 'decidedAt' => 100, 'state' => 'inProgress']);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '2', 'reduction' => '10.0000']);

        self::assertTrue(BigDecimal::of('50')->isEqualTo($this->creditLines()->worth(self::ORDER, 1)));
        self::assertTrue(BigDecimal::of('60')->isEqualTo($this->creditLines()->worth(self::ORDER, 1, 1)), 'neu festgelegt: die eigene Minderung zählt nicht');

        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => self::ORDER, 'number' => 'W1', 'state' => 'accepted', 'decidedAt' => 200]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);

        self::assertTrue(BigDecimal::of('25')->isEqualTo($this->creditLines()->worth(self::ORDER, 1)));
    }

    /**
     * A complaint decided as a rescission and then decided anew as a
     * reduction: its own rescission does not lower what the line is worth.
     */
    public function testNeuFestgelegtZaehltDerEigeneRuecktrittNicht(): void
    {
        $this->connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => self::ORDER, 'number' => 'R1', 'remedy' => 'rescission', 'decidedAt' => 100, 'state' => 'inProgress']);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '2', 'rescindedQuantity' => '1']);

        self::assertTrue(BigDecimal::of('30')->isEqualTo($this->creditLines()->worth(self::ORDER, 1)));
        self::assertTrue(BigDecimal::of('60')->isEqualTo($this->creditLines()->worth(self::ORDER, 1, 1)));
    }

    public function testMinderungszeileMitMengeEinsSatzUndLand(): void
    {
        $this->connection->update('tl_consho_order_item', ['taxCountry' => 'AT'], ['id' => 1]);

        $lines = $this->creditLines()->reductionLines(self::ORDER, [1 => '10', 2 => '0', 99 => '5']);

        self::assertCount(1, $lines);
        self::assertSame(['Hiking pole', '1', '-8.3333', '-1.6667', '-10.0000', 'AT'], [$lines[0]['productName'], $lines[0]['quantity'], $lines[0]['unitPrice'], $lines[0]['taxAmount'], $lines[0]['lineTotal'], $lines[0]['taxCountry']]);
        self::assertTrue(BigDecimal::of('20')->isEqualTo($lines[0]['taxRate']));
    }

    public function testEineSpaetereRueckgabeNimmtDieMinderungAnteiligMit(): void
    {
        $this->connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => self::ORDER, 'number' => 'R1', 'remedy' => 'reduction', 'decidedAt' => 100, 'state' => 'inProgress']);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '2', 'reduction' => '12.0000']);

        $lines = $this->creditLines()->lines(self::ORDER, [1 => '1'], BigDecimal::zero(), null, 200);

        self::assertSame(['-24.0000', '-4.0000', '-20.0000'], [$lines[0]['lineTotal'], $lines[0]['taxAmount'], $lines[0]['unitPrice']]);
        self::assertTrue(BigDecimal::of('24')->isEqualTo($this->creditLines()->credits(self::ORDER, [1 => '1'], null, 200)[1]));

        // Before the reduction, or without a time: the full share.
        self::assertSame('-30.0000', $this->creditLines()->lines(self::ORDER, [1 => '1'], BigDecimal::zero(), null, 100)[0]['lineTotal']);
        self::assertSame('-30.0000', $this->creditLines()->lines(self::ORDER, [1 => '1'], BigDecimal::zero())[0]['lineTotal']);
    }

    private function creditLines(): CreditLines
    {
        return new CreditLines($this->connection, new DocumentPayloadBuilder());
    }
}
