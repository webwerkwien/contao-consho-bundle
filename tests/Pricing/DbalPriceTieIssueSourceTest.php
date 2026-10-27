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
        // Scale, customer group, other currency: each a different rank.
        $this->price(1);
        $this->price(1, minQuantity: '10');
        $this->price(1, customerGroup: 3);
        $this->price(1, currency: 'CHF');

        self::assertSame([], $this->issues());
    }

    public function testZeilenVerschiedenerAboregelnSindKeinGleichstand(): void
    {
        // Abo-1 (A2): one-off purchase, monthly and yearly subscription are three contracts.
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
        // The case from the B-5 research: "promotional price" as a time-limited
        // list price row. A tie until Block H; since H2
        // PriceCalculator decides it (with a period before without).
        $this->price(1);
        $this->price(1, validFrom: 1000, validTo: 2000);

        self::assertSame([], $this->issues());
    }

    public function testZweiBefristeteZeilenMitVerschiedenemBeginnSindKeinGleichstand(): void
    {
        // H2: the one starting later wins.
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
        // Both time-limited, neither with a start: the later start decides nothing.
        $this->price(1, validTo: 2000);
        $this->price(1, validTo: 3000);

        self::assertCount(1, $this->issues());
    }

    public function testConsecutiveWindowsDoNotOverlap(): void
    {
        // Until exclusive, from inclusive — like DbalPriceRepository.
        $this->price(1, validTo: 1000);
        $this->price(1, validFrom: 1000, validTo: 2000);
        $this->price(1, validFrom: 2000);

        self::assertSame([], $this->issues());
    }

    public function testConsecutiveWindowsDoNotOverlapInEitherOrder(): void
    {
        // The later row created first: then the first half of the
        // condition checks the bound (a.validFrom < b.validTo), not the second.
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
        // The rule comes with Block H; until then an operator can know it and accept it.
        self::assertFalse(ConfigurationFinding::PriceTie->isWarning());
    }

    public function testEineNieGespeichertePreiszeileIstKeinGleichstand(): void
    {
        // The second row was opened but never saved (tstamp 0) —
        // unlike testTwoOpenRowsOfEqualRankAreATie, where both are saved.
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
