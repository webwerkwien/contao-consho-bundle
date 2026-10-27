<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
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
 * Block H, H-1, end to end: the promotion comes through the real price table —
 * the repository must deliver the period along with it, otherwise every row is "standing"
 * and the tie is random again. And the projection (filter, sorting,
 * tile) shows the promotional price as long as it applies.
 */
class PromotionEndToEndTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_price', 'tl_consho_product_list_price', 'tl_consho_projection_state', 'tl_consho_price_history'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'title' => 'Essig', 'taxClass' => 1]);

        // The promotion created first — without the period in the repository it would win
        // as the first row even if the rule were missing.
        $this->price('8.0000', self::NOW - 100, self::NOW + 100);
        $this->price('10.0000');
    }

    public function testDerWarenkorbBekommtDenAktionspreisMitHerkunft(): void
    {
        $price = $this->resolver()->forCartLine(1, 1, 'EUR', '1', self::NOW);

        self::assertNotNull($price);
        self::assertSame(8.0, (float) $price->unitNet);
        self::assertSame(PriceSource::Promotion, $price->source);
    }

    public function testNachDemEndeGiltWiederDerListenpreis(): void
    {
        $price = $this->resolver()->forCartLine(1, 1, 'EUR', '1', self::NOW + 100);

        self::assertNotNull($price);
        self::assertSame(10.0, (float) $price->unitNet);
        self::assertSame(PriceSource::List, $price->source);
    }

    public function testDieProjektionZeigtDenAktionspreisSolangeErGilt(): void
    {
        $this->projector()->rebuild(self::NOW);
        self::assertSame(9.6, (float) $this->connection->fetchOne('SELECT gross FROM tl_consho_product_list_price WHERE pid = 1'));

        self::assertSame(1, $this->projector()->refresh([1], self::NOW + 100));
        self::assertSame(12.0, (float) $this->connection->fetchOne('SELECT gross FROM tl_consho_product_list_price WHERE pid = 1'));
    }

    /**
     * H-2: the projector writes the history and puts the reference price on the
     * row — before the promotion it was €10 (gross €12).
     */
    public function testDieProjektionTraegtDenNiedrigstenPreisVorDerAktion(): void
    {
        $this->projector()->refresh([1], self::NOW - 40 * 86400);
        self::assertNull($this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));

        $this->projector()->refresh([1], self::NOW);

        self::assertSame([9.6, 12.0, 10.0], array_map('floatval', array_values((array) $this->connection->fetchAssociative('SELECT gross, compareGross, compareNet FROM tl_consho_product_list_price WHERE pid = 1'))));

        // After the end: no strike-through price any more.
        $this->projector()->refresh([1], self::NOW + 100);
        self::assertNull($this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));
    }

    /**
     * A strike-through price that appears is a change of the tile — even
     * if the amount stays the same. Otherwise the lists would not be invalidated.
     */
    public function testEinNeuerStreichpreisZaehltAlsAenderung(): void
    {
        // History from before, projection without a strike-through price (as after the seed migration).
        (new PriceHistory($this->connection))->record(1, 'EUR', 'AT', new \Webwerkwien\ContaoConshoBundle\Pricing\ListPrice('10.0000', '12.0000'), self::NOW - 40 * 86400);
        $this->connection->insert('tl_consho_product_list_price', ['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '8.0000', 'gross' => '9.6000']);

        self::assertSame(1, $this->projector()->refresh([1], self::NOW));
        self::assertSame(12.0, (float) $this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));
    }

    /**
     * B: if the price in the 30 days before was already below the promotion,
     * nothing is struck through — the price applies nevertheless.
     */
    public function testEineAktionUeberDemBezugspreisStreichtNichts(): void
    {
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '7.5000', 'validFrom' => self::NOW - 20 * 86400, 'validTo' => self::NOW - 10 * 86400]);

        $this->projector()->refresh([1], self::NOW - 40 * 86400);
        $this->projector()->refresh([1], self::NOW - 20 * 86400);
        $this->projector()->refresh([1], self::NOW - 10 * 86400);
        $this->projector()->refresh([1], self::NOW);

        self::assertSame(9.6, (float) $this->connection->fetchOne('SELECT gross FROM tl_consho_product_list_price WHERE pid = 1'));
        self::assertNull($this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));
    }

    /**
     * A permanent price cut from a date on: the old row ends, the new one
     * begins there, without an end. No row without a period stands next to it —
     * a new price, no promotion, no strike-through price.
     */
    public function testEineGeplanteSenkungMitEndeDerAltenZeileIstKeineAktion(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_price');
        $this->price('10.0000', null, self::NOW);
        $this->price('8.0000', self::NOW);

        $this->projector()->refresh([1], self::NOW - 40 * 86400);
        $this->projector()->refresh([1], self::NOW);

        self::assertSame(9.6, (float) $this->connection->fetchOne('SELECT gross FROM tl_consho_product_list_price WHERE pid = 1'));
        self::assertNull($this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));
        self::assertSame(PriceSource::List, $this->resolver()->forCartLine(1, 1, 'EUR', '1', self::NOW)?->source);
    }

    /**
     * The trap next to it: if the old row without a period stays, the
     * cheaper row with "valid from" is a promotion — with a strike-through price.
     */
    public function testEineBilligereZeileNurMitBeginnNebenDemBasispreisIstEineAktion(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_price');
        $this->price('10.0000');
        $this->price('8.0000', self::NOW);

        $this->projector()->refresh([1], self::NOW - 40 * 86400);
        $this->projector()->refresh([1], self::NOW);

        self::assertSame(12.0, (float) $this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));
        self::assertSame(PriceSource::Promotion, $this->resolver()->forCartLine(1, 1, 'EUR', '1', self::NOW)?->source);
    }

    private function price(string $net, int|null $from = null, int|null $to = null): void
    {
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => $net, 'validFrom' => $from, 'validTo' => $to]);
    }

    private function resolver(): ArticlePriceResolver
    {
        $repository = new DbalPriceRepository($this->connection);
        $taxClasses = $this->createStub(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturn(1);

        return new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator(), $taxClasses);
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
