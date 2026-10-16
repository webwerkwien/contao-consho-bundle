<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceTieIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

class DbalPriceTieIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
    }

    public function testOrdinaryPriceSetsAreNoTie(): void
    {
        // Staffel, Kundengruppe, andere Währung: jeweils ein anderer Rang.
        $this->price(1);
        $this->price(1, minQuantity: '10');
        $this->price(1, customerGroup: 3);
        $this->price(1, currency: 'CHF');

        self::assertSame([], $this->issues());
    }

    public function testZeilenVerschiedenerAboregelnSindKeinGleichstand(): void
    {
        // Abo-1 (A2): Einmalkauf, Monats- und Jahresabo sind drei Verträge.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR']);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'subscriptionRule' => 5]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'subscriptionRule' => 9]);

        self::assertSame([], $this->issues());
    }

    public function testZweiZeilenDerselbenAboregelSindEinGleichstand(): void
    {
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'subscriptionRule' => 5]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'subscriptionRule' => 5]);

        self::assertCount(1, $this->issues());
    }

    public function testTwoOpenRowsOfEqualRankAreATie(): void
    {
        $this->price(1);
        $this->price(1);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::PriceTie, ['count' => '1'])], $this->issues());
    }

    public function testEinBefristeterPreisNebenDemRegulaerenIstSeitBlockHKeinGleichstand(): void
    {
        // Der Fall aus der B-5-Recherche: „Aktionspreis" als befristete
        // Listenpreis-Zeile. Bis Block H ein Gleichstand, seit H2 entscheidet
        // PriceCalculator ihn (mit Zeitraum vor ohne).
        $this->price(1);
        $this->price(1, validFrom: 1000, validTo: 2000);

        self::assertSame([], $this->issues());
    }

    public function testZweiBefristeteZeilenMitVerschiedenemBeginnSindKeinGleichstand(): void
    {
        // H2: die später beginnende gewinnt.
        $this->price(1, validFrom: 500, validTo: 1500);
        $this->price(1, validFrom: 1000, validTo: 2000);

        self::assertSame([], $this->issues());
    }

    public function testZweiBefristeteZeilenMitGleichemBeginnBleibenEinGleichstand(): void
    {
        $this->price(1, validFrom: 1000, validTo: 2000);
        $this->price(1, validFrom: 1000, validTo: 3000);

        self::assertCount(1, $this->issues());
    }

    public function testZweiZeilenNurMitEndeBleibenEinGleichstand(): void
    {
        // Beide befristet, keine mit Beginn: Der spätere Beginn entscheidet nichts.
        $this->price(1, validTo: 2000);
        $this->price(1, validTo: 3000);

        self::assertCount(1, $this->issues());
    }

    public function testConsecutiveWindowsDoNotOverlap(): void
    {
        // Bis ausschließlich, ab einschließlich — wie DbalPriceRepository.
        $this->price(1, validTo: 1000);
        $this->price(1, validFrom: 1000, validTo: 2000);
        $this->price(1, validFrom: 2000);

        self::assertSame([], $this->issues());
    }

    public function testConsecutiveWindowsDoNotOverlapInEitherOrder(): void
    {
        // Die spätere Zeile zuerst angelegt: Dann prüft die erste Hälfte der
        // Bedingung die Grenze (a.validFrom < b.validTo), nicht die zweite.
        $this->price(1, validFrom: 2000);
        $this->price(1, validFrom: 1000, validTo: 2000);

        self::assertSame([], $this->issues());
    }

    public function testCountsArticlesNotPairs(): void
    {
        $this->price(1);
        $this->price(1);
        $this->price(1);
        $this->price(2, validFrom: 1000, validTo: 1500);
        $this->price(2, validFrom: 1000, validTo: 2000);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::PriceTie, ['count' => '2'])], $this->issues());
    }

    public function testItIsANote(): void
    {
        // Die Regel kommt mit Block H; bis dahin kann ein Betreiber es wissen und hinnehmen.
        self::assertFalse(ConfigurationFinding::PriceTie->isWarning());
    }

    public function testEineNieGespeichertePreiszeileIstKeinGleichstand(): void
    {
        // Die zweite Zeile wurde geöffnet, aber nie gespeichert (tstamp 0) —
        // anders als testTwoOpenRowsOfEqualRankAreATie, wo beide gespeichert sind.
        $this->price(1);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'customerGroup' => 0, 'minQuantity' => '1', 'tstamp' => 0]);

        self::assertSame([], $this->issues());
    }

    private function price(int $article, string $currency = 'EUR', int $customerGroup = 0, string $minQuantity = '1', int|null $validFrom = null, int|null $validTo = null): void
    {
        $this->insertPrice([
            'pid' => $article,
            'currency' => $currency,
            'customerGroup' => $customerGroup,
            'minQuantity' => $minQuantity,
            'validFrom' => $validFrom,
            'validTo' => $validTo,
        ]);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalPriceTieIssueSource($this->connection))->issues();
    }
}
