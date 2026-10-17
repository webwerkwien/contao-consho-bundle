<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PaymentRecordListener;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;

/**
 * Eine im Backend erfasste Zahlung hat Folgen (D-1) — bei „Mehrere
 * bearbeiten" erst, wenn Contaos Transaktion entschieden ist (Review S2).
 */
final class PaymentRecordListenerTest extends TestCase
{
    private Connection $connection;

    /** @var list<int> */
    private array $handled = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testEinzelnBearbeitetFolgtEsSofort(): void
    {
        $listener = $this->listener();

        $listener->onSubmit($this->dc(7));

        self::assertSame([7], $this->handled);
    }

    public function testInContaosTransaktionErstAmEndeDesRequests(): void
    {
        $listener = $this->listener();

        $this->connection->beginTransaction();
        $listener->onSubmit($this->dc(7));
        $listener->onSubmit($this->dc(7));
        $listener->onSubmit($this->dc(8));

        self::assertSame([], $this->handled, 'nicht innerhalb der Transaktion');

        $this->connection->rollBack();
        $listener->onTerminate();
        $listener->onTerminate();

        self::assertSame([7, 8], $this->handled, 'je Bestellung einmal');
    }

    /**
     * Review 2, S-b: Nach fastcgi_finish_request() sieht niemand eine
     * Exception — sie wird protokolliert, die nächste Bestellung läuft.
     */
    public function testEinFehlerAmEndeStopptDieUebrigenNicht(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('order 7 failed: kaputt'));

        $listener = $this->listener($logger, failFor: 7);

        $this->connection->beginTransaction();
        $listener->onSubmit($this->dc(7));
        $listener->onSubmit($this->dc(8));
        $this->connection->commit();
        $listener->onTerminate();

        self::assertSame([7, 8], $this->handled);
    }

    private function listener(LoggerInterface|null $logger = null, int $failFor = 0): PaymentRecordListener
    {
        $recorded = $this->createStub(PaymentRecorded::class);
        $recorded->method('handle')->willReturnCallback(function (int $orderId) use ($failFor): void {
            $this->handled[] = $orderId;

            if ($orderId === $failFor) {
                throw new \RuntimeException('kaputt');
            }
        });

        return new PaymentRecordListener(static fn (): PaymentRecorded => $recorded, $this->connection, $logger ?? $this->createStub(LoggerInterface::class));
    }

    /**
     * Nachlese Stufe 2: Der Terminate-Listener entsteht bei jeder Anfrage und
     * jedem Konsolenbefehl. PaymentRecorded (Belege, Mail, SecretCipher) wird
     * erst gebaut, wenn wirklich eine Zahlung erfasst wurde.
     */
    public function testOhneZahlungWirdPaymentRecordedNieGebaut(): void
    {
        $listener = new PaymentRecordListener(
            static fn (): PaymentRecorded => throw new \LogicException('gebaut'),
            $this->connection,
            $this->createStub(LoggerInterface::class),
        );

        $listener->onTerminate();
        $this->addToAssertionCount(1);
    }

    private function dc(int $orderId): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 1, 'pid' => $orderId]);

        return $dc;
    }
}
