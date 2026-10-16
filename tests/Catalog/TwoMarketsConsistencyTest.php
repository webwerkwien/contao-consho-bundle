<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalProductTileRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTree;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalMarketRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Pricing\SqliteProjectionWriter;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * M-4 (Gesamtprüfung Plan C-1a): zwei Euro-Shops mit verschiedenem Heimatland
 * — Kachel und Detailseite müssen je Shop übereinstimmen (wie
 * FromPriceConsistencyTest), UND zwischen den Shops darf sich das Brutto
 * unterscheiden, obwohl der Nettopreis derselbe ist (C25). Ein einzelner Shop
 * kann diesen Fall nicht zeigen — deshalb eine eigene Klasse statt einer
 * weiteren Zeile in FromPriceConsistencyTest::catalogues().
 *
 * Gegenprobe (siehe Auftrag): entfernt man in DbalProductTileRepository die
 * Bedingung `lp.country = :consho_home_country`, zählt die Kachel-Unterfrage
 * die Listenpreiszeilen BEIDER Länder zusammen — zwei unterschiedliche Brutto
 * je Variante statt eines — und meldet fälschlich "ab", der Test wird rot.
 */
class TwoMarketsConsistencyTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private const SHOP_AT = 1;

    private const SHOP_DE = 2;

    private const NOW = 1_757_000_000;

    private const NO_UNITS = ['unit' => '', 'purchaseUnit' => '0.0000', 'referenceUnit' => '0.0000'];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);

        $this->insertShop(['id' => self::SHOP_AT, 'title' => 'AT-Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertShop(['id' => self::SHOP_DE, 'title' => 'DE-Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'DE']);
        $this->insertRootPage(1, 'de', self::SHOP_AT);
        $this->insertRootPage(2, 'de', self::SHOP_DE);
    }

    public function testTileAndProductPageAgreePerShopAndDifferBetweenShops(): void
    {
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1, 'title' => 'Schwedenbitter']);
        $this->insertProductShop(['pid' => 1, 'shop' => self::SHOP_AT]);
        $this->insertProductShop(['pid' => 1, 'shop' => self::SHOP_DE]);
        $this->insertPrice(['pid' => 1, 'net' => '10.0000']);

        $this->insertProduct(['id' => 10, 'pid' => 1, 'published' => 1, 'title' => 'Variante A']);
        $this->insertProduct(['id' => 11, 'pid' => 1, 'published' => 1, 'title' => 'Variante B']);
        $this->insertPrice(['pid' => 10, 'net' => '10.0000']);
        $this->insertPrice(['pid' => 11, 'net' => '10.0000']);

        $prices = new DbalPriceRepository($this->connection);
        $resolver = new ArticlePriceResolver(
            new PriceCalculator($prices),
            $prices,
            new BasePriceCalculator(),
            $this->grossCalculator(['AT' => '20.0000', 'DE' => '19.0000']),
            new DbalArticleTaxClasses($this->connection),
        );

        (new ListPriceProjector($prices, $resolver, new SqliteProjectionWriter($this->connection), new DbalArticleTree($this->connection), new DbalMarketRepository($this->connection)))->rebuild(self::NOW);

        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);

        $tileRepository = new DbalProductTileRepository($this->connection);

        $seenGross = [];

        foreach ([[self::SHOP_AT, 'AT', '12.0000'], [self::SHOP_DE, 'DE', '11.9000']] as [$shopId, $country, $expectedGross]) {
            $tile = $tileRepository->forIds([1], 'EUR', $country, false, $shopId, 'de', 'de')[0];

            $pagePrices = array_values(array_filter(array_map(
                static fn (int $id): ArticlePrice|null => $resolver->forArticle($id, 1, 'EUR', $country, self::NOW, self::NO_UNITS),
                [10, 11],
            )));

            [$cheapest, $differs] = ArticlePriceResolver::fromPrice($pagePrices);

            self::assertNotNull($cheapest, \sprintf('%s: kein Preis auf der Detailseite.', $country));
            self::assertNotSame('', $tile->price, \sprintf('%s: die Kachel hat keinen Preis, die Detailseite schon.', $country));
            self::assertTrue(
                BigDecimal::of($tile->price)->isEqualTo($cheapest->gross),
                \sprintf('%s: Kachel „ab %s", Detailseite „ab %s".', $country, $tile->price, $cheapest->gross),
            );
            self::assertSame($differs, $tile->isFrom, \sprintf('%s: Kachel und Detailseite sind sich uneinig, ob „ab" davorsteht.', $country));

            // SQLite liefert NUMERIC uneinheitlich zurück ('12' statt
            // '12.0000') — Vergleich als Dezimalzahl, wie im übrigen Projekt.
            self::assertTrue(
                BigDecimal::of($tile->price)->isEqualTo($expectedGross),
                \sprintf('%s: erwartetes Brutto %s, tatsächlich %s.', $country, $expectedGross, $tile->price),
            );

            $seenGross[] = $tile->price;
        }

        // 20 % in Österreich, 19 % in Deutschland — derselbe Nettopreis, zwei
        // verschiedene Brutto (C25).
        self::assertFalse(BigDecimal::of($seenGross[0])->isEqualTo($seenGross[1]));
    }
}
