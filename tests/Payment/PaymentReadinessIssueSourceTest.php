<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentReadinessInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentReadinessIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * A payment module reports why a published method of its type will not
 * work; the core turns that into its finding — how loud and for whom, it
 * decides.
 */
class PaymentReadinessIssueSourceTest extends TestCase
{
    private Connection $connection;

    /** @var list<array<string, mixed>> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_payment_method');

        $c = $this->connection;
        $c->insert('tl_consho_payment_method', ['id' => 1, 'title' => 'Karte', 'type' => 'ready', 'published' => 1, 'jsonData' => '{"key":"test_1"}']);
        $c->insert('tl_consho_payment_method', ['id' => 2, 'title' => 'PayPal', 'type' => 'ready', 'published' => 1, 'jsonData' => '{"key":"live_2"}']);
        $c->insert('tl_consho_payment_method', ['id' => 3, 'title' => 'Entwurf', 'type' => 'ready', 'published' => 0, 'jsonData' => '{"key":"test_3"}']);
        $c->insert('tl_consho_payment_method', ['id' => 4, 'title' => 'Rechnung', 'type' => 'plain', 'published' => 1]);
        $c->insert('tl_consho_payment_method', ['id' => 5, 'title' => 'Alt', 'type' => 'gone', 'published' => 1]);

        $GLOBALS['TL_LANG']['CONSHO']['paymentReason']['ready'] = ['testKey' => 'Ein Test-Schlüssel nimmt kein Geld an.'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testJedeVeroeffentlichteZahlungsartWirdMitIhrenEinstellungenGefragt(): void
    {
        $issues = $this->source()->issues();

        self::assertSame([['key' => 'test_1'], ['key' => 'live_2']], $this->asked, 'published methods only, each with its own settings');
        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::PaymentMethodNotReady, ['method' => 'Karte', 'reason' => 'Ein Test-Schlüssel nimmt kein Geld an.']),
            new ConfigurationIssue(ConfigurationFinding::PaymentMethodNotReady, ['method' => 'Karte', 'reason' => 'ready: licenceInvalid']),
        ], $issues);
    }

    public function testDerBefundIstEineWarnungInDenZahlungsarten(): void
    {
        self::assertTrue(ConfigurationFinding::PaymentMethodNotReady->isWarning());
        self::assertSame(['consho_payment'], ConfigurationFinding::PaymentMethodNotReady->fixedIn());
    }

    private function source(): PaymentReadinessIssueSource
    {
        $ready = $this->createMock(PaymentReadinessInterface::class);
        $ready->method('methodProblems')->willReturnCallback(function (array $config): array {
            $this->asked[] = $config;

            // The same code twice is said once.
            return 'test_1' === $config['key'] ? ['testKey', 'licenceInvalid', 'testKey'] : [];
        });

        return new PaymentReadinessIssueSource($this->connection, new PaymentHandlers(new ServiceLocator([
            'ready' => static fn (): PaymentHandlerInterface => $ready,
            'plain' => fn (): PaymentHandlerInterface => $this->createMock(PaymentHandlerInterface::class),
        ])));
    }
}
