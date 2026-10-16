<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;

/**
 * start/stop als varchar(10) mit '' für „nicht gesetzt", validFrom/validTo als
 * int NULL — wie auf c5 (CatalogSchema).
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
        // Eine Variante zählt ebenso — sie bringt ihr Hauptprodukt in die Liste.
        $this->insertProduct(['id' => 3, 'pid' => 1, 'published' => 1, 'start' => (string) (self::NOW + 900)]);

        self::assertSame(self::NOW + 300, $this->schedule()->nextProductChange(self::NOW));
    }

    public function testPastMomentsAndUnpublishedProductsDoNotCount(): void
    {
        $this->insertProduct(['id' => 1, 'published' => 1, 'start' => (string) (self::NOW - 60), 'stop' => (string) self::NOW]);
        // Ausgeschaltet erscheint es auch zum Start nicht.
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
        // Ohne Veröffentlichung erscheint die Variante zum Start nicht; ihr Preis
        // steht trotzdem in der Tabelle und ändert nichts Sichtbares — aber er
        // verkürzt nur, er verfälscht nie.
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
        // tstamp 0: die Preismaske wurde geöffnet, aber nie gespeichert — anders
        // als testTheDetailPageFollowsItsVariantsAndPrices, wo die Zeile steht.
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'validFrom' => self::NOW + 300, 'tstamp' => 0]);

        self::assertNull($this->schedule()->nextDetailChange(1, self::NOW));
    }

    private function schedule(): DbalCatalogSchedule
    {
        return new DbalCatalogSchedule($this->connection);
    }
}
