<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalMarketRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Seit C-1c kommt das Marktpaar aus dem Verbund Startpunkt → Shop: die Währung
 * vom Startpunkt, das Heimatland vom Shop (Spezifikation 4.0). Vorher las diese
 * Klasse beides aus `tl_consho_shop` — ein Shop war damit genau ein Markt.
 *
 * Die Wirkung des fehlenden `published`-Filters — die Preismaske nimmt eine
 * Währung an, die nur ein unveröffentlichter Startpunkt führt — steht in
 * PriceInputListenerTest, wo die Maske zu Hause ist.
 */
class DbalMarketRepositoryTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
    }

    public function testJedeWaehrungEinesStartpunktsErgibtMitDemHeimatlandDesShopsEinenMarkt(): void
    {
        // Ein Shop, zwei Startpunkte: die österreichische Firma verkauft über
        // /en/ in Pfund. Das ist EIN Rechnungssteller und trotzdem zwei Märkte.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'en', 1, 'GBP');

        self::assertEquals([new Market('EUR', 'AT'), new Market('GBP', 'AT')], $this->markets());
    }

    public function testZweiStartpunkteDerselbenWaehrungSindEinMarkt(): void
    {
        // Der Regelfall eines zweisprachigen Shops: de und en, beide in Euro.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'en', 1, 'EUR');

        self::assertEquals([new Market('EUR', 'AT')], $this->markets());
    }

    public function testEinStartpunktOhneWaehrungBringtKeinenMarktUndNimmtKeinenWeg(): void
    {
        // Er kann nichts anzeigen — ein Preis ohne Währung ist keine Angabe.
        // Die übrigen Startpunkte desselben Shops bleiben davon unberührt.
        // Gemeldet wird er auf der Backend-Startseite als RootWithoutCurrency
        // (DbalShopScopeIssueSource) — hier fällt er nur still aus den Märkten,
        // und genau das ist die gewollte Lautstärke.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'en', 1, '');

        self::assertEquals([new Market('EUR', 'AT')], $this->markets());
    }

    public function testOhneHeimatlandKeinMarkt(): void
    {
        // Es gibt nichts, womit ein Brutto zu rechnen wäre.
        $this->insertShop(['id' => 1, 'country' => '']);
        $this->insertRootPage(1, 'de', 1, 'EUR');

        self::assertSame([], $this->markets());
    }

    public function testDieselbeWaehrungAusZweiLaendernSindZweiMaerkte(): void
    {
        // C25: gleicher Nettopreis, zwei Steuersätze, zwei Brutto.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertShop(['id' => 2, 'country' => 'DE']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'de', 2, 'EUR');

        self::assertEquals([new Market('EUR', 'AT'), new Market('EUR', 'DE')], $this->markets());
    }

    public function testEineSeiteDieKeinStartpunktIstZaehltNicht(): void
    {
        // consho_currency steht auf jeder Seite. Ein Restwert auf einer Seite,
        // die einmal Startpunkt war, darf kein Verkaufssignal werden — dieselbe
        // Bedingung wie in RootCurrencyMigration.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'regular', 'consho_shop' => 1, 'consho_currency' => 'EUR']);

        self::assertSame([], $this->markets());
    }

    public function testEinStartpunktOhneShopZaehltNicht(): void
    {
        // Eine fremde Website in derselben Installation.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'consho_shop' => 0, 'consho_currency' => 'EUR']);

        self::assertSame([], $this->markets());
    }

    public function testEinStartpunktMitGeloeschtemShopZaehltNicht(): void
    {
        // Der Verbund ist ein INNER JOIN: ohne Shop gibt es kein Heimatland
        // und damit kein Brutto.
        $this->insertRootPage(1, 'de', 404, 'EUR');

        self::assertSame([], $this->markets());
    }

    public function testDieMaerkteStehenNachWaehrungUndLandSortiert(): void
    {
        $this->insertShop(['id' => 1, 'country' => 'DE']);
        $this->insertShop(['id' => 2, 'country' => 'AT']);
        $this->insertShop(['id' => 3, 'country' => 'CH']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'de', 2, 'EUR');
        $this->insertRootPage(3, 'de', 3, 'CHF');

        self::assertEquals([new Market('CHF', 'CH'), new Market('EUR', 'AT'), new Market('EUR', 'DE')], $this->markets());
    }

    public function testEinUnveroeffentlichterStartpunktBringtSeinenMarktTrotzdem(): void
    {
        // 🎯 So wird eine Währung vorbereitet, bevor sie jemand sieht: Startpunkt
        // anlegen, GBP wählen, Preise pflegen, dann veröffentlichen. Ein
        // published-Filter nähme die GBP-Preiszeilen aus der Projektion, sobald
        // jemand den Startpunkt depubliziert — derselbe Fehler, den Block A bei
        // den abgeleiteten Lieferländern schon einmal gemacht und verworfen hat
        // (docs/block-a-fundament.md, Abschnitt 7).
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'language' => 'en', 'consho_shop' => 1, 'consho_currency' => 'GBP', 'published' => 0]);

        self::assertEquals([new Market('EUR', 'AT'), new Market('GBP', 'AT')], $this->markets());
    }

    /**
     * @return list<Market>
     */
    private function markets(): array
    {
        return (new DbalMarketRepository($this->connection))->findMarkets();
    }
}
