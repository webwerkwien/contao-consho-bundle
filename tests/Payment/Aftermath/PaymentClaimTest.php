<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment\Aftermath;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\PaymentClaim;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Spec 9, P3: "Zahlung einfordern" after a chargeback.
 */
final class PaymentClaimTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    private string $paid = '0';

    private bool $cancelled = false;

    private string|null $orderPage = 'https://shop.test/bestellung?consho_order=abc';

    private bool $mailSent = true;

    /** @var list<array{int, string, int, string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_provider_event');

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '30', 'currency' => 'EUR', 'paymentDueAt' => 100]);
        $this->connection->insert('tl_consho_provider_event', ['id' => 1, 'pid' => 1, 'kind' => 'chargeback', 'reference' => 'chb_1', 'amount' => '30', 'state' => 'open']);
    }

    public function testSetsTheTermAndSendsTheRequest(): void
    {
        self::assertSame('30', $this->claim()->outstanding(1));
        self::assertNull($this->claim()->claim(1, '2027-01-20', self::NOW, 'k.muster'));

        $dueAt = (new \DateTimeImmutable('2027-01-20 23:59:59'))->getTimestamp();
        self::assertSame($dueAt, (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order'));
        self::assertSame([[1, '30', $dueAt, (string) $this->orderPage]], $this->mails);
    }

    public function testNothingToClaimWithoutAStandingChargeback(): void
    {
        $this->connection->update('tl_consho_provider_event', ['reversedAt' => 5], ['id' => 1]);
        self::assertNull($this->claim()->outstanding(1));

        $this->connection->update('tl_consho_provider_event', ['reversedAt' => 0, 'state' => 'settled'], ['id' => 1]);
        self::assertSame('notClaimable', $this->claim()->claim(1, '2027-01-20', self::NOW));
        self::assertSame(100, (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order'));
        self::assertSame([], $this->mails);
    }

    public function testNothingToClaimOnceThePaymentIsBack(): void
    {
        $this->paid = '30';

        self::assertNull($this->claim()->outstanding(1));
        self::assertSame('notClaimable', $this->claim()->claim(1, '2027-01-20', self::NOW));
    }

    public function testNothingToClaimFromACancelledOrder(): void
    {
        $this->cancelled = true;

        self::assertNull($this->claim()->outstanding(1));
    }

    public function testTheDayMustBeValidAndAhead(): void
    {
        self::assertSame('invalidDate', $this->claim()->claim(1, '2027-02-30', self::NOW));
        self::assertSame('notFuture', $this->claim()->claim(1, date('Y-m-d', self::NOW - 86400), self::NOW));
        self::assertSame([], $this->mails);
    }

    public function testAMailThatFailedLeavesTheTermSet(): void
    {
        $this->mailSent = false;

        self::assertSame('mailNotSent', $this->claim()->claim(1, '2027-01-20', self::NOW));
        self::assertGreaterThan(100, (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order'));
    }

    public function testWithoutAnOrderPageNoMail(): void
    {
        $this->orderPage = null;

        self::assertSame('mailNotSent', $this->claim()->claim(1, '2027-01-20', self::NOW));
        self::assertSame([], $this->mails);
    }

    public function testTheDefaultDayIsTwoWeeksAhead(): void
    {
        self::assertSame(date('Y-m-d', self::NOW + 14 * 86400), PaymentClaim::defaultDay(self::NOW));
    }

    private function claim(): PaymentClaim
    {
        $balances = $this->createStub(OrderBalanceReaderInterface::class);
        $balances->method('of')->willReturnCallback(fn (): OrderBalance => new OrderBalance(
            $this->cancelled ? '0' : '30',
            $this->paid,
            PaymentState::Open,
            DeliveryState::Open,
            [],
            false,
            $this->cancelled,
        ));

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturnCallback(fn (): string|null => $this->orderPage);

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('paymentClaim')->willReturnCallback(function (int $orderId, string $outstanding, int $dueAt, string $viewUrl): bool {
            if ($this->mailSent) {
                $this->mails[] = [$orderId, $outstanding, $dueAt, $viewUrl];
            }

            return $this->mailSent;
        });

        return new PaymentClaim($this->connection, $balances, $links, $mail);
    }
}
