<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Config;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\DbalShopScopeIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Shop 1 „Kräuterhaus" nimmt EUR und CHF an; ein Startpunkt „Österreich" in EUR.
 *
 * Geprüft wird, was an der Maske vorbei entstehen kann: ein Startpunkt ohne
 * Währung und einer in einer Währung, die der Shop nicht (mehr) annimmt.
 */
class DbalShopScopeIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Nur die Spalten, um die es hier geht — die Typen wie in der DB:
        // `currencies` ist ein blob, `consho_currency` ein varchar(3) mit
        // leerem Vorgabewert (contao/dca/tl_consho_shop.php, tl_page.php).
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '')",
        );

        $this->shop(1, 'Kräuterhaus', ['EUR', 'CHF']);
    }

    public function testEinVollstaendigerStartpunktErzeugtKeinenBefund(): void
    {
        $this->root(1, 'Österreich', shop: 1, currency: 'EUR');

        self::assertSame([], $this->issues());
    }

    public function testEinStartpunktOhneWaehrungWirdBeimNamenGenannt(): void
    {
        // An der Maske vorbei: ein Pflichtfeld, das über Import oder SQL leer
        // bleiben kann. Unter dieser Seite zeigt der Shop keinen Preis.
        $this->root(7, 'Österreich', shop: 1, currency: '');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::RootWithoutCurrency, ['page' => 'Österreich (ID 7)', 'shop' => 'Kräuterhaus'], 1)],
            $this->issues(),
        );
    }

    public function testEineWaehrungDieDerShopNichtMehrAnnimmtWirdGemeldet(): void
    {
        // Der lautlose Fall: Shop ['EUR','CHF'], Startpunkt CHF, der Betreiber
        // streicht CHF → der Shop hat nur noch eine Währung → das Feld wird
        // ausgeblendet, save() läuft nie mehr. Bis hierher merkt es niemand.
        $this->root(7, 'Schweiz', shop: 1, currency: 'CHF');
        $this->shop(1, 'Kräuterhaus', ['EUR']);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::RootCurrencyNotSold, ['page' => 'Schweiz (ID 7)', 'currency' => 'CHF', 'shop' => 'Kräuterhaus'], 1)],
            $this->issues(),
        );
    }

    public function testDerLeereFallFaelltNichtUnterDieNichtAngenommeneWaehrung(): void
    {
        // Die leere Währung ist in keiner Liste erlaubter Währungen enthalten —
        // ohne die Fallunterscheidung meldete sie RootCurrencyNotSold und der
        // Text spräche von einer Währung „", die niemand streichen kann.
        $this->root(7, 'Österreich', shop: 1, currency: '');

        self::assertSame(
            [ConfigurationFinding::RootWithoutCurrency],
            array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $this->issues()),
        );
    }

    public function testDieWaehrungWirdUnabhaengigVonGrossKleinschreibungVerglichen(): void
    {
        // Task 3 migriert den alten Shop-Wert an die Startpunkte, ohne dass er
        // je durch einen Speicher-Callback gelaufen wäre. Ein kleingeschriebenes
        // „eur" ist dieselbe Währung, kein Befund.
        $this->root(7, 'Österreich', shop: 1, currency: 'eur');

        self::assertSame([], $this->issues());
    }

    public function testEineWaehrungAusLauterLeerzeichenGiltAlsKeine(): void
    {
        // varchar(3) aus einem Import: „   " ist keine Währung, sondern eine
        // fehlende. Ohne trim() meldete der Befund eine Währung namens „   ",
        // die niemand am Shop wieder zulassen kann.
        $this->root(7, 'Österreich', shop: 1, currency: '   ');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::RootWithoutCurrency, ['page' => 'Österreich (ID 7)', 'shop' => 'Kräuterhaus'], 1)],
            $this->issues(),
        );
    }

    public function testEineUngueltigeWaehrungAmShopZaehltNichtAlsAngenommen(): void
    {
        // CurrencySelection verwirft, was kein ISO-4217-Code ist — der Shop
        // nimmt „XYZ" nicht an, auch wenn es am Shop gespeichert steht.
        $this->shop(1, 'Kräuterhaus', ['XYZ']);
        $this->root(7, 'Fantasien', shop: 1, currency: 'XYZ');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::RootCurrencyNotSold, ['page' => 'Fantasien (ID 7)', 'currency' => 'XYZ', 'shop' => 'Kräuterhaus'], 1)],
            $this->issues(),
        );
    }

    public function testSeitenOhneShopWerdenNichtGeprueft(): void
    {
        // Ein Startpunkt ohne Shop-Konfiguration schaltet die Shop-Funktionen
        // ab (Block A, Abschnitt 7) — dort gilt gar keine Währung.
        $this->root(7, 'Verein', shop: 0, currency: '');

        self::assertSame([], $this->issues());
    }

    public function testNurStartpunkteWerdenGeprueft(): void
    {
        // Eine gewöhnliche Seite trägt das Feld nicht in der Palette; ein Rest
        // in der Spalte ist kein Befund.
        $this->connection->insert('tl_page', ['id' => 7, 'type' => 'regular', 'title' => 'Impressum', 'consho_shop' => 1, 'consho_currency' => '']);

        self::assertSame([], $this->issues());
    }

    public function testEinStartpunktMitGeloeschtemShopErzeugtKeinenWaehrungsbefund(): void
    {
        // Es gibt keinen Shop, der eine Währung annehmen oder ablehnen könnte —
        // die Meldung nennte einen Shop ohne Namen. Derselbe Fall wie oben:
        // ohne Shop-Konfiguration keine Shop-Funktionen.
        $this->root(7, 'Verwaist', shop: 99, currency: 'CHF');
        $this->root(8, 'Auch verwaist', shop: 99, currency: '');

        self::assertSame([], $this->issues());
    }

    public function testJederBetroffeneStartpunktBekommtSeinenEigenenBefund(): void
    {
        // Titel ohne Umlaut, damit die erwartete Reihenfolge unmittelbar
        // lesbar bleibt: sortiert wird byteweise, „Österreich" stünde hinter
        // „Schweiz".
        $this->root(7, 'Alpenseite', shop: 1, currency: '');
        $this->root(8, 'Bergseite', shop: 1, currency: 'GBP');

        self::assertEquals(
            [
                new ConfigurationIssue(ConfigurationFinding::RootWithoutCurrency, ['page' => 'Alpenseite (ID 7)', 'shop' => 'Kräuterhaus'], 1),
                new ConfigurationIssue(ConfigurationFinding::RootCurrencyNotSold, ['page' => 'Bergseite (ID 8)', 'currency' => 'GBP', 'shop' => 'Kräuterhaus'], 1),
            ],
            $this->issues(),
        );
    }

    public function testDieReihenfolgeFolgtDemSeitentitelNichtDerId(): void
    {
        // Die Titel widersprechen absichtlich der id-Reihenfolge. PRAGMA
        // reverse_unordered_selects: ohne ORDER BY läse SQLite die Tabelle
        // sonst ohnehin aufsteigend nach id — zufällig nicht die erwartete
        // Reihenfolge, aber eben nur zufällig. Erst mit der PRAGMA scheitert
        // ein fehlendes ORDER BY zuverlässig.
        $this->connection->executeStatement('PRAGMA reverse_unordered_selects = ON');

        $this->root(7, 'Mitte', shop: 1, currency: '');
        $this->root(8, 'Anfang', shop: 1, currency: '');
        $this->root(9, 'Zuletzt', shop: 1, currency: '');

        self::assertSame(
            ['Anfang (ID 8)', 'Mitte (ID 7)', 'Zuletzt (ID 9)'],
            array_map(static fn (ConfigurationIssue $i): string => $i->context['page'], $this->issues()),
        );
    }

    public function testBeideStartpunktbefundeSindWarnungen(): void
    {
        // Beide Male zeigt der Startpunkt Preise, die niemand kassieren kann —
        // nichts zum Wegklicken, und nach 11.2 sperrt das den Checkout.
        self::assertTrue(ConfigurationFinding::RootWithoutCurrency->isWarning());
        self::assertTrue(ConfigurationFinding::RootCurrencyNotSold->isWarning());
    }

    public function testEinEinzelnerShopErzeugtKeinenHinweisZuGeteiltenMitgliedern(): void
    {
        $this->root(7, 'Österreich', shop: 1, currency: 'EUR');

        self::assertSame([], $this->issues());
    }

    public function testAbDemZweitenShopErscheintDerHinweisZuGeteiltenMitgliedern(): void
    {
        $this->root(7, 'Österreich', shop: 1, currency: 'EUR');
        $this->shop(2, 'Almhütte', ['EUR']);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::MultipleShopsShareMembers, ['shops' => 'Almhütte, Kräuterhaus'])],
            $this->issues(),
        );
    }

    public function testEinDritterShopIstEinNeuerFall(): void
    {
        // Der Fingerabdruck enthält den Kontext: Wer die Lage mit zwei Shops
        // zur Kenntnis genommen hat, hat über den dritten nichts gesagt.
        $this->shop(2, 'Almhütte', ['EUR']);
        $zwei = $this->issues()[0];

        $this->shop(3, 'Waldhaus', ['EUR']);
        $drei = $this->issues()[0];

        self::assertNotSame($zwei->fingerprint(), $drei->fingerprint());
    }

    public function testDerHinweisZuGeteiltenMitgliedernIstNieEineWarnung(): void
    {
        // 🔴 Mehrere Shops sind erlaubt und gewollt. Eine Warnung sperrte den
        // Checkout des Shops (Block C, 11.2) — dieser Befund sperrt nichts,
        // er sagt nur, worauf sich der Betreiber einlässt.
        self::assertFalse(ConfigurationFinding::MultipleShopsShareMembers->isWarning());
    }

    /**
     * @param list<string> $currencies
     */
    private function shop(int $id, string $title, array $currencies): void
    {
        $this->connection->delete('tl_consho_shop', ['id' => $id]);
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'title' => $title, 'currencies' => serialize($currencies)]);
    }

    private function root(int $id, string $title, int $shop, string $currency): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'type' => 'root', 'title' => $title, 'consho_shop' => $shop, 'consho_currency' => $currency]);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalShopScopeIssueSource($this->connection))->issues();
    }
}
