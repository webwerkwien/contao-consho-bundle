<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment\Aftermath;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\ProviderEventBooker;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEvent;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Spec 9.2, N1–N4: what the provider reports after the payment succeeded is
 * booked once per provider ID.
 */
final class ProviderEventBookerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $log;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_payment', 'tl_consho_provider_event'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->log = new \ArrayObject();
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'B-7', 'currency' => 'EUR', 'grandTotal' => '50.0000']);
        $this->connection->insert('tl_consho_payment', ['id' => 70, 'pid' => 7, 'amount' => '50.0000', 'method' => 'Karte', 'reference' => 'tr_1', 'attempt' => 3]);
    }

    public function testChargebackBooksANegativeRowAgainstThePayment(): void
    {
        $changed = $this->book([ProviderEvent::chargeback('chb_1', '50', 'EUR', 'MD06 Return of funds requested', self::NOW - 60)]);

        self::assertSame(1, $changed);

        $row = $this->connection->fetchAssociative("SELECT * FROM tl_consho_payment WHERE method = 'chargeback'") ?: self::fail('no chargeback row');
        self::assertAmount('-50', (string) $row['amount']);
        self::assertSame(70, (int) $row['refundOf']);
        self::assertSame('chb_1', $row['payoutReference']);
        self::assertSame('MD06 Return of funds requested', $row['reference']);
        self::assertSame(self::NOW - 60, (int) $row['receivedAt']);
        self::assertNull($row['attempt']);
        self::assertSame(self::NOW, (int) $row['lockedAt']);

        $event = $this->connection->fetchAssociative("SELECT * FROM tl_consho_provider_event WHERE reference = 'chb_1'") ?: self::fail('no event');
        self::assertSame('chargeback', $event['kind']);
        self::assertSame('open', $event['state']);
        self::assertSame(70, (int) $event['payment']);
        self::assertSame('MD06 Return of funds requested', $event['reason']);
    }

    public function testTheSameChargebackTwiceBooksOnce(): void
    {
        $event = ProviderEvent::chargeback('chb_1', '50', 'EUR', '', self::NOW);

        $this->book([$event]);
        self::assertSame(0, $this->book([$event]));

        self::assertSame(1, $this->rowsWhere("method = 'chargeback'"));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_provider_event'));
    }

    public function testAReversalBooksAPositiveRowOnce(): void
    {
        $this->book([ProviderEvent::chargeback('chb_1', '50', 'EUR', '', self::NOW - 100)]);

        $reversed = ProviderEvent::chargeback('chb_1', '50', 'EUR', '', self::NOW - 100, self::NOW - 10);
        self::assertSame(1, $this->book([$reversed]));
        self::assertSame(0, $this->book([$reversed]));

        $row = $this->connection->fetchAssociative("SELECT * FROM tl_consho_payment WHERE method = 'chargebackReversal'") ?: self::fail('no reversal row');
        self::assertAmount('50', (string) $row['amount']);
        self::assertSame(70, (int) $row['refundOf']);
        self::assertSame(self::NOW - 10, (int) $row['receivedAt']);
        self::assertSame(self::NOW - 10, (int) $this->connection->fetchOne("SELECT reversedAt FROM tl_consho_provider_event WHERE reference = 'chb_1'"));
        self::assertAmount('50', (string) $this->connection->fetchOne('SELECT SUM(amount) FROM tl_consho_payment WHERE pid = 7'));
    }

    public function testAReversalReportedFirstBooksBothRows(): void
    {
        $this->book([ProviderEvent::chargeback('chb_1', '50', 'EUR', '', self::NOW - 100, self::NOW - 10)]);

        self::assertSame(1, $this->rowsWhere("method = 'chargeback'"));
        self::assertSame(1, $this->rowsWhere("method = 'chargebackReversal'"));
        self::assertAmount('50', (string) $this->connection->fetchOne('SELECT SUM(amount) FROM tl_consho_payment WHERE pid = 7'));
    }

    public function testAnOwnRefundIsSkipped(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => 7, 'amount' => '-20.0000', 'method' => 'refund', 'refundOf' => 70, 'payoutState' => 'done', 'payoutReference' => 're_9']);

        self::assertSame(0, $this->book([ProviderEvent::refund('re_9', '20', 'EUR', 'refunded', self::NOW)]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_provider_event'));
    }

    public function testAForeignRefundBecomesAnOpenEventWithoutARow(): void
    {
        self::assertSame(1, $this->book([ProviderEvent::refund('re_x', '20', 'EUR', 'refunded', self::NOW)]));

        $event = $this->connection->fetchAssociative("SELECT * FROM tl_consho_provider_event WHERE reference = 're_x'") ?: self::fail('no event');
        self::assertSame('refund', $event['kind']);
        self::assertSame('open', $event['state']);
        self::assertAmount('20', (string) $event['amount']);
        self::assertSame(1, $this->rowsWhere('1 = 1'));
    }

    public function testAProcessingForeignRefundIsRecordedToo(): void
    {
        self::assertSame(1, $this->book([ProviderEvent::refund('re_x', '20', 'EUR', 'processing', self::NOW)]));
    }

    public function testAPendingOrFailedForeignRefundIsNotRecorded(): void
    {
        self::assertSame(0, $this->book([
            ProviderEvent::refund('re_p', '20', 'EUR', 'pending', self::NOW),
            ProviderEvent::refund('re_f', '20', 'EUR', 'failed', self::NOW),
        ]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_provider_event'));
    }

    public function testAnotherCurrencyIsSkippedAndLogged(): void
    {
        self::assertSame(0, $this->book([ProviderEvent::chargeback('chb_1', '50', 'USD', '', self::NOW)]));
        self::assertSame(0, $this->rowsWhere("method = 'chargeback'"));
        self::assertStringContainsString('chb_1 in USD skipped', implode("\n", $this->log->getArrayCopy()));
    }

    public function testWithoutThePaymentNothingIsBooked(): void
    {
        self::assertSame(0, $this->book([ProviderEvent::chargeback('chb_1', '50', 'EUR', '', self::NOW)], attempt: 99));
        self::assertSame(0, $this->rowsWhere("method = 'chargeback'"));
        self::assertStringContainsString('has no payment', implode("\n", $this->log->getArrayCopy()));
    }

    /**
     * @param list<ProviderEvent> $events
     */
    private function book(array $events, int $attempt = 3): int
    {
        $orders = $this->createMock(PaymentChangeRepositoryInterface::class);
        $connection = $this->connection;
        $orders->method('lockOrder')->willReturnCallback(static function (int $id) use ($connection): array|null {
            $row = $connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]);

            return false === $row ? null : array_map(strval(...), array_filter($row, static fn ($v): bool => null !== $v));
        });

        $log = $this->log;
        $logger = new class($log) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly \ArrayObject $log)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->log[] = $level.': '.$message;
            }
        };

        return (new ProviderEventBooker($this->connection, $orders, $logger))->book(7, $attempt, $events, self::NOW);
    }

    private function rowsWhere(string $where): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment WHERE pid = 7 AND '.$where);
    }

    private static function assertAmount(string $expected, string $actual): void
    {
        self::assertTrue(BigDecimal::of($expected)->isEqualTo($actual), \sprintf('%s expected, got %s', $expected, $actual));
    }
}
