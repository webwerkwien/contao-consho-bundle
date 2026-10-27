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

        DcaSchema::createCore($this->connection, 'tl_page');
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

        // Mail transport and home country set: they are issues here too,
        // but should not disturb the other tests.
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
     * 🔴 Two UNPUBLISHED test root pages of shop 1 had no checkout page. The
     * issue fired, and through the readiness it blocked the checkout of a shop
     * whose real root page was fully set up.
     *
     * An unpublished root page serves nobody.
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
     * N1 (Nachlese stage 2): the setup page also asks unpublished root pages —
     * otherwise a shop in preparation reported "done" there although no shop
     * page had been created. Start page and checkout stay with the published
     * ones (test above).
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
        // The same rule as for the member groups in C-4: what lies outside
        // its window is as unreachable as something unpublished.
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
        // The control case: otherwise the test above would only check that nothing ever comes.
        $this->vollstaendig();
        $this->connection->insert('tl_page', [
            'id' => 52, 'title' => 'Zweiter Startpunkt', 'type' => 'root',
            'published' => 1, 'consho_shop' => 1, 'consho_checkoutPage' => 0,
        ]);

        self::assertSame(ConfigurationFinding::CheckoutPageMissing, $this->issues()[0]->finding);
    }

    public function testEinStartpunktOhneShopWirdNichtGemeldet(): void
    {
        // A website without Consho has no checkout page and needs none.
        $this->vollstaendig();
        $this->connection->insert('tl_page', ['id' => 99, 'title' => 'Andere Site', 'type' => 'root', 'consho_shop' => 0, 'published' => 1]);

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
        // An element set to invisible does not render — the page is
        // as empty as without it.
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
     * 🔴 Selected AND available. A shop that ticked three methods and then
     * unpublished all three is in exactly this state — a check for "nothing
     * ticked" would call that set up.
     */
    public function testEineAusgewaehlteAberUnveroeffentlichteArtZaehltNicht(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_consho_shipping_method', ['published' => 0], ['id' => 4]);

        self::assertSame(ConfigurationFinding::ShippingMethodMissing, $this->issues()[0]->finding);
    }

    public function testEineVersandartOhneSteuerklasseZaehltNicht(): void
    {
        // act=toggle publishes without the required-field check — the same
        // condition as in the checkout (DbalShippingMethodRepository::offerable()).
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
        // This check lives here and no longer in the tax diagnosis — it
        // blocks the checkout, and that runs on every request.
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

    // ── Cart and order page (C-6) ───────────────────────────────────────────

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
     * 🔴 The line from 11.3 that C-6 overlooked at first: "root page without …
     * page, OR PAGE WITHOUT A MATCHING ELEMENT". It was implemented for the
     * checkout page, not for the cart page — after adding something the
     * visitor landed on an empty page.
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
     * C-10 (E5): the order page is set, but empty — after the purchase and
     * through the link in the confirmation the buyer landed in nowhere.
     */
    /**
     * E-6, V5: the shipping costs page like the cart and order pages — but
     * without blocking the checkout. Not chosen is a notice, chosen and empty
     * a warning.
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
     * The order page's article was unpublished, and the issue stayed silent.
     * An article or element outside its time window does not render either.
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

    /** Control case: a time window that applies now hides nothing. */
    public function testEinLaufenderZeitraumZaehlt(): void
    {
        $this->vollstaendig();
        $this->connection->update('tl_article', ['start' => (string) (time() - 3600), 'stop' => (string) (time() + 86400)], ['id' => 1]);
        $this->connection->update('tl_content', ['start' => (string) (time() - 3600)], ['id' => 8]);

        self::assertSame([], $this->issues());
    }

    /** Nested: the article of the outer element must be visible too. */
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
     * The same second placement as for the checkout element: an element in
     * an element (element group, accordion) carries `ptable = 'tl_content'`.
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
            'published' => 1,
        ]);
        $this->connection->insert('tl_page', ['id' => 2, 'title' => 'Kasse', 'type' => 'regular', 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 3, 'title' => 'Warenkorb', 'type' => 'regular', 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 4, 'title' => 'Bestellung', 'type' => 'regular', 'published' => 1]);
        $this->connection->insert('tl_article', ['id' => 5, 'pid' => 3, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 6, 'pid' => 5, 'ptable' => 'tl_article', 'type' => 'consho_cart']);
        $this->connection->insert('tl_article', ['id' => 1, 'pid' => 2, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 1, 'pid' => 1, 'ptable' => 'tl_article', 'type' => 'consho_checkout']);
        $this->connection->insert('tl_article', ['id' => 7, 'pid' => 4, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 8, 'pid' => 7, 'ptable' => 'tl_article', 'type' => 'consho_order']);
        $this->connection->insert('tl_page', ['id' => 20, 'title' => 'Versandkosten', 'type' => 'regular', 'published' => 1]);
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
