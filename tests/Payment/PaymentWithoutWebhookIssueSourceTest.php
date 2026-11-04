<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentWithoutWebhookIssueSource;

class PaymentWithoutWebhookIssueSourceTest extends TestCase
{
    public function testJeZahlungsartMitNachgefragtenZahlungenDerLetztenSiebenTage(): void
    {
        $now = 1_000_000;
        $attempts = new InMemoryPaymentAttempts();

        foreach ([1 => 7, 2 => 7, 3 => 8, 4 => 9] as $id => $method) {
            $attempts->add(new PaymentAttempt($id, $id, $method, 'paypal', AttemptState::Succeeded, '10', 'EUR', 'O-'.$id, '', ''), 't'.$id);
        }

        $attempts->markPolled(1, $now - 60);
        $attempts->markPolled(2, $now - 86400);
        $attempts->markPolled(3, $now - 8 * 86400);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_consho_payment_method (id INTEGER PRIMARY KEY, title VARCHAR(255))');
        $connection->insert('tl_consho_payment_method', ['id' => 7, 'title' => 'PayPal']);

        $issues = (new PaymentWithoutWebhookIssueSource($connection, $attempts, static fn (): int => $now))->issues();

        self::assertCount(1, $issues, 'Zahlungsart 8 nur vor mehr als sieben Tagen, 9 nie');
        self::assertSame(ConfigurationFinding::PaymentWithoutWebhook, $issues[0]->finding);
        self::assertSame(['method' => 'PayPal', 'count' => '2'], $issues[0]->context);
    }

    public function testOhneNachgefragteZahlungenKeinBefund(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertSame([], (new PaymentWithoutWebhookIssueSource($connection, new InMemoryPaymentAttempts()))->issues());
    }
}
