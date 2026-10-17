<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalCheckoutReadinessIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationCheck;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalCheckoutReadinessIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'regular', published INTEGER NOT NULL DEFAULT 1, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_checkoutPage INTEGER NULL, consho_cartPage INTEGER NOT NULL DEFAULT 0, consho_orderPage INTEGER NOT NULL DEFAULT 0, consho_shippingCostsPage INTEGER NOT NULL DEFAULT 0)",
        );
        $this->connection->executeStatement(
            // published/start/stop as in Contao's DCA — published defaults to false there.
            "CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, ptable TEXT NOT NULL DEFAULT 'tl_article', type TEXT NOT NULL DEFAULT 'text', invisible INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_shipping_method');
        DcaSchema::create($this->connection, 'tl_consho_payment_method');

        // Mail-Transport und Heimatland gesetzt: Sie sind seit dem zweiten
        // Review auch hier Befunde, sollen aber die anderen Tests nicht stören.
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'mailTransport' => 7, 'country' => 'AT']);
    }

    public function testEinVollstaendigEingerichteterShopMeldetNichts(): void
    {
        $this->vollstaendig();

        self::assertSame([], $this->issues());
    }

    public function testEinStartpunktOhneCheckoutSeiteWirdGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_page', ['consho_checkoutPage' => 0], ['id' => 1]);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::CheckoutPageMissing, $issue->finding);
        self::assertSame('1', $issue->context['count']);
        self::assertSame('Shop DE', $issue->context['pages']);
    }

    /**
     * 🔴 Bei der Abnahme von C-5 selbst auf c5 gefunden (2026-09-22): Zwei
     * UNVERÖFFENTLICHTE Test-Startpunkte von Shop 1 hatten keine
     * Checkout-Seite. Der Befund feuerte, und über die Bereitschaft sperrte
     * er den Checkout eines Shops, dessen echter Startpunkt vollständig
     * eingerichtet war.
     *
     * Ein unveröffentlichter Startpunkt bedient niemanden.
     */
    public function testEinUnveroeffentlichterStartpunktWirdNichtGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->insert('tl_page', [
            'id' => 50, 'title' => 'Test-Startpunkt', 'type' => 'root',
            'published' => 0, 'consho_shop' => 1, 'consho_checkoutPage' => 0,
        ]);

        self::assertSame([], $this->issues());
    }

    /**
     * N1 (Nachlese Stufe 2): Die Einrichtungsseite fragt auch unveröffentlichte
     * Startpunkte — ein Shop in Vorbereitung meldete dort sonst „erledigt",
     * obwohl keine Shopseite angelegt war. Startseite und Checkout bleiben bei
     * den veröffentlichten (Test darüber).
     */
    public function testFuerDieEinrichtungZaehltAuchEinUnveroeffentlichterStartpunkt(): void
    {
        $this->vollstaendig();
        $this->connection->insert('tl_page', [
            'id' => 50, 'title' => 'Test-Startpunkt', 'type' => 'root',
            'published' => 0, 'start' => (string) (time() + 86_400), 'consho_shop' => 1, 'consho_checkoutPage' => 0,
        ]);

        $findings = array_map(
            static fn (ConfigurationIssue $issue): ConfigurationFinding => $issue->finding,
            (new DbalCheckoutReadinessIssueSource($this->connection, new ConfigurationCheck()))->issuesForSetup(),
        );

        self::assertContains(ConfigurationFinding::CheckoutPageMissing, $findings);
        self::assertContains(ConfigurationFinding::CartPageMissing, $findings);
        self::assertSame([], $this->issues(), 'der normale Weg bleibt bei veröffentlichten');
    }

    public function testEinZeitlichUnerreichbarerStartpunktWirdNichtGemeldet(): void
    {
        // Dieselbe Regel wie bei den Kundengruppen in C-4: Was ausserhalb
        // seines Fensters liegt, ist so unerreichbar wie Unveröffentlichtes.
        $this->vollstaendig();
        $this->connection->insert('tl_page', [
            'id' => 51, 'title' => 'Noch nicht', 'type' => 'root',
            'published' => 1, 'start' => (string) (time() + 86_400),
            'consho_shop' => 1, 'consho_checkoutPage' => 0,
        ]);

        self::assertSame([], $this->issues());
    }

    public function testEinVeroeffentlichterStartpunktOhneKasseWirdSehrWohlGemeldet(): void
    {
        // Die Gegenprobe: Sonst prüfte der Test oben nur, dass nie etwas kommt.
        $this->vollstaendig();
        $this->connection->insert('tl_page', [
            'id' => 52, 'title' => 'Zweiter Startpunkt', 'type' => 'root',
            'published' => 1, 'consho_shop' => 1, 'consho_checkoutPage' => 0,
        ]);

        self::assertSame(ConfigurationFinding::CheckoutPageMissing, $this->issues()[0]->finding);
    }

    public function testEinStartpunktOhneShopWirdNichtGemeldet(): void
    {
        // Eine Website ohne Consho hat keine Checkout-Seite und braucht keine.
        $this->vollstaendig();
        $this->connection->insert('tl_page', ['id' => 99, 'title' => 'Andere Site', 'type' => 'root', 'consho_shop' => 0]);

        self::assertSame([], $this->issues());
    }

    public function testEineCheckoutSeiteOhneElementWirdGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_content', ['type' => 'text'], ['id' => 1]);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::CheckoutElementMissing, $issue->finding);
        self::assertSame('Kasse', $issue->context['pages']);
    }

    public function testEinUnsichtbaresElementZaehltNicht(): void
    {
        // Ein auf unsichtbar gestelltes Element rendert nicht — die Seite ist
        // so leer wie ohne.
        $this->vollstaendig();
        $this->connection->update('tl_content', ['invisible' => 1], ['id' => 1]);

        self::assertSame(ConfigurationFinding::CheckoutElementMissing, $this->issues()[0]->finding);
    }

    public function testEinShopOhneAusgewaehlteVersandartWirdGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_consho_shop', ['shippingMethods' => null], ['id' => 1]);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::ShippingMethodMissing, $issue->finding);
        self::assertSame('Testshop', $issue->context['shops']);
    }

    /**
     * 🔴 Ausgewählt UND verfügbar. Ein Shop, der drei Arten angehakt und dann
     * alle drei unveröffentlicht hat, ist genau in diesem Zustand — eine
     * Prüfung auf „nichts angehakt" nennte das eingerichtet.
     */
    public function testEineAusgewaehlteAberUnveroeffentlichteArtZaehltNicht(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_consho_shipping_method', ['published' => 0], ['id' => 4]);

        self::assertSame(ConfigurationFinding::ShippingMethodMissing, $this->issues()[0]->finding);
    }

    public function testEineVersandartOhneSteuerklasseZaehltNicht(): void
    {
        // act=toggle veröffentlicht ohne Pflichtfeldprüfung — dieselbe
        // Bedingung wie im Checkout (DbalShippingMethodRepository::offerable()).
        $this->vollstaendig();
        $this->connection->update('tl_consho_shipping_method', ['taxClass' => 0], ['id' => 4]);

        self::assertSame(ConfigurationFinding::ShippingMethodMissing, $this->issues()[0]->finding);
    }

    public function testEinShopOhneZahlungsartWirdGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_consho_payment_method', ['published' => 0], ['id' => 9]);

        self::assertSame(ConfigurationFinding::PaymentMethodMissing, $this->issues()[0]->finding);
    }

    public function testEinFehlenderMailTransportWirdHierGemeldet(): void
    {
        // Seit dem zweiten Review vom 2026-09-22 liegt diese Prüfung hier und
        // nicht mehr bei der Steuerdiagnose — sie sperrt den Checkout, und
        // die läuft bei jedem Aufruf mit.
        $this->vollstaendig();
        $this->connection->update('tl_consho_shop', ['mailTransport' => 0], ['id' => 1]);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::MailTransportMissing, $issue->finding);
        self::assertSame(1, $issue->shopId);
    }

    public function testEinFehlendesHeimatlandWirdHierGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_consho_shop', ['country' => ''], ['id' => 1]);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::HomeCountryMissing, $issue->finding);
        self::assertSame(1, $issue->shopId);
    }

    // ── Warenkorb- und Bestellseite (C-6) ───────────────────────────────────

    public function testEinStartpunktOhneWarenkorbseiteWirdGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_page', ['consho_cartPage' => 0], ['id' => 1]);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::CartPageMissing, $issue->finding);
        self::assertSame('Shop DE', $issue->context['pages']);
        self::assertSame(1, $issue->shopId);
    }

    public function testEinStartpunktOhneBestellseiteWirdGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_page', ['consho_orderPage' => 0], ['id' => 1]);

        self::assertSame(ConfigurationFinding::OrderPageMissing, $this->issues()[0]->finding);
    }

    /**
     * 🔴 Die Zeile aus 11.3, die C-6 zunächst übersah: „Startpunkt ohne …
     * Seite, ODER SEITE OHNE PASSENDES ELEMENT". Für die Checkout-Seite war
     * sie umgesetzt, für die Warenkorbseite nicht — der Besucher landete nach
     * dem Hinzufügen auf einer leeren Seite (Review 2026-09-22).
     */
    public function testEineWarenkorbseiteOhneElementWirdGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_content', ['type' => 'text'], ['id' => 6]);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::CartElementMissing, $issue->finding);
        self::assertSame('Warenkorb', $issue->context['pages']);
    }

    /**
     * C-10 (E5): Die Bestellseite ist eingestellt, aber leer — der Käufer
     * landete nach dem Kauf und über den Link der Bestätigung im Nichts.
     */
    /**
     * E-6, V5: Die Versandkostenseite wie Warenkorb- und Bestellseite — aber
     * ohne den Checkout zu sperren. Nicht gewählt ist ein Hinweis, gewählt
     * und leer eine Warnung.
     */
    public function testDieVersandkostenseiteWirdGeprueftSperrtAberNicht(): void
    {
        $this->vollstaendig();
        $this->connection->delete('tl_content', ['id' => 20]);

        $issues = $this->issues();
        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::ShippingCostsElementMissing, $issues[0]->finding);
        self::assertSame('Versandkosten', $issues[0]->context['pages']);
        self::assertTrue($issues[0]->finding->isWarning());

        $source = new DbalCheckoutReadinessIssueSource($this->connection, new ConfigurationCheck());
        self::assertTrue((new CheckoutReadiness([$source]))->isReady(1), 'eine Warnung, aber keine Sperre');

        $this->connection->update('tl_page', ['consho_shippingCostsPage' => 0], ['id' => 1]);

        $issues = $this->issues();
        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::ShippingCostsPageMissing, $issues[0]->finding);
        self::assertFalse($issues[0]->finding->isWarning());
        self::assertTrue((new CheckoutReadiness([$source]))->isReady(1));
    }

    public function testEineBestellseiteOhneElementWirdGemeldet(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_content', ['type' => 'text'], ['id' => 8]);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::OrderElementMissing, $issue->finding);
        self::assertSame('Bestellung', $issue->context['pages']);
        self::assertSame(1, $issue->shopId);
    }

    public function testEinUnsichtbaresBestellElementZaehltNicht(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_content', ['invisible' => 1], ['id' => 8]);

        self::assertSame(ConfigurationFinding::OrderElementMissing, $this->issues()[0]->finding);
    }

    /**
     * Gefunden beim Deploy von C-10: Der Artikel der Bestellseite war
     * unveröffentlicht, der Befund schwieg. Ein Artikel oder Element außerhalb
     * seines Zeitraums rendert ebenso wenig.
     *
     * @return iterable<string, array{string, array<string, int|string>, int, ConfigurationFinding}>
     */
    public static function hiddenPlacements(): iterable
    {
        $past = (string) (time() - 3600);
        $future = (string) (time() + 86400);

        yield 'Kasse, Artikel unveröffentlicht' => ['tl_article', ['published' => 0], 1, ConfigurationFinding::CheckoutElementMissing];
        yield 'Kasse, Artikel abgelaufen' => ['tl_article', ['stop' => $past], 1, ConfigurationFinding::CheckoutElementMissing];
        yield 'Kasse, Element noch nicht gestartet' => ['tl_content', ['start' => $future], 1, ConfigurationFinding::CheckoutElementMissing];
        yield 'Warenkorb, Artikel unveröffentlicht' => ['tl_article', ['published' => 0], 5, ConfigurationFinding::CartElementMissing];
        yield 'Bestellseite, Artikel unveröffentlicht' => ['tl_article', ['published' => 0], 7, ConfigurationFinding::OrderElementMissing];
        yield 'Bestellseite, Element abgelaufen' => ['tl_content', ['stop' => $past], 8, ConfigurationFinding::OrderElementMissing];
    }

    /**
     * @param array<string, int|string> $change
     *
     * @dataProvider hiddenPlacements
     */
    public function testEinVerborgenerArtikelOderZeitraumZaehltNicht(string $table, array $change, int $id, ConfigurationFinding $finding): void
    {
        $this->vollstaendig();
        $this->connection->update($table, $change, ['id' => $id]);

        self::assertSame([$finding], array_map(static fn (ConfigurationIssue $issue): ConfigurationFinding => $issue->finding, $this->issues()));
    }

    /** Gegenprobe: ein Zeitraum, der jetzt gilt, verbirgt nichts. */
    public function testEinLaufenderZeitraumZaehlt(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_article', ['start' => (string) (time() - 3600), 'stop' => (string) (time() + 86400)], ['id' => 1]);
        $this->connection->update('tl_content', ['start' => (string) (time() - 3600)], ['id' => 8]);

        self::assertSame([], $this->issues());
    }

    /** Verschachtelt: auch der Artikel des äußeren Elements muss sichtbar sein. */
    public function testEinVerschachteltesElementInEinemUnveroeffentlichtenArtikel(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_content', ['type' => 'accordion'], ['id' => 6]);
        $this->connection->insert('tl_content', ['id' => 7, 'pid' => 6, 'ptable' => 'tl_content', 'type' => 'consho_cart']);
        $this->connection->update('tl_article', ['published' => 0], ['id' => 5]);

        self::assertSame(ConfigurationFinding::CartElementMissing, $this->issues()[0]->finding);
    }

    public function testEinUnsichtbaresWarenkorbElementZaehltNicht(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_content', ['invisible' => 1], ['id' => 6]);

        self::assertSame(ConfigurationFinding::CartElementMissing, $this->issues()[0]->finding);
    }

    /**
     * Dieselbe zweite Platzierung wie beim Checkout-Element: ein Element in
     * einem Element (Elementgruppe, Akkordeon) trägt `ptable = 'tl_content'`.
     */
    public function testEinVerschachteltesWarenkorbElementZaehlt(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_content', ['type' => 'accordion'], ['id' => 6]);
        $this->connection->insert('tl_content', [
            'id' => 7, 'pid' => 6, 'ptable' => 'tl_content', 'type' => 'consho_cart',
        ]);

        self::assertSame([], $this->issues());
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalCheckoutReadinessIssueSource($this->connection, new ConfigurationCheck()))->issues();
    }

    private function vollstaendig(): void
    {
        $this->connection->insert('tl_page', [
            'id' => 1, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1,
            'consho_checkoutPage' => 2, 'consho_cartPage' => 3, 'consho_orderPage' => 4, 'consho_shippingCostsPage' => 20,
        ]);
        $this->connection->insert('tl_page', ['id' => 2, 'title' => 'Kasse', 'type' => 'regular']);
        $this->connection->insert('tl_page', ['id' => 3, 'title' => 'Warenkorb', 'type' => 'regular']);
        $this->connection->insert('tl_page', ['id' => 4, 'title' => 'Bestellung', 'type' => 'regular']);
        $this->connection->insert('tl_article', ['id' => 5, 'pid' => 3, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 6, 'pid' => 5, 'ptable' => 'tl_article', 'type' => 'consho_cart']);
        $this->connection->insert('tl_article', ['id' => 1, 'pid' => 2, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 1, 'pid' => 1, 'ptable' => 'tl_article', 'type' => 'consho_checkout']);
        $this->connection->insert('tl_article', ['id' => 7, 'pid' => 4, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 8, 'pid' => 7, 'ptable' => 'tl_article', 'type' => 'consho_order']);
        $this->connection->insert('tl_page', ['id' => 20, 'title' => 'Versandkosten', 'type' => 'regular']);
        $this->connection->insert('tl_article', ['id' => 20, 'pid' => 20, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 20, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'consho_shipping_costs']);

        $this->connection->insert('tl_consho_shipping_method', ['id' => 4, 'type' => 'flat_rate', 'published' => 1, 'taxClass' => 1]);
        $this->connection->insert('tl_consho_payment_method', ['id' => 9, 'type' => 'prepayment', 'published' => 1]);
        $this->connection->update(
            'tl_consho_shop',
            ['shippingMethods' => serialize(['4']), 'paymentMethods' => serialize(['9'])],
            ['id' => 1],
        );
    }
}
