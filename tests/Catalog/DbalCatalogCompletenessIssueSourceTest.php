<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogCompletenessIssueSource;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Shop 1 "Kräuterhaus" with home country AT, one start point de in EUR.
 *
 * Since C-1c the start point says in which currency is sold — the issue
 * "no price" is therefore a question per currency, not per shop.
 */
class DbalCatalogCompletenessIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '')",
        );

        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->root(shop: 1, currency: 'EUR');
    }

    public function testACompleteCatalogueReportsNothing(): void
    {
        $this->product(1, shop: true, price: true);

        self::assertSame([], $this->issues());
    }

    public function testCountsPublishedMainProductsWithoutAShop(): void
    {
        $this->product(1, shop: false, price: true);
        $this->product(2, shop: false, price: false);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::ProductShopMissing, ['count' => '2'])], $this->issues());
    }

    public function testDraftsExpiredProductsAndVariantsDoNotCountAsWithoutShop(): void
    {
        $this->product(1, shop: true, price: true);
        // A variant inherits the shops of its main product.
        $this->insertProduct(['id' => 2, 'pid' => 1, 'published' => 1]);
        $this->insertListPrice(['pid' => 2, 'currency' => 'EUR', 'country' => 'AT', 'net' => '1', 'gross' => '1']);
        // Invisible on purpose.
        $this->insertProduct(['id' => 3, 'published' => 0]);
        $this->insertProduct(['id' => 4, 'published' => 1, 'stop' => (string) self::NOW]);
        $this->insertProduct(['id' => 5, 'published' => 1, 'start' => (string) (self::NOW + 60)]);

        self::assertSame([], $this->issues());
    }

    public function testCountsVisibleProductsWithoutAPriceInTheShopsCurrency(): void
    {
        $this->product(1, shop: true, price: true);
        $this->product(2, shop: true, price: false);
        // A price in a foreign currency does not help the shop.
        $this->product(3, shop: true, price: false);
        $this->insertListPrice(['pid' => 3, 'currency' => 'CHF', 'country' => 'AT', 'net' => '1', 'gross' => '1']);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'EUR', 'count' => '2'])],
            $this->issues(),
        );
    }

    public function testAShopWithoutAHomeCountryIsLeftToItsOwnWarning(): void
    {
        // Without a home country nothing in the shop has a gross — every product
        // would count as unpriced. HomeCountryMissing names the cause, not this issue.
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'fallbackLanguage' => 'de']);
        $this->root(shop: 2, currency: 'EUR');
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 2]);

        self::assertSame([], $this->issues());
    }

    public function testEinShopOhneWaehrungAnSeinenStartpunktenMeldetDieUrsache(): void
    {
        // Without a currency the start point shows nothing at all. Counting every
        // product as unpriced would only name the effect — the shop is
        // reported, just as with the missing home country.
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->root(shop: 2, currency: '');
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 2]);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::CurrencyMissing, ['shop' => 'Zweitshop'], 2)],
            $this->issues(),
        );
    }

    public function testEinShopGanzOhneStartpunkt(): void
    {
        // Nobody has assigned it a start point — then too it sells
        // in no currency. RootWithoutCurrency does not apply here: there is
        // no page that could be named.
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'fallbackLanguage' => 'de', 'country' => 'AT']);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::CurrencyMissing, ['shop' => 'Zweitshop'], 2)],
            $this->issues(),
        );
    }

    public function testOhneHeimatlandBleibtAuchDerWaehrungsbefundAus(): void
    {
        // A freshly created shop has neither the one nor the other. Two
        // warnings for one state are one too many — the home country comes
        // first, and HomeCountryMissing names it.
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'fallbackLanguage' => 'de']);

        self::assertSame([], $this->issues());
    }

    public function testDerWaehrungsbefundIstEineWarnung(): void
    {
        // As with the missing home country: the shop sells nothing, there is
        // nothing to weigh and nothing to click away.
        self::assertTrue(ConfigurationFinding::CurrencyMissing->isWarning());
    }

    public function testJedeWaehrungDesShopsWirdEinzelnGezaehlt(): void
    {
        // A second start point in pounds: the same invoicing party, a second
        // market. The product only has a euro price; in GBP it is missing.
        $this->root(shop: 1, currency: 'GBP');
        $this->product(1, shop: true, price: true);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'GBP', 'count' => '1'])],
            $this->issues(),
        );
    }

    public function testDieBefundeStehenNachWaehrungSortiertUndNichtNachStartpunkt(): void
    {
        // The English start point is in GBP, the German one in EUR — sorted by
        // start point, GBP would come first. The order of the issues on
        // the backend start page must not depend on what the start points
        // are called in the page tree.
        $this->connection->update('tl_page', ['consho_currency' => 'GBP'], ['language' => 'de']);
        $this->root(shop: 1, currency: 'EUR', language: 'en');
        $this->product(1, shop: true, price: false);

        self::assertEquals(
            [
                new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'EUR', 'count' => '1']),
                new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'GBP', 'count' => '1']),
            ],
            $this->issues(),
        );
    }

    public function testEinZweiterStartpunktDerselbenWaehrungZaehltNichtDoppelt(): void
    {
        // Bilingual shop, both start points in euros — one market, one issue.
        $this->root(shop: 1, currency: 'EUR', language: 'en');
        $this->product(1, shop: true, price: false);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'EUR', 'count' => '1'])],
            $this->issues(),
        );
    }

    public function testAPriceOnAVariantCounts(): void
    {
        // With variants the prices sit on the variants (A-4b, E2).
        $this->product(1, shop: true, price: false);
        $this->insertProduct(['id' => 2, 'pid' => 1, 'published' => 1]);
        $this->insertListPrice(['pid' => 2, 'currency' => 'EUR', 'country' => 'AT', 'net' => '1', 'gross' => '1']);

        self::assertSame([], $this->issues());
    }

    public function testTheVisibilityProjectionDoesNotEnterTheCount(): void
    {
        // B-10: the diagnosis describes the source data. The projection stays
        // empty here — computed from it, 2 instead of 1 would be shown without a
        // price, and an outdated state would appear as a missing price.
        $this->product(1, shop: true, price: true);
        $this->product(2, shop: true, price: false);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'EUR', 'count' => '1'])],
            $this->issues(),
        );
    }

    public function testBothAreNotesThatCanBeAcknowledged(): void
    {
        // A product only for a link to regular customers can be intended.
        self::assertFalse(ConfigurationFinding::ProductShopMissing->isWarning());
        self::assertFalse(ConfigurationFinding::ProductPriceMissing->isWarning());
    }

    private function root(int $shop, string $currency, string $language = 'de'): void
    {
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => $shop, 'consho_currency' => $currency, 'language' => $language]);
    }

    private function product(int $id, bool $shop, bool $price): void
    {
        $this->insertProduct(['id' => $id, 'published' => 1]);

        if ($shop) {
            $this->insertProductShop(['pid' => $id, 'shop' => 1]);
        }

        if ($price) {
            $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10', 'gross' => '12']);
        }
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        $criteria = new VisibilityCriteria();

        return (new DbalCatalogCompletenessIssueSource(
            $this->connection,
            $criteria,
            new VisibilityRows($criteria),
            new ShopLanguages($this->connection),
            static fn (): int => self::NOW,
        ))->issues();
    }
}
