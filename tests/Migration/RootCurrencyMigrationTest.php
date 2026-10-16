<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\RootCurrencyMigration;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class RootCurrencyMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->createTables();
    }

    public function testJederStartpunktErbtDieWaehrungSeinesShops(): void
    {
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->insertShop(2, 'Herb House', 'GBP');
        $this->insertRoot(10, 1);
        $this->insertRoot(11, 1);
        $this->insertRoot(12, 2);

        $this->migration()->run();

        self::assertSame('EUR', $this->currencyOfRoot(10));
        self::assertSame('EUR', $this->currencyOfRoot(11));
        self::assertSame('GBP', $this->currencyOfRoot(12));
    }

    public function testJederShopErlaubtGenauSeineBisherigeWaehrung(): void
    {
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->insertShop(2, 'Herb House', 'GBP');

        $this->migration()->run();

        self::assertSame(['EUR'], (new ShopCurrencies($this->connection))->of(1));
        self::assertSame(['GBP'], (new ShopCurrencies($this->connection))->of(2));
    }

    public function testDerZweiteLaufFindetNichtsMehrZuTun(): void
    {
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->insertRoot(10, 1);

        $migration = $this->migration();

        self::assertTrue($migration->shouldRun());

        $migration->run();

        // Ohne diese Zusicherung liefe die Migration bei jedem
        // contao:migrate erneut und überschriebe eine inzwischen von Hand
        // erweiterte Währungsliste.
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testEineSeiteOhneShopBleibtUnberuehrt(): void
    {
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->insertRoot(10, 0);

        $this->migration()->run();

        self::assertSame('', $this->currencyOfRoot(10));
    }

    public function testEineGewoehnlicheSeiteBleibtUnberuehrt(): void
    {
        $this->insertShop(1, 'Kräuterhaus', 'EUR');

        // Kein Startpunkt: consho_shop steht zwar in der Spalte, aber die
        // Maske zeigt das Feld nur an Startpunkten. Ein Wert hier wäre ein
        // Rest aus einem umgestellten Seitentyp — die Migration darf ihn
        // nicht zum Verkaufssignal machen.
        $this->connection->insert('tl_page', ['id' => 20, 'type' => 'regular', 'consho_shop' => 1, 'consho_currency' => '']);

        $this->migration()->run();

        self::assertSame('', $this->currencyOfRoot(20));
    }

    public function testEinStartpunktOhneShopDatensatzBleibtLeer(): void
    {
        // Der Shop wurde gelöscht, der Verweis zeigt ins Leere. Hier wird
        // nichts geraten — eine falsche Währung, die still weiterlebt, wäre
        // schlimmer als eine leere.
        //
        // ⚠️ Diese Seite meldet auch RootWithoutCurrency nicht: Ohne Shop gibt
        // es niemanden, der eine Währung annehmen oder ablehnen könnte, und
        // die Meldung nennte einen Shop, den es nicht gibt. Sie fällt unter
        // „Startpunkt ohne Shop-Konfiguration" (Block A, Abschnitt 7).
        $this->insertRoot(10, 99);

        $this->migration()->run();

        self::assertSame('', $this->currencyOfRoot(10));
        $this->assertNichtsMehrZuTun();
    }

    public function testEinShopMitZweiWaehrungenLaesstSeinenStartpunktWaehlen(): void
    {
        // Es gibt nichts zu erben: Welche der beiden hier gilt, kann nur der
        // Betreiber sagen. RootWithoutCurrency meldet die Lücke.
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->connection->update('tl_consho_shop', ['currencies' => serialize(['CHF', 'EUR'])], ['id' => 1]);
        $this->insertRoot(10, 1);

        $this->migration()->run();

        self::assertSame('', $this->currencyOfRoot(10));
        $this->assertNichtsMehrZuTun();
    }

    public function testEineBereitsGepflegteWaehrungsListeBleibtStehen(): void
    {
        // Ein Shop, der zwischen Task 1 und dieser Migration angelegt wurde:
        // currencies ist gewählt, die alte Einzelspalte leer. Die Migration
        // darf die Wahl nicht durch die leere Altspalte ersetzen.
        $this->insertShop(1, 'Kräuterhaus', '');
        $this->connection->update('tl_consho_shop', ['currencies' => serialize(['CHF', 'EUR'])], ['id' => 1]);

        $this->migration()->run();

        self::assertSame(['CHF', 'EUR'], (new ShopCurrencies($this->connection))->of(1));
    }

    public function testEinVorherGefuellterZwischenspeicherStehtNichtImWeg(): void
    {
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->insertRoot(10, 1);

        $currencies = new ShopCurrencies($this->connection);

        // Irgendetwas hat den Dienst in diesem Request schon befragt — dann
        // hält er die Liste von VOR der Migration, und die ist leer. Ohne das
        // reset() in run() bekäme resolve() genau diese leere Liste zu sehen
        // und der Startpunkt bliebe ohne Währung.
        $currencies->of(1);

        (new RootCurrencyMigration($this->connection, $currencies))->run();

        self::assertSame('EUR', $this->currencyOfRoot(10));
    }

    public function testEinShopOhneWaehrungBekommtKeineLeereListe(): void
    {
        // Weder raten noch eine leere Zeichenkette als Währung eintragen:
        // serialize(['']) sähe aus wie eine Wahl und wäre keine.
        $this->insertShop(1, 'Kräuterhaus', '');
        $this->insertRoot(10, 1);

        $this->migration()->run();

        // Die rohe Spalte, nicht der Dienst: ShopCurrencies filtert eine
        // ungültige Währung ohnehin weg, und dadurch bliebe serialize([''])
        // in der Datenbank unbemerkt liegen. Gegenprobe bestätigt: ohne
        // `currency <> ''` in der Abfrage überlebt die Mutation den Test über
        // den Dienst, diesen hier nicht.
        self::assertNull($this->connection->fetchOne('SELECT currencies FROM tl_consho_shop WHERE id = 1') ?: null);
        self::assertSame([], (new ShopCurrencies($this->connection))->of(1));
        self::assertSame('', $this->currencyOfRoot(10));
        $this->assertNichtsMehrZuTun();
    }

    /**
     * Was run() nicht auflösen kann, darf shouldRun() nicht als Arbeit melden.
     *
     * contao:migrate führt eine Migration so lange aus, bis sie sich für fertig
     * erklärt, und bricht nach 19 Runden mit Exit 1 ab — während sie jedes Mal
     * Erfolg meldet („0 root page(s)"). Der Abbruch zeigt dann auf Contao statt
     * auf die Ursache. Genau das tat diese Migration in drei Zuständen, die ihr
     * eigener Klassenkommentar als gewollt beschreibt; gefunden im
     * Abschlussreview vom 2026-09-21, nachdem der Terminierungstest nur den
     * gesunden Fall geprüft hatte.
     */
    private function assertNichtsMehrZuTun(): void
    {
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testEineBereitsGesetzteWaehrungWirdNichtUeberschrieben(): void
    {
        // Ein Startpunkt, an dem schon jemand von Hand gewählt hat — auch
        // wenn die Wahl nicht zur Altspalte des Shops passt. Die Migration
        // holt Werte nach, sie korrigiert nicht.
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->connection->insert('tl_page', ['id' => 10, 'type' => 'root', 'consho_shop' => 1, 'consho_currency' => 'CHF']);

        $this->migration()->run();

        self::assertSame('CHF', $this->currencyOfRoot(10));
    }

    public function testNachDemEntfernenDerAltspalteLaeuftSieNichtMehr(): void
    {
        // Task 10 löscht tl_consho_shop.currency nach der Abnahme. Ab dann
        // hat diese Migration keine Quelle mehr — sie muss schweigen, nicht
        // scheitern.
        $this->connection->executeStatement('DROP TABLE tl_consho_shop');
        $this->connection->executeStatement("CREATE TABLE tl_consho_shop (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', currencies BLOB)");

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieNeueSpalteLaeuftDieMigrationNicht(): void
    {
        // contao:migrate läuft in zwei Durchgängen, der Schemaabgleich
        // dazwischen. Im ersten gibt es die Spalte noch nicht — ohne diese
        // Prüfung scheitert der Durchgang an einer unbekannten Spalte.
        $this->connection->executeStatement('DROP TABLE tl_page');
        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0)");

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testDieMeldungNenntBeideZahlen(): void
    {
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->insertRoot(10, 1);
        $this->insertRoot(11, 1);

        $message = $this->migration()->run()->getMessage();

        self::assertStringContainsString('1', $message);
        self::assertStringContainsString('2', $message);
    }

    private function migration(): RootCurrencyMigration
    {
        return new RootCurrencyMigration($this->connection, new ShopCurrencies($this->connection));
    }

    private function createTables(): void
    {
        // From the DCA: it still carries the old `currency` beside `currencies`
        // — the state this migration reads from.
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                type TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                consho_currency TEXT NOT NULL DEFAULT ''
            )",
        );
    }

    private function insertShop(int $id, string $title, string $currency): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'title' => $title, 'currency' => $currency]);
    }

    private function insertRoot(int $id, int $shopId): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'type' => 'root', 'consho_shop' => $shopId, 'consho_currency' => '']);
    }

    private function currencyOfRoot(int $id): string
    {
        return (string) $this->connection->fetchOne('SELECT consho_currency FROM tl_page WHERE id = ?', [$id]);
    }
}
