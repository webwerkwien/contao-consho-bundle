<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Setup;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationPageIssueSource;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalCheckoutReadinessIssueSource;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalLegalReadinessIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationCheck;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Setup\ShopPageSetup;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block I (I1, I2): shop pages at the push of a button — against tl_page,
 * tl_article and tl_content from Contao's own DCA, not against a copy.
 */
class ShopPageSetupTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    /**
     * @var list<string>
     */
    private array $versionCalls = [];

    /**
     * @var list<list<string>>
     */
    private array $invalidated = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_page', 'tl_article', 'tl_content'] as $table) {
            DcaSchema::createCore($this->connection, $table);
        }

        $this->connection->insert('tl_page', ['id' => 1, 'title' => 'Shop DE', 'type' => 'root', 'language' => 'de', 'consho_shop' => 1, 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 2, 'pid' => 1, 'sorting' => 256, 'title' => 'Startseite', 'alias' => 'index', 'published' => 1]);

        $GLOBALS['TL_LANG']['CONSHO']['setup']['pageTitles'] = ['cart' => 'Warenkorb', 'checkout' => 'Kasse', 'order' => 'Ihre Bestellung', 'shippingCosts' => 'Versandkosten', 'withdrawal' => 'Vertrag widerrufen', 'cancellation' => 'Verträge hier kündigen'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testOhneBefundNurDieSeitenDieJederShopBraucht(): void
    {
        self::assertSame(
            ['consho_cartPage', 'consho_checkoutPage', 'consho_orderPage', 'consho_shippingCostsPage'],
            $this->pages()->missing(1, 1, []),
        );
    }

    public function testWiderrufUndKuendigungNurWoDieBefundeSieVerlangen(): void
    {
        $issues = [
            new ConfigurationIssue(ConfigurationFinding::WithdrawalFunctionAdvised, [], 1),
            new ConfigurationIssue(ConfigurationFinding::CancellationPageMissing, [], 1),
            new ConfigurationIssue(ConfigurationFinding::CancellationPageMissing, [], 2),
        ];

        $missing = $this->pages()->missing(1, 1, $issues);

        self::assertContains('consho_withdrawalFormPage', $missing);
        self::assertContains('consho_cancellationPage', $missing);
        self::assertNotContains('consho_cancellationPage', $this->pages()->missing(1, 1, [$issues[2]]), 'der Befund eines anderen Shops zählt nicht');
    }

    public function testEinGesetztesFeldBleibtUnberuehrt(): void
    {
        // If the field points to a page without an element, the operator decides
        // what that page is — the assistant only links it.
        $this->connection->update('tl_page', ['consho_cartPage' => 2], ['id' => 1]);

        self::assertNotContains('consho_cartPage', $this->pages()->missing(1, 1, []));
    }

    public function testLegtSeiteArtikelUndElementAnUndVerknuepftSie(): void
    {
        $titles = $this->pages()->create(1, ['consho_cartPage', 'consho_checkoutPage'], 7, self::NOW);

        self::assertSame(['Warenkorb', 'Kasse'], $titles);

        $root = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = 1');
        self::assertIsArray($root);

        $cart = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$root['consho_cartPage']]);
        self::assertIsArray($cart);
        self::assertSame(['pid' => 1, 'title' => 'Warenkorb', 'alias' => 'warenkorb', 'type' => 'regular', 'published' => 1, 'hide' => 1, 'robots' => 'noindex,nofollow', 'sitemap' => 'map_never', 'searchIndexer' => 'never_index', 'sorting' => 384], [
            'pid' => (int) $cart['pid'], 'title' => $cart['title'], 'alias' => $cart['alias'], 'type' => $cart['type'], 'published' => (int) $cart['published'], 'hide' => (int) $cart['hide'],
            'robots' => $cart['robots'], 'sitemap' => $cart['sitemap'], 'searchIndexer' => $cart['searchIndexer'], 'sorting' => (int) $cart['sorting'],
        ], 'am Ende der ersten Ebene, versteckt, nicht im Index');

        $article = $this->connection->fetchAssociative('SELECT * FROM tl_article WHERE pid = ?', [$cart['id']]);
        self::assertIsArray($article);
        self::assertSame(['main', 1, 7], [$article['inColumn'], (int) $article['published'], (int) $article['author']]);

        $element = $this->connection->fetchAssociative('SELECT * FROM tl_content WHERE pid = ?', [$article['id']]);
        self::assertIsArray($element);
        self::assertSame(['tl_article', 'consho_cart', 0], [$element['ptable'], $element['type'], (int) $element['invisible']]);

        $checkout = $this->connection->fetchOne('SELECT c.type FROM tl_content c JOIN tl_article a ON a.id = c.pid JOIN tl_page p ON p.id = a.pid WHERE p.id = ?', [$root['consho_checkoutPage']]);
        self::assertSame('consho_checkout', $checkout);

        self::assertSame(['setEditUrl', 'initialize:empty', 'create:linked'], $this->versionCalls, 'eine Version des Startpunkts vor und nach der Änderung');
        self::assertSame([['contao.db.tl_page.1']], $this->invalidated);
    }

    public function testDieEchtePruefungIstDanachZufrieden(): void
    {
        // Not "the rows are there" but "the finding they were created for falls
        // silent" — what is asked is the source that locks the purchase.
        foreach (['tl_consho_shop', 'tl_consho_shipping_method', 'tl_consho_payment_method'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Shop DE']);
        $source = new DbalCheckoutReadinessIssueSource($this->connection, new ConfigurationCheck());
        $pageFindings = static fn (): array => array_values(array_filter(
            array_map(static fn (ConfigurationIssue $issue): string => $issue->finding->value, $source->issues()),
            static fn (string $finding): bool => str_contains($finding, 'Page') || str_contains($finding, 'Element'),
        ));

        self::assertSame(['checkoutPageMissing', 'cartPageMissing', 'orderPageMissing', 'shippingCostsPageMissing'], $pageFindings());

        $this->pages()->create(1, $this->pages()->missing(1, 1, []), 7, self::NOW);

        self::assertSame([], $pageFindings());
    }

    public function testWiderrufUndKuendigungStellenIhreQuellenZufrieden(): void
    {
        // I: otherwise a wrong element type on these two pages survives the
        // whole suite — and the withdrawal would stay locked.
        foreach (['tl_consho_shop', 'tl_consho_payment_method', 'tl_consho_number_range', 'tl_consho_subscription', 'tl_consho_product', 'tl_consho_product_shop'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // tl_files' DCA needs the container at load; the legal source only reads
        // uuid and type there (as in DbalLegalReadinessIssueSourceTest).
        $this->connection->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT 'file')");
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Shop DE', 'country' => 'AT', 'withdrawalDays' => 14]);
        $this->connection->insert('tl_consho_subscription', ['shop' => 1, 'state' => 'active']);

        $handlers = new PaymentHandlers(new class implements ServiceProviderInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException($id);
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function getProvidedServices(): array
            {
                return [];
            }
        });

        $sources = [new DbalLegalReadinessIssueSource($this->connection, $handlers), new CancellationPageIssueSource($this->connection)];
        $found = static fn (): array => array_values(array_filter(
            array_map(static fn (ConfigurationIssue $issue): string => $issue->finding->value, array_merge(...array_map(static fn (ConfigurationIssueSource $source): array => $source->issues(), $sources))),
            static fn (string $finding): bool => \in_array($finding, ['withdrawalFunctionMissing', 'cancellationPageMissing'], true),
        ));

        $before = $found();
        sort($before);
        self::assertSame(['cancellationPageMissing', 'withdrawalFunctionMissing'], $before);

        $issues = [new ConfigurationIssue(ConfigurationFinding::WithdrawalFunctionMissing, [], 1), new ConfigurationIssue(ConfigurationFinding::CancellationPageMissing, [], 1)];
        $this->pages()->create(1, $this->pages()->missing(1, 1, $issues), 7, self::NOW);

        self::assertSame([], $found());
    }

    public function testEinBelegterAliasBekommtEineNummer(): void
    {
        $this->connection->insert('tl_page', ['pid' => 99, 'title' => 'Warenkorb', 'alias' => 'warenkorb']);

        $this->pages()->create(1, ['consho_cartPage'], 7, self::NOW);

        self::assertSame('warenkorb-2', $this->connection->fetchOne('SELECT alias FROM tl_page WHERE pid = 1 AND title = ?', ['Warenkorb']));
    }

    public function testZweimalKlickenLegtNichtsDoppeltAn(): void
    {
        $setup = $this->pages();
        $setup->create(1, ['consho_cartPage'], 7, self::NOW);

        self::assertSame([], $setup->create(1, ['consho_cartPage'], 7, self::NOW));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_content WHERE type = 'consho_cart'"));
        self::assertSame(['setEditUrl', 'initialize:empty', 'create:linked', 'setEditUrl', 'initialize:linked'], $this->versionCalls, 'ohne Änderung keine neue Version');
    }

    public function testDerTitelInDerSpracheDesStartpunkts(): void
    {
        $this->connection->update('tl_page', ['language' => 'en'], ['id' => 1]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with('CONSHO.setup.pageTitles.cart', [], 'contao_default', 'en')->willReturn('Basket');

        self::assertSame(['Basket'], $this->pages($translator)->create(1, ['consho_cartPage'], 7, self::NOW));
        self::assertSame('basket', $this->connection->fetchOne("SELECT alias FROM tl_page WHERE title = 'Basket'"));
    }

    private function pages(TranslatorInterface|null $translator = null): ShopPageSetup
    {
        $calls = &$this->versionCalls;
        $versions = $this->createMock(Versions::class);

        $connection = $this->connection;

        foreach (['setEditUrl', 'initialize', 'create'] as $method) {
            $versions->method($method)->willReturnCallback(static function () use (&$calls, $method, $versions, $connection): mixed {
                // I: the second version has to see the change, or
                // it equals the first. Recorded with the root's link at the call.
                $calls[] = 'setEditUrl' === $method ? $method : $method.':'.((int) $connection->fetchOne('SELECT consho_cartPage FROM tl_page WHERE id = 1') > 0 ? 'linked' : 'empty');

                return 'setEditUrl' === $method ? $versions : null;
            });
        }

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('createInstance')->willReturn($versions);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/contao?do=page');

        $invalidated = &$this->invalidated;
        $tags = $this->createMock(CacheTagManager::class);
        $tags->method('invalidateTags')->willReturnCallback(static function (array $list) use (&$invalidated, $tags): CacheTagManager {
            $invalidated[] = $list;

            return $tags;
        });

        return new ShopPageSetup($this->connection, $framework, $router, $translator, $tags);
    }
}
