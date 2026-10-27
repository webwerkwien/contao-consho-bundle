<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\CatalogPriceRules;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceHistory;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

/**
 * Block H, H-3, end to end: a catalogue rule reaches, via the resolver,
 * cart, scale, projection and strike-through price — and not the subscription.
 */
class CatalogRuleEndToEndTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_property', 'tl_consho_price', 'tl_consho_price_rule', 'tl_consho_product_list_price', 'tl_consho_projection_state', 'tl_consho_price_history', 'tl_consho_product_type'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'title' => 'Essig', 'taxClass' => 1, 'manufacturer' => 3]);
        $this->price('10.0000');
        $this->price('9.0000', '10.0000');
        $this->price('1.0000', rule: 4);

        $this->connection->insert('tl_consho_price_rule', ['tstamp' => 1, 'title' => 'Herstellerwoche', 'published' => 1, 'percent' => '20', 'manufacturers' => serialize(['3']), 'validFrom' => self::NOW - 100]);
    }

    public function testWarenkorbUndStaffel(): void
    {
        $line = $this->resolver()->forCartLine(1, 1, 'EUR', '10', self::NOW);
        self::assertNotNull($line);
        self::assertSame(7.2, (float) $line->unitNet);
        self::assertSame(PriceSource::Promotion, $line->source);

        $article = $this->resolver()->forArticle(1, 1, 'EUR', 'AT', self::NOW, ['unit' => '', 'purchaseUnit' => '', 'referenceUnit' => '']);
        self::assertNotNull($article);
        self::assertSame([8.0, 7.2], array_map(static fn ($step): float => (float) $step->price->net, $article->scale), 'jede Stufe mit der Regel');
    }

    public function testDasAboBleibtUnberuehrt(): void
    {
        $line = $this->resolver()->forCartLine(1, 1, 'EUR', '1', self::NOW, [], 4);

        self::assertNotNull($line);
        self::assertSame(1.0, (float) $line->unitNet);
        self::assertSame(PriceSource::List, $line->source);
    }

    public function testProjektionUndStreichpreis(): void
    {
        $this->projector()->refresh([1], self::NOW - 40 * 86400);
        $this->projector()->refresh([1], self::NOW);

        self::assertSame([9.6, 12.0], array_map('floatval', array_values((array) $this->connection->fetchAssociative('SELECT gross, compareGross FROM tl_consho_product_list_price WHERE pid = 1'))));
    }

    private function price(string $net, string $threshold = '1.0000', int $rule = 0): void
    {
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => $net, 'minQuantity' => $threshold, 'subscriptionRule' => $rule]);
    }

    private function resolver(): ArticlePriceResolver
    {
        $repository = new DbalPriceRepository($this->connection);
        $taxClasses = $this->createStub(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturn(1);

        return new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator(), $taxClasses, null, new CatalogPriceRules($this->connection));
    }

    private function projector(): ListPriceProjector
    {
        $articles = $this->createStub(ArticleTreeInterface::class);
        $articles->method('articlesOf')->willReturnCallback(static fn (array $ids): array => array_combine($ids, $ids));

        $markets = $this->createStub(MarketRepositoryInterface::class);
        $markets->method('findMarkets')->willReturn([new Market('EUR', 'AT')]);

        return new ListPriceProjector(new DbalPriceRepository($this->connection), $this->resolver(), new SqliteProjectionWriter($this->connection), $articles, $markets, new PriceHistory($this->connection));
    }
}
