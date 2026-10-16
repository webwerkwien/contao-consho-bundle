<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentMethodRepository;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Subscription\MandateMethodIssueSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingMethod;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\FakeRecurringHandler;

/**
 * Abo-4b, P99: laufende Abos, deren Mandat über eine Zahlungsart abbucht, die
 * der Shop nicht mehr anbietet — dieselbe Frage wie beim Abrechnen.
 */
final class MandateMethodIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_shop', 'tl_consho_payment_method'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $c = $this->connection;
        $c->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop', 'paymentMethods' => serialize(['6', '8', '9', '10'])]);
        $c->insert('tl_consho_payment_method', ['id' => 6, 'type' => 'invoice', 'title' => 'Auf Rechnung', 'published' => 1]);
        $c->insert('tl_consho_payment_method', ['id' => 8, 'type' => FakeRecurringHandler::TYPE, 'title' => 'Karte', 'published' => 1]);
        $c->insert('tl_consho_payment_method', ['id' => 9, 'type' => FakeRecurringHandler::TYPE, 'title' => 'Alte Karte', 'published' => 0]);
        // Ein Modul, das nicht abbuchen kann.
        $c->insert('tl_consho_payment_method', ['id' => 10, 'type' => 'invoice', 'title' => 'Rechnung 2', 'published' => 1]);
        // Veröffentlicht, aber dem Shop nicht zugeordnet.
        $c->insert('tl_consho_payment_method', ['id' => 11, 'type' => FakeRecurringHandler::TYPE, 'title' => 'Fremde Karte', 'published' => 1]);
    }

    public function testNurLaufendeAboMitNichtMehrAbbuchenderZahlungsart(): void
    {
        $this->subscription(1, 8);                  // bucht ab
        $this->subscription(2, 9);                  // unveröffentlicht → Befund
        $this->subscription(3, 10);                 // kann nicht abbuchen → Befund
        $this->subscription(4, 11, 'suspended');    // nicht im Shop, ruhend → Befund
        $this->subscription(5, 12);                 // gelöscht → Befund
        $this->subscription(6, 9, 'ended');         // beendet → nichts
        $this->subscription(7, 9, 'active', '');    // ohne Mandat → nichts
        $this->subscription(8, 9, 'paused');        // pausiert → Befund (P106)

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::MandateMethodUnavailable, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'count' => '5', 'numbers' => 'WEB-2-1, WEB-3-1, WEB-4-1, WEB-5-1, WEB-8-1'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    public function testWiederVeroeffentlichtVerschwindetDerBefund(): void
    {
        $this->subscription(2, 9);
        self::assertCount(1, $this->source()->issues());

        $this->connection->update('tl_consho_payment_method', ['published' => 1], ['id' => 9]);
        self::assertSame([], $this->source()->issues());
    }

    private function source(): MandateMethodIssueSource
    {
        $card = new FakeRecurringHandler();
        $handlers = new PaymentHandlers(new ServiceLocator([
            InvoicePaymentHandler::getType() => static fn (): PaymentHandlerInterface => new InvoicePaymentHandler(),
            FakeRecurringHandler::TYPE => static fn (): PaymentHandlerInterface => $card,
        ]));

        return new MandateMethodIssueSource($this->connection, new SubscriptionBillingMethod(new DbalPaymentMethodRepository($this->connection), $handlers));
    }

    private function subscription(int $id, int $method, string $state = 'active', string $mandate = 'm-1'): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => $id, 'subscriptionNumber' => 'WEB-'.$id.'-1', 'shop' => 1, 'state' => $state, 'mandate' => $mandate, 'mandateMethod' => $method]);
    }
}
