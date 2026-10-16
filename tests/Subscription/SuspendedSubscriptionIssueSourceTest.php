<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Subscription\SuspendedSubscriptionIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-5 (P38): „Ruhende Abos" als Hinweis, je Shop.
 */
final class SuspendedSubscriptionIssueSourceTest extends TestCase
{
    public function testRuhendeAbosJeShop(): void
    {
        $c = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($c, 'tl_consho_subscription');
        DcaSchema::create($c, 'tl_consho_shop');
        $c->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $c->insert('tl_consho_shop', ['id' => 2, 'title' => 'Shop DE']);

        foreach ([[1, 'WEB-00085-1', 'suspended'], [1, 'WEB-00084-1', 'suspended'], [1, 'WEB-00086-1', 'active'], [2, 'WEB-00090-1', 'ended']] as [$shop, $number, $state]) {
            $c->insert('tl_consho_subscription', ['shop' => $shop, 'subscriptionNumber' => $number, 'state' => $state]);
        }

        $issues = (new SuspendedSubscriptionIssueSource($c))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::SubscriptionsSuspended, $issues[0]->finding);
        self::assertSame(['shop' => 'Testshop', 'count' => '2', 'numbers' => 'WEB-00084-1, WEB-00085-1'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
        self::assertFalse($issues[0]->finding->isWarning());
    }
}
