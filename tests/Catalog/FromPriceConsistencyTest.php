<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * Kachel und Detailseite nennen denselben Ab-Preis (A-4b, E2).
 *
 * Geprüft wird gegeneinander, nicht gegen eine erwartete Zahl: Die Kachel liest
 * die Projektion der Sichtbarkeit (B-10), die der echte Projektor aus der
 * Listenpreis-Projektion gebaut hat — und die wiederum der echte Projektor aus
 * tl_consho_price;
 * die Detailseite fragt ArticlePriceResolver je Variante. Weicht eine der beiden
 * Seiten ab, schlägt der Test fehl — auch bei einer Katalogform, an die beim
 * Schreiben niemand gedacht hat, sobald sie hier als Fall steht.
 *
 * Befund 6, der Anlass: Hauptprodukt 10 €, Varianten mit eigenen 15 € und 18 €.
 * Die Kachel sagte „ab 10 €", die Seite „ab 15 €".
 */
class FromPriceConsistencyTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private const SHOP = 1;

    private const NOW = 1_757_000_000;

    private const NO_UNITS = ['unit' => '', 'purchaseUnit' => '0.0000', 'referenceUnit' => '0.0000'];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => self::SHOP, 'title' => 'Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', self::SHOP);
    }

    /**
     * @return iterable<string, array{string|null, list<string|null>}> Preis des Hauptprodukts, eigene Preise der Varianten (null = erbt)
     */
    public static function catalogues(): iterable
    {
        yield 'Befund 6: Hauptprodukt billiger als jede Variante' => ['10.0000', ['15.0000', '18.0000']];
        yield 'alle Varianten erben' => ['12.0000', [null, null]];
        yield 'eine erbt, eine hat einen eigenen, günstigeren Preis' => ['12.0000', ['9.0000', null]];
        yield 'eine erbt, eine hat einen eigenen, teureren Preis' => ['12.0000', ['19.0000', null]];
        yield 'nur die Varianten haben Preise' => [null, ['25.0000', '12.6000']];
        yield 'ohne Varianten' => ['12.0000', []];
    }

    /**
     * @param list<string|null> $variantGross
     */
    #[DataProvider('catalogues')]
    public function testTileAndProductPageNameTheSameFromPrice(string|null $mainGross, array $variantGross): void
    {
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1, 'title' => 'Schwedenbitter']);
        $this->insertProductShop(['pid' => 1, 'shop' => self::SHOP]);

        if (null !== $mainGross) {
            $this->insertPrice(['pid' => 1, 'net' => $mainGross]);
        }

        // Die Artikel, die die Detailseite fragt: die Varianten, ohne Varianten das Produkt selbst.
        $articles = [];

        foreach ($variantGross as $i => $gross) {
            $id = 10 + $i;
            $this->insertProduct(['id' => $id, 'pid' => 1, 'published' => 1, 'title' => 'Variante '.$id]);

            if (null !== $gross) {
                $this->insertPrice(['pid' => $id, 'net' => $gross]);
            }

            $articles[] = $id;
        }

        if ([] === $articles) {
            $articles = [1];
        }

        $prices = new DbalPriceRepository($this->connection);
        $resolver = new ArticlePriceResolver(new PriceCalculator($prices), $prices, new BasePriceCalculator(), $this->grossCalculator(['AT' => '0.0000']), new DbalArticleTaxClasses($this->connection));

        (new ListPriceProjector($prices, $resolver, new SqliteProjectionWriter($this->connection), new DbalArticleTree($this->connection), new DbalMarketRepository($this->connection)))->rebuild(self::NOW);

        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);

        $tile = (new DbalProductTileRepository($this->connection))
            ->forIds([1], 'EUR', 'AT', false, self::SHOP, 'de', 'de')[0]
        ;

        $pagePrices = array_values(array_filter(array_map(
            static fn (int $id): ArticlePrice|null => $resolver->forArticle($id, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS),
            $articles,
        )));

        [$cheapest, $differs] = ArticlePriceResolver::fromPrice($pagePrices);

        self::assertNotNull($cheapest);
        self::assertNotSame('', $tile->price, 'Die Kachel hat keinen Preis, die Detailseite schon.');
        self::assertTrue(
            BigDecimal::of($tile->price)->isEqualTo($cheapest->gross),
            \sprintf('Kachel „ab %s", Detailseite „ab %s".', $tile->price, $cheapest->gross),
        );
        self::assertSame($differs, $tile->isFrom, 'Kachel und Detailseite sind sich uneinig, ob „ab" davorsteht.');
    }

}
