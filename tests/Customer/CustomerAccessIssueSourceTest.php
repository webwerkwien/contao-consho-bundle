<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding as F;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerAccessIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Nachlese KA5, KA6, E2: Can a customer get in? Registration path,
 * customer page with the wrong groups, missing 401 page.
 */
final class CustomerAccessIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_page', 'tl_article', 'tl_content', 'tl_module', 'tl_member_group'] as $table) {
            DcaSchema::createCore($this->connection, $table);
        }

        foreach (['tl_consho_shop', 'tl_consho_product', 'tl_consho_product_shop', 'tl_consho_subscription'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_member_group', ['id' => 7, 'name' => 'Kunden']);
        $this->connection->insert('tl_member_group', ['id' => 8, 'name' => 'Händler']);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop', 'customerGroup' => 7, 'netPriceGroups' => serialize(['8'])]);

        // Subscription product in the shop.
        $this->connection->insert('tl_consho_product', ['id' => 4, 'pid' => 0, 'published' => 1, 'saleSubscription' => 1]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 4, 'shop' => 1]);

        // Start point 1 → account 10 (protected for customers + dealers) → my subscriptions 11; 401 page 12.
        $this->page(1, 0, 'root', 'Shop DE', ['consho_shop' => 1]);
        $this->page(10, 1, 'regular', 'Konto', ['protected' => 1, '"groups"' => serialize(['7', '8'])]);
        $this->page(11, 10, 'regular', 'Meine Abos');
        $this->page(12, 1, 'error_401', 'Anmelden');
        $this->connection->insert('tl_article', ['id' => 20, 'pid' => 11, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 30, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'consho_my_subscriptions']);

        // Registration with activation mail and customer group.
        $this->connection->insert('tl_module', ['id' => 40, 'type' => 'registration', 'reg_allowLogin' => 1, 'reg_activate' => 1, 'reg_groups' => serialize(['7'])]);
    }

    public function testEingerichtetMeldetNichts(): void
    {
        self::assertSame([], $this->issues());
    }

    public function testOhneRegistrierungsmodul(): void
    {
        $this->connection->delete('tl_module', ['id' => 40]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(F::RegistrationPathMissing, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    public function testRegistrierungOhneAktivierungSchaltetNichtFrei(): void
    {
        $this->connection->update('tl_module', ['reg_activate' => 0], ['id' => 40]);

        self::assertSame([F::RegistrationPathMissing], $this->findings());
    }

    public function testRegistrierungOhneKundengruppe(): void
    {
        $this->connection->update('tl_module', ['reg_groups' => serialize(['8'])], ['id' => 40]);

        self::assertSame([F::RegistrationPathMissing], $this->findings());
    }

    /**
     * Without "allow login", Contao creates the member with login = 0 —
     * activated, but without login.
     */
    public function testOhneAnmeldungErlaubenKeinWeg(): void
    {
        $this->connection->update('tl_module', ['reg_allowLogin' => 0, 'consho_activateAtOnce' => 1], ['id' => 40]);

        self::assertSame([F::RegistrationPathMissing], $this->findings());
    }

    public function testSofortFreischaltenGenuegtAuchOhneGruppe(): void
    {
        $this->connection->update('tl_module', ['reg_activate' => 0, 'reg_groups' => null, 'consho_activateAtOnce' => 1], ['id' => 40]);

        self::assertSame([], $this->issues());
    }

    public function testOhneAbosKeinRegistrierungsbefund(): void
    {
        $this->connection->delete('tl_module', ['id' => 40]);
        $this->connection->update('tl_consho_product', ['saleSubscription' => 0], ['id' => 4]);

        self::assertSame([], $this->issues());
    }

    /**
     * Without guest orders every purchase needs an account, even
     * without subscriptions in the shop.
     */
    public function testOhneGastbestellungUndOhneAbosMeldetFehlendenWeg(): void
    {
        $this->connection->delete('tl_module', ['id' => 40]);
        $this->connection->update('tl_consho_product', ['saleSubscription' => 0], ['id' => 4]);
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(F::RegistrationPathMissingNoGuests, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
    }

    public function testOhneGastbestellungMitAbosNenntDenWeiterenGrund(): void
    {
        $this->connection->delete('tl_module', ['id' => 40]);
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);

        self::assertSame([F::RegistrationPathMissingNoGuests], $this->findings());
    }

    public function testOhneGastbestellungGiltDieselbeRegelFuerDenWeg(): void
    {
        $this->connection->update('tl_consho_product', ['saleSubscription' => 0], ['id' => 4]);
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);

        self::assertSame([], $this->issues());

        $this->connection->update('tl_module', ['reg_activate' => 0], ['id' => 40]);

        self::assertSame([F::RegistrationPathMissingNoGuests], $this->findings());
    }

    /**
     * A warning, not a block: a shop whose accounts the operator
     * creates himself needs no registration.
     */
    public function testOhneGastbestellungWarntSperrtAberNicht(): void
    {
        self::assertTrue(F::RegistrationPathMissingNoGuests->isWarning());
        self::assertNotContains(F::RegistrationPathMissingNoGuests, CheckoutReadiness::BLOCKING);
    }

    public function testGeerbterSchutzOhneNettogruppe(): void
    {
        $this->connection->update('tl_page', ['"groups"' => serialize(['7'])], ['id' => 10]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(F::CustomerPageLocksOut, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'page' => 'Meine Abos', 'groups' => 'Händler'], $issues[0]->context);
    }

    public function testEigenerSchutzZaehltVorDemDerEltern(): void
    {
        // The page itself is protected for customers — the parents allowed both, the nearest one decides.
        $this->connection->update('tl_page', ['protected' => 1, '"groups"' => serialize(['7'])], ['id' => 11]);

        self::assertSame([F::CustomerPageLocksOut], $this->findings());
    }

    public function testUngeschuetztBrauchtKeineAnmeldeseite(): void
    {
        $this->connection->update('tl_page', ['protected' => 0], ['id' => 10]);
        $this->connection->delete('tl_page', ['id' => 12]);

        self::assertSame([], $this->issues());
    }

    public function testGeschuetztOhne401Seite(): void
    {
        $this->connection->update('tl_page', ['published' => 0], ['id' => 12]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(F::LoginPageMissing, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'roots' => 'Shop DE'], $issues[0]->context);
    }

    public function testAbgelaufene401SeiteZaehltNicht(): void
    {
        $this->connection->update('tl_page', ['stop' => '1000'], ['id' => 12]);

        self::assertSame([F::LoginPageMissing], $this->findings());
    }

    public function testVersteckteElementeZaehlenNicht(): void
    {
        $this->connection->update('tl_page', ['"groups"' => serialize(['7'])], ['id' => 10]);
        $this->connection->update('tl_content', ['invisible' => 1], ['id' => 30]);

        self::assertSame([], $this->issues());
    }

    public function testEineEbeneTieferImContainer(): void
    {
        $this->connection->update('tl_page', ['"groups"' => serialize(['7'])], ['id' => 10]);
        $this->connection->update('tl_content', ['type' => 'element_group'], ['id' => 30]);
        $this->connection->insert('tl_content', ['id' => 31, 'pid' => 30, 'ptable' => 'tl_content', 'type' => 'consho_my_orders']);

        self::assertSame([F::CustomerPageLocksOut], $this->findings());
    }

    /**
     * The order page opens by the link in the mail. With guest checkout no
     * protection can be right — a guest has no login to get past it.
     */
    public function testGeschuetzteBestellseiteMitGastbestellung(): void
    {
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 1], ['id' => 1]);
        $this->page(13, 1, 'regular', 'Bestellung');
        $this->connection->update('tl_page', ['consho_orderPage' => 13], ['id' => 1]);

        self::assertSame([], $this->findings(), 'ungeschützt: nichts');

        // Inherited from the account page, with every group admitted.
        $this->connection->update('tl_page', ['pid' => 10], ['id' => 13]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(F::OrderPageProtected, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'page' => 'Bestellung'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    /**
     * Without guest checkout every buyer is a member: the order page follows
     * the rule of the customer pages, and counts towards the login page.
     */
    public function testGeschuetzteBestellseiteOhneGastbestellung(): void
    {
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);
        // Accounts by hand: the registration finding stays out of the way.
        $this->connection->update('tl_module', ['consho_activateAtOnce' => 1], ['id' => 40]);
        $this->page(13, 10, 'regular', 'Bestellung');
        $this->connection->update('tl_page', ['consho_orderPage' => 13], ['id' => 1]);

        self::assertSame([], $this->findings(), 'beide Gruppen zugelassen');

        $this->connection->update('tl_page', ['"groups"' => serialize(['7'])], ['id' => 10]);
        $this->connection->delete('tl_content', ['id' => 30]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(F::CustomerPageLocksOut, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'page' => 'Bestellung', 'groups' => 'Händler'], $issues[0]->context);

        $this->connection->update('tl_page', ['published' => 0], ['id' => 12]);
        self::assertSame([F::CustomerPageLocksOut, F::LoginPageMissing], $this->findings());
    }

    /**
     * The shop that names the order page decides, not the tree the page sits
     * in: a guest shop linking to a protected page under a members-only shop
     * locks its guests out all the same.
     */
    public function testBestellseiteUnterFremdemStartpunktZaehltFuerDenNennendenShop(): void
    {
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);
        $this->connection->update('tl_module', ['consho_activateAtOnce' => 1], ['id' => 40]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Gastshop', 'customerGroup' => 7, 'guestCheckout' => 1]);
        $this->page(2, 0, 'root', 'Gastshop DE', ['consho_shop' => 2]);
        $this->page(13, 10, 'regular', 'Bestellung');
        $this->connection->update('tl_page', ['consho_orderPage' => 13], ['id' => 2]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(F::OrderPageProtected, $issues[0]->finding);
        self::assertSame(['shop' => 'Gastshop', 'page' => 'Bestellung'], $issues[0]->context);
        self::assertSame(2, $issues[0]->shopId);
    }

    public function testStartpunktOhneShopZaehltNicht(): void
    {
        $this->connection->update('tl_page', ['consho_shop' => 0], ['id' => 1]);
        $this->connection->update('tl_page', ['"groups"' => serialize(['3'])], ['id' => 10]);
        $this->connection->delete('tl_page', ['id' => 12]);

        self::assertSame([], $this->issues());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function page(int $id, int $pid, string $type, string $title, array $values = []): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'pid' => $pid, 'type' => $type, 'title' => $title, 'published' => 1, ...$values]);
    }

    /**
     * @return list<F>
     */
    private function findings(): array
    {
        return array_map(static fn (ConfigurationIssue $issue): F => $issue->finding, $this->issues());
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new CustomerAccessIssueSource($this->connection))->issues();
    }
}
