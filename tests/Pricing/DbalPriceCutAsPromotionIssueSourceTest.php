<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceCutAsPromotionIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Eine billigere Zeile mit Beginn und ohne Ende neben dem Basispreis zeigt
 * PriceCalculator dauerhaft als Aktion — gemeint ist meist eine Senkung.
 * Gemeldet wird nur die Zeile, die auf Dauer gewinnt (spätester Beginn, kein
 * Gleichstand), und nur wenn sie billiger ist als jeder Basispreis.
 */
class DbalPriceCutAsPromotionIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
    }

    public function testBilligereZeileOhneEndeNebenDemBasispreis(): void
    {
        $this->price(1, '100.0000');
        $this->price(1, '80.0000', validFrom: 1000);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::PriceCutAsPromotion, ['count' => '1'])], $this->issues());
    }

    public function testDerRichtigeWegMeldetNichts(): void
    {
        // Alte Zeile mit „Gültig bis", neue mit „Gültig ab": kein Basispreis
        // ohne Zeitraum mehr, also keine Aktion.
        $this->price(1, '100.0000', validTo: 1000);
        $this->price(1, '80.0000', validFrom: 1000);

        self::assertSame([], $this->issues());
    }

    public function testBefristeteAktionMeldetNichts(): void
    {
        $this->price(1, '100.0000');
        $this->price(1, '80.0000', validFrom: 1000, validTo: 2000);

        self::assertSame([], $this->issues());
    }

    public function testTeurereZeileOhneEndeIstEinNeuerPreis(): void
    {
        // B: teurer ist sie der neue Preis, keine Aktion.
        $this->price(1, '100.0000');
        $this->price(1, '120.0000', validFrom: 1000);

        self::assertSame([], $this->issues());
    }

    public function testSpaetereTeurereZeileOhneEndeGewinntAufDauer(): void
    {
        // Review 0.1.13: H2 — die später beginnende Zeile gewinnt; sie ist
        // teurer, also bleibt auf Dauer kein Streichpreis.
        $this->price(1, '100.0000');
        $this->price(1, '80.0000', validFrom: 1000);
        $this->price(1, '120.0000', validFrom: 2000);

        self::assertSame([], $this->issues());
    }

    public function testSpaetereBilligereZeileOhneEndeWirdGemeldet(): void
    {
        $this->price(1, '100.0000');
        $this->price(1, '120.0000', validFrom: 1000);
        $this->price(1, '80.0000', validFrom: 2000);

        self::assertCount(1, $this->issues());
    }

    public function testGleicherBeginnIstEinGleichstandUndKeinHinweis(): void
    {
        // Welche Zeile gewinnt, ist dann offen — das meldet priceTie.
        $this->price(1, '100.0000');
        $this->price(1, '120.0000', validFrom: 1000);
        $this->price(1, '80.0000', validFrom: 1000);

        self::assertSame([], $this->issues());
    }

    public function testNurBilligerAlsEinerVonZweiBasispreisenZaehltNicht(): void
    {
        // winnerOf() vergleicht mit dem ersten Basispreis; welcher das ist,
        // ist bei zweien offen (priceTie). Kein Rat zu einer Änderung.
        $this->price(1, '100.0000');
        $this->price(1, '70.0000');
        $this->price(1, '80.0000', validFrom: 1000);

        self::assertSame([], $this->issues());
    }

    public function testAndererRangZaehltNicht(): void
    {
        $this->price(1, '100.0000');
        $this->price(1, '80.0000', validFrom: 1000, currency: 'CHF');
        $this->price(1, '80.0000', validFrom: 1000, customerGroup: 3);
        $this->price(1, '80.0000', validFrom: 1000, minQuantity: '10');
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '80.0000', 'validFrom' => 1000, 'subscriptionRule' => 5]);

        self::assertSame([], $this->issues());
    }

    public function testNieGespeicherteZeileZaehltNicht(): void
    {
        $this->price(1, '100.0000');
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '80.0000', 'validFrom' => 1000, 'tstamp' => 0]);

        self::assertSame([], $this->issues());
    }

    public function testZaehltArtikelNichtZeilen(): void
    {
        $this->price(1, '100.0000');
        $this->price(1, '80.0000', validFrom: 1000);
        $this->price(1, '70.0000', validFrom: 2000);
        $this->price(2, '50.0000');
        $this->price(2, '40.0000', validFrom: 1000);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::PriceCutAsPromotion, ['count' => '2'])], $this->issues());
    }

    public function testEsIstEinHinweis(): void
    {
        self::assertFalse(ConfigurationFinding::PriceCutAsPromotion->isWarning());
    }

    private function price(int $article, string $net, int|null $validFrom = null, int|null $validTo = null, string $currency = 'EUR', int $customerGroup = 0, string $minQuantity = '1'): void
    {
        $this->insertPrice([
            'pid' => $article,
            'net' => $net,
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
        return (new DbalPriceCutAsPromotionIssueSource($this->connection))->issues();
    }
}
