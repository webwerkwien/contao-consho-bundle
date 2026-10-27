<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;

/**
 * start/stop as varchar(10) with '' for "not set", validFrom/validTo as
 * int NULL — as on a real installation (CatalogSchema).
 */
class DbalCatalogScheduleTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
    }

    public function testNothingScheduledIsNull(): void
    {
        $this->insertProduct(['id' => 1, 'published' => 1]);

        self::assertNull($this->schedule()->nextProductChange(self::NOW));
        self::assertNull($this->schedule()->nextDetailChange(1, self::NOW));
    }

    public function testTheEarliestStartOrStopAheadOfAnyProduct(): void
    {
        $this->insertProduct(['id' => 1, 'published' => 1, 'start' => (string) (self::NOW + 600)]);
        $this->insertProduct(['id' => 2, 'published' => 1, 'stop' => (string) (self::NOW + 300)]);
        // A variant counts as well — it brings its main product into the list.
        $this->insertProduct(['id' => 3, 'pid' => 1, 'published' => 1, 'start' => (string) (self::NOW + 900)]);

        self::assertSame(self::NOW + 300, $this->schedule()->nextProductChange(self::NOW));
    }

    public function testPastMomentsAndUnpublishedProductsDoNotCount(): void
    {
        $this->insertProduct(['id' => 1, 'published' => 1, 'start' => (string) (self::NOW - 60), 'stop' => (string) self::NOW]);
        // Switched off, it does not appear at the start either.
        $this->insertProduct(['id' => 2, 'published' => 0, 'start' => (string) (self::NOW + 60)]);

        self::assertNull($this->schedule()->nextProductChange(self::NOW));
    }

    public function testTheDetailPageFollowsItsVariantsAndPrices(): void
    {
        $this->insertProduct(['id' => 1, 'published' => 1, 'stop' => (string) (self::NOW + 900)]);
        $this->insertProduct(['id' => 2, 'pid' => 1, 'published' => 1, 'start' => (string) (self::NOW + 600)]);
        $this->insertPrice(['pid' => 2, 'currency' => 'EUR', 'validFrom' => self::NOW + 300]);

        self::assertSame(self::NOW + 300, $this->schedule()->nextDetailChange(1, self::NOW));
    }

    public function testAPriceEndingCountsAsWell(): void
    {
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'validFrom' => self::NOW - 600, 'validTo' => self::NOW + 120]);

        self::assertSame(self::NOW + 120, $this->schedule()->nextDetailChange(1, self::NOW));
    }

    public function testAnotherProductsMomentsDoNotShortenThePage(): void
    {
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertProduct(['id' => 5, 'published' => 1, 'start' => (string) (self::NOW + 60)]);
        $this->insertProduct(['id' => 6, 'pid' => 5, 'published' => 1, 'start' => (string) (self::NOW + 60)]);
        $this->insertPrice(['pid' => 5, 'currency' => 'EUR', 'validFrom' => self::NOW + 60]);

        self::assertNull($this->schedule()->nextDetailChange(1, self::NOW));
    }

    public function testAnUnpublishedVariantsStartDoesNotCountButItsPriceDoes(): void
    {
        // Without publication the variant does not appear at the start; its price
        // is in the table anyway and changes nothing visible — but it
        // only shortens, it never falsifies.
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertProduct(['id' => 2, 'pid' => 1, 'published' => 0, 'start' => (string) (self::NOW + 60)]);

        self::assertNull($this->schedule()->nextDetailChange(1, self::NOW));
    }

    public function testAnUnknownProductHasNoSchedule(): void
    {
        self::assertNull($this->schedule()->nextDetailChange(99, self::NOW));
    }

    public function testEineNieGespeichertePreiszeileVerkuerztDieSeiteNicht(): void
    {
        // tstamp 0: the price mask was opened but never saved — unlike
        // testTheDetailPageFollowsItsVariantsAndPrices, where the row exists.
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'validFrom' => self::NOW + 300, 'tstamp' => 0]);

        self::assertNull($this->schedule()->nextDetailChange(1, self::NOW));
    }

    private function schedule(): DbalCatalogSchedule
    {
        return new DbalCatalogSchedule($this->connection);
    }
}
