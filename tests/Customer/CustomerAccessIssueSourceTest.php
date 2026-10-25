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
 * Nachlese KA5, KA6, E2: Kommt ein Kunde hinein? Registrierungsweg,
 * Kundenseite mit falschen Gruppen, fehlende 401-Seite.
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

        // Abo-Produkt im Shop.
        $this->connection->insert('tl_consho_product', ['id' => 4, 'pid' => 0, 'published' => 1, 'saleSubscription' => 1]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 4, 'shop' => 1]);

        // Startpunkt 1 → Konto 10 (geschützt für Kunden + Händler) → Meine Abos 11; 401-Seite 12.
        $this->page(1, 0, 'root', 'Shop DE', ['consho_shop' => 1]);
        $this->page(10, 1, 'regular', 'Konto', ['protected' => 1, '"groups"' => serialize(['7', '8'])]);
        $this->page(11, 10, 'regular', 'Meine Abos');
        $this->page(12, 1, 'error_401', 'Anmelden');
        $this->connection->insert('tl_article', ['id' => 20, 'pid' => 11, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 30, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'consho_my_subscriptions']);

        // Registrierung mit Aktivierungsmail und Kundengruppe.
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
     * Abnahme 2026-10-09: Ohne „Anmeldung erlauben" legt Contao das Mitglied
     * mit login = 0 an — freigeschaltet, aber ohne Anmeldung.
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
     * Ohne Gastbestellung braucht jeder Kauf ein Konto, auch
     * ohne Abos im Shop.
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
     * Warnung, keine Sperre: Ein Shop, dessen Konten
     * der Betreiber selbst anlegt, braucht keine Registrierung.
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
        // Die Seite selbst ist für Kunden geschützt — die Eltern ließen beide zu, entscheidend ist die nächste.
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
