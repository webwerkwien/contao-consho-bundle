<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentMethodRepository;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingIssueSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingMethod;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-4, P24: follow-up orders go "on invoice" — via the shop's first
 * invoice payment method, without its checkout rules. If it is missing, a
 * finding says so. The shop's selection is read by the real repository.
 *
 * Shop 1 lists 2 prepayment, 5 invoice only for group 9, 6 invoice.
 */
class SubscriptionBillingTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_payment_method', 'tl_consho_product', 'tl_consho_product_shop', 'tl_consho_subscription'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'paymentMethods' => serialize(['2', '5', '6'])]);
        $this->method(2, 'prepayment', 'Vorkasse');
        // Group and amount limits: they apply to the checkout, not to the concluded contract.
        $this->method(5, 'invoice', 'Auf Rechnung (Firmen)', ['allowedGroups' => ['9'], 'maxNet' => '1', 'maxNetCurrency' => 'EUR']);
        $this->method(6, 'invoice', 'Auf Rechnung');
    }

    public function testDieErsteRechnungsartInDerReihenfolgeDesShopsOhneIhreKassenregeln(): void
    {
        $method = $this->billing()->forShop(1);

        self::assertSame([5, 'invoice'], [$method?->id, $method?->type]);
    }

    public function testDieReihenfolgeDesShopsZaehltNichtDieId(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['6', '5'])], ['id' => 1]);

        self::assertSame(6, $this->billing()->forShop(1)?->id);
    }

    public function testUnveroeffentlichtZaehltNicht(): void
    {
        $this->connection->update('tl_consho_payment_method', ['published' => 0], ['type' => 'invoice']);

        self::assertNull($this->billing()->forShop(1));
    }

    public function testEinShopMitAbosUndOhneRechnungsartBekommtDenBefund(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['2'])], ['id' => 1]);
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'shop' => 1]);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::SubscriptionBillingMethodMissing, ['shop' => 'Testshop'])],
            $this->issues()->issues(),
        );
        self::assertTrue(ConfigurationFinding::SubscriptionBillingMethodMissing->isWarning());
    }

    /**
     * Abo-5: a suspended subscription keeps being billed (P36)
     * and needs the invoice payment method just as well.
     */
    public function testAuchEinRuhendesAboBrauchtDieRechnungsart(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['2'])], ['id' => 1]);
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'state' => 'suspended']);

        self::assertCount(1, $this->issues()->issues());
    }

    /**
     * Pausing a subscription (P106): a paused subscription comes back afterwards and
     * needs the invoice payment method just as well.
     */
    public function testAuchEinPausiertesAboBrauchtDieRechnungsart(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['2'])], ['id' => 1]);
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'state' => 'paused']);

        self::assertCount(1, $this->issues()->issues());
    }

    /**
     * Abo-5: a main product without a shop assignment is sold
     * nowhere (VisibilityCriteria) — on a real installation it was a 404. The test
     * previously pinned the opposite: "applies to every shop".
     */
    public function testEinAboProduktZaehltNurImZugeordnetenShop(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['2'])], ['id' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 4, 'title' => 'E-Book', 'published' => 1, 'saleSubscription' => 1]);

        self::assertSame([], $this->issues()->issues(), 'ohne Zuordnung: nirgends verkauft');

        $this->connection->insert('tl_consho_product_shop', ['pid' => 4, 'shop' => 2]);
        self::assertSame([], $this->issues()->issues(), 'einem anderen Shop zugeordnet');

        $this->connection->insert('tl_consho_product_shop', ['pid' => 4, 'shop' => 1]);
        $assigned = $this->issues();
        self::assertCount(1, $assigned->issues(), 'diesem Shop zugeordnet');
    }

    public function testMitRechnungsartKeinBefund(): void
    {
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'shop' => 1]);

        self::assertSame([], $this->issues()->issues());
    }

    public function testEinShopOhneAbosBrauchtKeineRechnungsart(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['2'])], ['id' => 1]);
        // One-off product, ended subscription: neither is a reason.
        $this->connection->insert('tl_consho_product', ['id' => 4, 'title' => 'E-Book', 'published' => 1, 'saleSubscription' => 0]);
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'state' => 'ended']);

        self::assertSame([], $this->issues()->issues());
    }

    private function billing(): SubscriptionBillingMethod
    {
        return new SubscriptionBillingMethod(new DbalPaymentMethodRepository($this->connection));
    }

    private function issues(): SubscriptionBillingIssueSource
    {
        return new SubscriptionBillingIssueSource($this->connection, $this->billing());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function method(int $id, string $type, string $title, array $settings = []): void
    {
        $this->connection->insert('tl_consho_payment_method', ['id' => $id, 'type' => $type, 'title' => $title, 'published' => 1, 'jsonData' => json_encode($settings, JSON_THROW_ON_ERROR)]);
    }
}
