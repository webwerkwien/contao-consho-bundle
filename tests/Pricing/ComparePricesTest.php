<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\PriceHistorySeedMigration;
use Webwerkwien\ContaoConshoBundle\Pricing\ComparePrices;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\ResolvedPrice;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block H (H-2): the product page reads the strike-through price from the row that
 * the tile reads as well — and only for the price this row describes.
 * Plus the start of the history at the first deployment.
 */
class ComparePricesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product_list_price');
        DcaSchema::create($this->connection, 'tl_consho_price_history');

        $this->connection->insert('tl_consho_product_list_price', ['pid' => 8, 'currency' => 'EUR', 'country' => 'AT', 'net' => '8.0000', 'gross' => '9.6000', 'compareNet' => '10.0000', 'compareGross' => '12.0000']);
        $this->connection->insert('tl_consho_product_list_price', ['pid' => 9, 'currency' => 'EUR', 'country' => 'AT', 'net' => '5.0000', 'gross' => '6.0000']);
    }

    public function testDieAktionFuerAlleBekommtDenStreichpreisDerProjektion(): void
    {
        self::assertSame([10.0, 12.0], array_map('floatval', $this->compare(8, new ResolvedPrice('8.0000', 'EUR', PriceSource::Promotion, '1'))));
    }

    public function testKeinStreichpreisOhneAktion(): void
    {
        self::assertNull($this->compare(8, new ResolvedPrice('8.0000', 'EUR', PriceSource::List, '1')));
    }

    public function testKeinStreichpreisFuerDieAktionEinerGruppe(): void
    {
        self::assertNull($this->compare(8, new ResolvedPrice('8.0000', 'EUR', PriceSource::Promotion, '1', 5)));
    }

    public function testKeinStreichpreisWennDieZahlEineAndereIst(): void
    {
        // Say a promotion from a higher threshold (A) — the projection describes quantity 1.
        self::assertNull($this->compare(8, new ResolvedPrice('7.0000', 'EUR', PriceSource::Promotion, '1')));
    }

    public function testKeinStreichpreisOhneBezugspreisInDerZeile(): void
    {
        self::assertNull($this->compare(9, new ResolvedPrice('5.0000', 'EUR', PriceSource::Promotion, '1')));
    }

    public function testDieMigrationStartetDenVerlaufEinmal(): void
    {
        $migration = new PriceHistorySeedMigration($this->connection);

        self::assertTrue($migration->shouldRun());
        self::assertTrue($migration->run()->isSuccessful());
        self::assertSame(
            [[8, 9.6, 0], [9, 6.0, 0]],
            array_map(static fn (array $r): array => [(int) $r['article'], (float) $r['gross'], (int) $r['promotion']], $this->connection->fetchAllAssociative('SELECT article, gross, promotion FROM tl_consho_price_history ORDER BY article')),
        );
        self::assertFalse($migration->shouldRun(), 'nicht bei jedem Lauf wieder');
    }

    /**
     * @return array{string, string}|null
     */
    private function compare(int $article, ResolvedPrice $shown): array|null
    {
        return (new ComparePrices($this->connection))->for($article, 'EUR', 'AT', $shown);
    }
}
