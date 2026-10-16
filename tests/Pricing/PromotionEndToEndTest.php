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
 * Block H, H-1, Ende zu Ende: Die Aktion kommt durch die echte Preistabelle —
 * das Repository muss den Zeitraum mitliefern, sonst ist jede Zeile „stehend"
 * und der Gleichstand wieder zufällig. Und die Projektion (Filter, Sortierung,
 * Kachel) zeigt den Aktionspreis, solange er gilt.
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

        // Die Aktion zuerst angelegt — ohne Zeitraum im Repository gewänne sie
        // als erste Zeile auch dann, wenn die Regel fehlte.
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
     * H-2: Der Projektor schreibt den Verlauf und legt den Bezugspreis an die
     * Zeile — vor der Aktion stand 10 € (brutto 12 €).
     */
    public function testDieProjektionTraegtDenNiedrigstenPreisVorDerAktion(): void
    {
        $this->projector()->refresh([1], self::NOW - 40 * 86400);
        self::assertNull($this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));

        $this->projector()->refresh([1], self::NOW);

        self::assertSame([9.6, 12.0, 10.0], array_map('floatval', array_values((array) $this->connection->fetchAssociative('SELECT gross, compareGross, compareNet FROM tl_consho_product_list_price WHERE pid = 1'))));

        // Nach dem Ende: kein Streichpreis mehr.
        $this->projector()->refresh([1], self::NOW + 100);
        self::assertNull($this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));
    }

    /**
     * Ein Streichpreis, der hinzukommt, ist eine Änderung der Kachel — auch
     * wenn der Betrag gleich bleibt. Sonst würden die Listen nicht entwertet.
     */
    public function testEinNeuerStreichpreisZaehltAlsAenderung(): void
    {
        // Verlauf von früher, Projektion ohne Streichpreis (wie nach der Seed-Migration).
        (new PriceHistory($this->connection))->record(1, 'EUR', 'AT', new \Webwerkwien\ContaoConshoBundle\Pricing\ListPrice('10.0000', '12.0000'), self::NOW - 40 * 86400);
        $this->connection->insert('tl_consho_product_list_price', ['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '8.0000', 'gross' => '9.6000']);

        self::assertSame(1, $this->projector()->refresh([1], self::NOW));
        self::assertSame(12.0, (float) $this->connection->fetchOne('SELECT compareGross FROM tl_consho_product_list_price WHERE pid = 1'));
    }

    /**
     * B: Lag der Preis in den 30 Tagen davor schon unter der Aktion, wird
     * nichts gestrichen — der Preis gilt trotzdem.
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
