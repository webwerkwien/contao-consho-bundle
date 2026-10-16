<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalCachedCatalogPageIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * „Immer aus dem Cache laden" an einer Seite, während der Shop kundenabhängig
 * preist (C-4, Befund B1 des Abschlussreviews).
 *
 * ⚠️ Was diese Tests NICHT zeigen: dass Contao dann wirklich die Gast-Seite
 * ausliefert. Das hängt an `Vary: Cookie` in AbstractController und ist hier
 * nachgelesen, nicht nachgestellt — ein Test dafür prüfte unsere eigene
 * Abschrift (Fall 23g). Geprüft wird die Entscheidung: Wann melden wir?
 */
class DbalCachedCatalogPageIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                type TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                includeCache INTEGER NOT NULL DEFAULT 0,
                alwaysLoadFromCache INTEGER NOT NULL DEFAULT 0
            )",
        );

        $this->insertShop(['id' => 1, 'title' => 'Testshop']);
    }

    public function testOhneKundenabhaengigePreiseIstDerHakenHarmlos(): void
    {
        // Der Zustand vor C-4, und der jedes Shops ohne Kundengruppen: Alle
        // sehen dasselbe, und genau dafür ist der geteilte Cache da.
        $this->cachedPage(2, 'Katalog');

        self::assertSame([], $this->issues());
    }

    public function testEineNettogruppeMachtDenHakenZumBefund(): void
    {
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
        $this->cachedPage(2, 'Katalog');

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::CatalogPageAlwaysCached, $issues[0]->finding);
        self::assertSame(['pages' => 'Katalog'], $issues[0]->context);
    }

    public function testAuchEinGruppenpreisOhneNettogruppeReicht(): void
    {
        // Zwei Wege zu kundenabhängigen Preisen, und dieser hier braucht am
        // Shop keine Einstellung: Der Betreiber legt eine Preiszeile für eine
        // Kundengruppe an, fertig.
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '8.0000', 'customerGroup' => 3]);
        $this->cachedPage(2, 'Katalog');

        self::assertCount(1, $this->issues());
    }

    public function testEineNieGespeichertePreiszeileZaehltNicht(): void
    {
        // tstamp 0 — dieselbe Regel wie in DbalPriceRepository::findCandidates().
        // Sonst meldete schon das Öffnen einer Preismaske diesen Befund.
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 0, 'currency' => 'EUR', 'net' => '0.0000', 'customerGroup' => 3]);
        $this->cachedPage(2, 'Katalog');

        self::assertSame([], $this->issues());
    }

    public function testOhneGesetztenHakenGibtEsNichtsZuMelden(): void
    {
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'regular', 'title' => 'Katalog', 'includeCache' => 1, 'alwaysLoadFromCache' => 0]);

        self::assertSame([], $this->issues());
    }

    public function testAlleBetroffenenSeitenStehenInEinemBefund(): void
    {
        // Ein Befund, nicht einer je Seite: Der Betreiber geht die Liste
        // einmal durch, und eine Quittierung, die beim nächsten Haken zerfällt,
        // wäre richtig so — der Kontext trägt die Seiten und damit den
        // Fingerabdruck.
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
        $this->cachedPage(2, 'Katalog');
        $this->cachedPage(3, 'Angebote');

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(['pages' => 'Angebote, Katalog'], $issues[0]->context);
    }

    public function testDerBefundIstEinHinweisUndSperrtNichts(): void
    {
        // Jeder gezeigte Preis ist ein echter Preis dieses Shops — nur der
        // falsche für diesen Besucher. Und der Betreiber hat den Haken selbst
        // gesetzt.
        self::assertFalse(ConfigurationFinding::CatalogPageAlwaysCached->isWarning());
    }

    public function testOhneUebersteuerteCacheEinstellungenTutDerHakenNichts(): void
    {
        // ⚠️ In der zweiten Reviewrunde gefunden: loadDetails() liest
        // `alwaysLoadFromCache = includeCache ? alwaysLoadFromCache : false`
        // (PageModel, 933). Ein stehengebliebener Haken auf einer Seite, deren
        // „Cache-Einstellungen überschreiben" wieder aus ist, wirkt gar nicht —
        // und ein Befund dazu schickte den Betreiber zu einem Kästchen, das
        // schon harmlos ist.
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
        $this->connection->insert('tl_page', [
            'id' => 2,
            'type' => 'regular',
            'title' => 'Katalog',
            'includeCache' => 0,
            'alwaysLoadFromCache' => 1,
        ]);

        self::assertSame([], $this->issues());
    }

    private function cachedPage(int $id, string $title): void
    {
        $this->connection->insert('tl_page', [
            'id' => $id,
            'type' => 'regular',
            'title' => $title,
            // Beides, weil erst das Paar wirkt.
            'includeCache' => 1,
            'alwaysLoadFromCache' => 1,
        ]);
    }

    /**
     * @return list<\Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalCachedCatalogPageIssueSource($this->connection))->issues();
    }
}
