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
 * Abo-4, P24: Folgebestellungen gehen „Auf Rechnung" — über die erste
 * Rechnungs-Zahlungsart des Shops, ohne ihre Kassenregeln. Fehlt sie, sagt
 * es ein Befund. Die Auswahl des Shops liest das echte Repository.
 *
 * Shop 1 listet 2 Vorkasse, 5 Rechnung nur für Gruppe 9, 6 Rechnung.
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
        // Gruppen- und Betragsgrenze: gilt der Kasse, nicht dem geschlossenen Vertrag.
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
     * Zweites Review Abo-5: Ein ruhendes Abo wird weiter abgerechnet (P36)
     * und braucht die Rechnungsart ebenso.
     */
    public function testAuchEinRuhendesAboBrauchtDieRechnungsart(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['2'])], ['id' => 1]);
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'state' => 'suspended']);

        self::assertCount(1, $this->issues()->issues());
    }

    /**
     * Abo pausieren (P106): ein pausiertes Abo kommt danach wieder und
     * braucht die Rechnungsart ebenso.
     */
    public function testAuchEinPausiertesAboBrauchtDieRechnungsart(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['2'])], ['id' => 1]);
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'state' => 'paused']);

        self::assertCount(1, $this->issues()->issues());
    }

    /**
     * Abnahme Abo-5: Ein Hauptprodukt ohne Shop-Zuordnung wird nirgends
     * verkauft (VisibilityCriteria) — auf c5 war es eine 404. Der Test hielt
     * vorher das Gegenteil fest: „gilt für jeden Shop".
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
        // Einmalkauf-Produkt, beendetes Abo: beides kein Grund.
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
