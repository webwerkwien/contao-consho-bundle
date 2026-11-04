<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHeldIssueSource;

class PaymentHeldIssueSourceTest extends TestCase
{
    public function testOffeneVersucheAelterAlsSiebenTageMitIhrenBestellungen(): void
    {
        $now = 10_000_000;
        $attempts = new InMemoryPaymentAttempts();

        $cases = [
            1 => [11, AttemptState::Open, $now - 8 * 86400],
            2 => [11, AttemptState::Open, $now - 9 * 86400],
            3 => [12, AttemptState::Open, $now - 7 * 86400 - 1],
            4 => [13, AttemptState::Open, $now - 6 * 86400],
            5 => [14, AttemptState::Succeeded, $now - 30 * 86400],
        ];

        foreach ($cases as $id => [$order, $state, $created]) {
            $attempts->add(new PaymentAttempt($id, $order, 7, 'paypal', $state, '10', 'EUR', 'O-'.$id, '', '', createdAt: $created), 't'.$id);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_consho_order (id INTEGER PRIMARY KEY, orderNumber VARCHAR(64))');
        $connection->insert('tl_consho_order', ['id' => 11, 'orderNumber' => 'WEB-00011']);

        $issues = (new PaymentHeldIssueSource($connection, $attempts, static fn (): int => $now))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::PaymentHeld, $issues[0]->finding);
        self::assertSame(['count' => '2', 'orders' => 'WEB-00011, #12'], $issues[0]->context, 'one order with two attempts counts once; 13 is younger, 14 paid');
        self::assertTrue(ConfigurationFinding::PaymentHeld->isWarning());
    }

    public function testOhneGehalteneZahlungenKeinBefund(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertSame([], (new PaymentHeldIssueSource($connection, new InMemoryPaymentAttempts(), static fn (): int => 10_000_000))->issues());
    }
}
