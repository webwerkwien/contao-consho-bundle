<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogCompletenessIssueSource;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Shop 1 „Kräuterhaus" mit Heimatland AT, ein Startpunkt de in EUR.
 *
 * Seit C-1c sagt der Startpunkt, in welcher Währung verkauft wird — der Befund
 * „kein Preis" ist deshalb eine Frage je Währung, nicht je Shop.
 */
class DbalCatalogCompletenessIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '')",
        );

        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->root(shop: 1, currency: 'EUR');
    }

    public function testACompleteCatalogueReportsNothing(): void
    {
        $this->product(1, shop: true, price: true);

        self::assertSame([], $this->issues());
    }

    public function testCountsPublishedMainProductsWithoutAShop(): void
    {
        $this->product(1, shop: false, price: true);
        $this->product(2, shop: false, price: false);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::ProductShopMissing, ['count' => '2'])], $this->issues());
    }

    public function testDraftsExpiredProductsAndVariantsDoNotCountAsWithoutShop(): void
    {
        $this->product(1, shop: true, price: true);
        // Eine Variante erbt die Shops ihres Hauptprodukts.
        $this->insertProduct(['id' => 2, 'pid' => 1, 'published' => 1]);
        $this->insertListPrice(['pid' => 2, 'currency' => 'EUR', 'country' => 'AT', 'net' => '1', 'gross' => '1']);
        // Absichtlich unsichtbar.
        $this->insertProduct(['id' => 3, 'published' => 0]);
        $this->insertProduct(['id' => 4, 'published' => 1, 'stop' => (string) self::NOW]);
        $this->insertProduct(['id' => 5, 'published' => 1, 'start' => (string) (self::NOW + 60)]);

        self::assertSame([], $this->issues());
    }

    public function testCountsVisibleProductsWithoutAPriceInTheShopsCurrency(): void
    {
        $this->product(1, shop: true, price: true);
        $this->product(2, shop: true, price: false);
        // Ein Preis in einer fremden Währung hilft dem Shop nicht.
        $this->product(3, shop: true, price: false);
        $this->insertListPrice(['pid' => 3, 'currency' => 'CHF', 'country' => 'AT', 'net' => '1', 'gross' => '1']);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'EUR', 'count' => '2'])],
            $this->issues(),
        );
    }

    public function testAShopWithoutAHomeCountryIsLeftToItsOwnWarning(): void
    {
        // Ohne Heimatland hat im Shop nichts ein Brutto — jedes Produkt zählte
        // als unbepreist. Die Ursache nennt HomeCountryMissing, nicht dieser Befund.
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'fallbackLanguage' => 'de']);
        $this->root(shop: 2, currency: 'EUR');
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 2]);

        self::assertSame([], $this->issues());
    }

    public function testEinShopOhneWaehrungAnSeinenStartpunktenMeldetDieUrsache(): void
    {
        // Ohne Währung zeigt der Startpunkt gar nichts an. Jedes Produkt als
        // unbepreist zu zählen, nennte nur die Wirkung — gemeldet wird der
        // Shop, genau wie beim fehlenden Heimatland.
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->root(shop: 2, currency: '');
        $this->insertProduct(['id' => 1, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 2]);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::CurrencyMissing, ['shop' => 'Zweitshop'], 2)],
            $this->issues(),
        );
    }

    public function testEinShopGanzOhneStartpunkt(): void
    {
        // Niemand hat ihm einen Startpunkt zugewiesen — auch dann verkauft er
        // in keiner Währung. RootWithoutCurrency greift hier nicht: Es gibt
        // keine Seite, die man beim Namen nennen könnte.
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'fallbackLanguage' => 'de', 'country' => 'AT']);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::CurrencyMissing, ['shop' => 'Zweitshop'], 2)],
            $this->issues(),
        );
    }

    public function testOhneHeimatlandBleibtAuchDerWaehrungsbefundAus(): void
    {
        // Ein frisch angelegter Shop hat weder das eine noch das andere. Zwei
        // Warnungen für einen Zustand sind eine zu viel — das Heimatland kommt
        // zuerst, und HomeCountryMissing nennt es.
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'fallbackLanguage' => 'de']);

        self::assertSame([], $this->issues());
    }

    public function testDerWaehrungsbefundIstEineWarnung(): void
    {
        // Wie beim fehlenden Heimatland: Der Shop verkauft nichts, da ist
        // nichts abzuwägen und nichts wegzuklicken.
        self::assertTrue(ConfigurationFinding::CurrencyMissing->isWarning());
    }

    public function testJedeWaehrungDesShopsWirdEinzelnGezaehlt(): void
    {
        // Ein zweiter Startpunkt in Pfund: derselbe Rechnungssteller, zweiter
        // Markt. Das Produkt hat nur einen Euro-Preis, in GBP fehlt er.
        $this->root(shop: 1, currency: 'GBP');
        $this->product(1, shop: true, price: true);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'GBP', 'count' => '1'])],
            $this->issues(),
        );
    }

    public function testDieBefundeStehenNachWaehrungSortiertUndNichtNachStartpunkt(): void
    {
        // Der englische Startpunkt steht in GBP, der deutsche in EUR — nach
        // Startpunkt sortiert käme GBP zuerst. Die Reihenfolge der Befunde auf
        // der Backend-Startseite darf nicht davon abhängen, wie die Startpunkte
        // im Seitenbaum heißen.
        $this->connection->update('tl_page', ['consho_currency' => 'GBP'], ['language' => 'de']);
        $this->root(shop: 1, currency: 'EUR', language: 'en');
        $this->product(1, shop: true, price: false);

        self::assertEquals(
            [
                new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'EUR', 'count' => '1']),
                new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'GBP', 'count' => '1']),
            ],
            $this->issues(),
        );
    }

    public function testEinZweiterStartpunktDerselbenWaehrungZaehltNichtDoppelt(): void
    {
        // Zweisprachiger Shop, beide Startpunkte in Euro — ein Markt, ein Befund.
        $this->root(shop: 1, currency: 'EUR', language: 'en');
        $this->product(1, shop: true, price: false);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'EUR', 'count' => '1'])],
            $this->issues(),
        );
    }

    public function testAPriceOnAVariantCounts(): void
    {
        // Mit Varianten sitzen die Preise an den Varianten (A-4b, E2).
        $this->product(1, shop: true, price: false);
        $this->insertProduct(['id' => 2, 'pid' => 1, 'published' => 1]);
        $this->insertListPrice(['pid' => 2, 'currency' => 'EUR', 'country' => 'AT', 'net' => '1', 'gross' => '1']);

        self::assertSame([], $this->issues());
    }

    public function testTheVisibilityProjectionDoesNotEnterTheCount(): void
    {
        // B-10: Die Diagnose beschreibt die Quelldaten. Die Projektion bleibt
        // hier leer — über sie gerechnet stünden 2 statt 1 ohne Preis da, und
        // ein veralteter Stand erschiene als fehlender Preis.
        $this->product(1, shop: true, price: true);
        $this->product(2, shop: true, price: false);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ProductPriceMissing, ['shop' => 'Kräuterhaus', 'currency' => 'EUR', 'count' => '1'])],
            $this->issues(),
        );
    }

    public function testBothAreNotesThatCanBeAcknowledged(): void
    {
        // Ein Produkt nur für einen Link an Stammkunden kann gewollt sein.
        self::assertFalse(ConfigurationFinding::ProductShopMissing->isWarning());
        self::assertFalse(ConfigurationFinding::ProductPriceMissing->isWarning());
    }

    private function root(int $shop, string $currency, string $language = 'de'): void
    {
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => $shop, 'consho_currency' => $currency, 'language' => $language]);
    }

    private function product(int $id, bool $shop, bool $price): void
    {
        $this->insertProduct(['id' => $id, 'published' => 1]);

        if ($shop) {
            $this->insertProductShop(['pid' => $id, 'shop' => 1]);
        }

        if ($price) {
            $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10', 'gross' => '12']);
        }
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        $criteria = new VisibilityCriteria();

        return (new DbalCatalogCompletenessIssueSource(
            $this->connection,
            $criteria,
            new VisibilityRows($criteria),
            new ShopLanguages($this->connection),
            static fn (): int => self::NOW,
        ))->issues();
    }
}
