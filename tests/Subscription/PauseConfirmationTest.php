<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Subscription\PauseConfirmation;
use Webwerkwien\ContaoConshoBundle\Subscription\PauseScheduled;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo pausieren (P110): die Bestätigung an die neueste Bestellung des Abos —
 * deren Anschrift und Sprache, wie bei der Erinnerung.
 */
final class PauseConfirmationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_subscription');

        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'createdAt' => 100]);
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'subscription' => 1, 'createdAt' => 200]);
        $this->connection->insert('tl_consho_order', ['id' => 92, 'orderNumber' => 'WEB-00092', 'subscription' => 2, 'createdAt' => 300]);
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'firstOrder' => 90]);
    }

    public function testAnDieNeuesteBestellungDesAbos(): void
    {
        $mail = $this->createMock(OrderEventMail::class);
        $mail->expects(self::once())->method('subscriptionPaused')->with(91, 'WEB-00090-1', 1000, 2000, 'https://shop.test/b')->willReturn(true);

        self::assertTrue($this->confirmation($mail)->send($this->pause()));
    }

    public function testOhneBestellseiteKeineMailAberEineLogzeile(): void
    {
        $mail = $this->createMock(OrderEventMail::class);
        $mail->expects(self::never())->method('subscriptionPaused');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('no order page'));

        self::assertFalse($this->confirmation($mail, null, $logger)->send($this->pause()));
    }

    private function confirmation(OrderEventMail $mail, string|null $url = 'https://shop.test/b', LoggerInterface|null $logger = null): PauseConfirmation
    {
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn($url);

        return new PauseConfirmation($this->connection, $links, $mail, $logger ?? $this->createStub(LoggerInterface::class));
    }

    private function pause(): PauseScheduled
    {
        return new PauseScheduled(5, 1, 'WEB-00090-1', 2, 1000, 2000);
    }
}
