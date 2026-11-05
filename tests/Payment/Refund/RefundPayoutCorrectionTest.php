<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment\Refund;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\PaymentActions;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\FakeRefundingHandler;

/**
 * Geldflüsse E8: a locked payment booked too high is corrected by a
 * counter-booking, never by a refund.
 */
final class RefundPayoutCorrectionTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_payment', 'tl_consho_payment_attempt', 'tl_consho_provider_event'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'B-1', 'currency' => 'EUR', 'language' => 'de']);
        // 100 typed for a transfer of 10, locked by the invoice.
        $this->connection->insert('tl_consho_payment', ['id' => 101, 'pid' => 1, 'amount' => '100.0000', 'method' => 'Überweisung', 'lockedAt' => self::NOW - 60]);
    }

    public function testEineKorrekturIstEineGesperrteGegenbuchungMitGrund(): void
    {
        $row = $this->payout()->correct(1, 101, '90', '100 statt 10 erfasst', self::NOW);

        self::assertNotNull($row);
        $booked = $this->connection->fetchAssociative('SELECT * FROM tl_consho_payment WHERE id = ?', [$row]) ?: self::fail('no row');
        self::assertAmount('-90', (string) $booked['amount']);
        self::assertSame('correction', $booked['method']);
        self::assertSame('100 statt 10 erfasst', $booked['reference']);
        self::assertSame(101, (int) $booked['refundOf']);
        self::assertSame(self::NOW, (int) $booked['lockedAt']);
        // No money moves: nothing to pay out, nothing a cron would pick up.
        self::assertSame('', $booked['payoutState']);
    }

    public function testHoechstensDerRest(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-5.0000', 'method' => 'refund', 'refundOf' => 101, 'payoutState' => 'manual']);

        self::assertSame('95.0000', $this->payout()->correctable(101));
        self::assertNull($this->payout()->correct(1, 101, '95.01', 'zu viel', self::NOW));
        self::assertNotNull($this->payout()->correct(1, 101, '95', 'Rest', self::NOW));
        self::assertNull($this->payout()->correctable(101), 'nothing left');
    }

    public function testOffeneFremdeErstattungMindertDenRest(): void
    {
        $this->connection->insert('tl_consho_provider_event', ['pid' => 1, 'payment' => 101, 'kind' => 'refund', 'reference' => 're_1', 'amount' => '30.0000', 'currency' => 'EUR', 'state' => 'open']);

        self::assertSame('70.0000', $this->payout()->correctable(101));
    }

    public function testNurGesperrteEigeneZahlungen(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 102, 'pid' => 1, 'amount' => '20.0000', 'method' => 'bar']);
        $this->connection->insert('tl_consho_payment', ['id' => 103, 'pid' => 1, 'amount' => '20.0000', 'method' => 'Gutschein', 'reference' => 'voucher:7', 'lockedAt' => self::NOW]);
        $this->connection->insert('tl_consho_payment', ['id' => 104, 'pid' => 1, 'amount' => '40.0000', 'method' => 'chargebackReversal', 'refundOf' => 101, 'lockedAt' => self::NOW]);
        $this->connection->insert('tl_consho_payment', ['id' => 105, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'lockedAt' => self::NOW]);

        foreach ([102 => 'unlocked: edit it', 103 => 'voucher', 104 => 'reversal', 105 => 'refund', 999 => 'missing'] as $id => $why) {
            self::assertNull($this->payout()->correctable($id), $why);
        }
    }

    /**
     * A refund against a payment not locked yet locks it (LockedRecords) —
     * editing is gone, so correcting must be there, or nothing resolves it.
     */
    public function testEineDurchErstattungGesperrteZahlungIstKorrigierbar(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 102, 'pid' => 1, 'amount' => '100.0000', 'method' => 'Überweisung']);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-30.0000', 'method' => 'refund', 'refundOf' => 102, 'payoutState' => 'manual', 'lockedAt' => self::NOW]);

        self::assertSame('70.0000', $this->payout()->correctable(102));
        self::assertTrue((new PaymentActions($this->payout()))->canCorrect(['id' => 102, 'amount' => '100.0000', 'lockedAt' => null]));
    }

    public function testEineZahlungEinerAnderenBestellungWirdNichtKorrigiert(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 2, 'orderNumber' => 'B-2', 'currency' => 'EUR', 'language' => 'de']);

        self::assertNull($this->payout()->correct(2, 101, '10', 'falsch', self::NOW));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment'));
    }

    public function testOhneGrundKeineKorrektur(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->payout()->correct(1, 101, '10', '  ', self::NOW);
    }

    public function testDieKorrekturMindertDenErstattbarenRestEinerAnbieterzahlung(): void
    {
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 10, 'pid' => 1, 'tokenHash' => 'h10', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment', ['id' => 110, 'pid' => 1, 'amount' => '50.0000', 'attempt' => 10, 'reference' => 'tr_1', 'method' => 'Karte', 'lockedAt' => self::NOW]);

        $this->payout()->correct(1, 110, '20', 'doppelt erfasst', self::NOW);

        self::assertSame([110 => '30.0000'], $this->payout()->refundable(1));
    }

    private function payout(): RefundPayout
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturnCallback(static fn (int $id): array => ['id' => (string) $id]);

        $methods = new class implements PaymentMethodLookupInterface {
            public function byId(int $methodId): PaymentMethod|null
            {
                return 1 === $methodId ? new PaymentMethod(1, 'card', 'Karte', []) : null;
            }
        };

        return new RefundPayout($this->connection, $orders, $methods, new PaymentHandlers(new ServiceLocator([
            'card' => static fn (): PaymentHandlerInterface => new FakeRefundingHandler(),
            'prepayment' => static fn (): PaymentHandlerInterface => new PrepaymentHandler(),
        ])));
    }

    private static function assertAmount(string $expected, string $actual): void
    {
        self::assertTrue(BigDecimal::of($expected)->isEqualTo($actual), \sprintf('%s expected, got %s', $expected, $actual));
    }
}
