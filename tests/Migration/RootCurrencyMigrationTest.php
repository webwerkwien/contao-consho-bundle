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

        // Without this assertion the migration would run again on every
        // contao:migrate and overwrite a currency list that has meanwhile
        // been extended by hand.
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

        // Not a start point: consho_shop is in the column, but the form only
        // shows the field on start points. A value here would be a leftover
        // from a changed page type — the migration must not turn it into a
        // sales signal.
        $this->connection->insert('tl_page', ['id' => 20, 'type' => 'regular', 'consho_shop' => 1, 'consho_currency' => '']);

        $this->migration()->run();

        self::assertSame('', $this->currencyOfRoot(20));
    }

    public function testEinStartpunktOhneShopDatensatzBleibtLeer(): void
    {
        // The shop was deleted, the reference points to nothing. Nothing is
        // guessed here — a wrong currency that lives on silently would be
        // worse than an empty one.
        //
        // ⚠️ This page does not report RootWithoutCurrency either: without a
        // shop there is nobody who could accept or reject a currency, and the
        // message would name a shop that does not exist. It falls under
        // "start point without a shop configuration" (Block A, section 7).
        $this->insertRoot(10, 99);

        $this->migration()->run();

        self::assertSame('', $this->currencyOfRoot(10));
        $this->assertNichtsMehrZuTun();
    }

    public function testEinShopMitZweiWaehrungenLaesstSeinenStartpunktWaehlen(): void
    {
        // There is nothing to inherit: which of the two applies here only the
        // operator can say. RootWithoutCurrency reports the gap.
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->connection->update('tl_consho_shop', ['currencies' => serialize(['CHF', 'EUR'])], ['id' => 1]);
        $this->insertRoot(10, 1);

        $this->migration()->run();

        self::assertSame('', $this->currencyOfRoot(10));
        $this->assertNichtsMehrZuTun();
    }

    public function testEineBereitsGepflegteWaehrungsListeBleibtStehen(): void
    {
        // A shop created between task 1 and this migration: currencies is
        // selected, the old single column empty. The migration must not
        // replace the selection with the empty old column.
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

        // Something has already asked the service in this request — then it
        // holds the list from BEFORE the migration, and that is empty. Without
        // the reset() in run(), resolve() would see exactly this empty list
        // and the start point would stay without a currency.
        $currencies->of(1);

        (new RootCurrencyMigration($this->connection, $currencies))->run();

        self::assertSame('EUR', $this->currencyOfRoot(10));
    }

    public function testEinShopOhneWaehrungBekommtKeineLeereListe(): void
    {
        // Neither guess nor enter an empty string as the currency:
        // serialize(['']) would look like a selection and would not be one.
        $this->insertShop(1, 'Kräuterhaus', '');
        $this->insertRoot(10, 1);

        $this->migration()->run();

        // The raw column, not the service: ShopCurrencies filters out an
        // invalid currency anyway, and so serialize(['']) would stay in the
        // database unnoticed. Counter-check: without `currency <> ''` in the
        // query, the mutation survives the test through the service, but not
        // this one.
        self::assertNull($this->connection->fetchOne('SELECT currencies FROM tl_consho_shop WHERE id = 1') ?: null);
        self::assertSame([], (new ShopCurrencies($this->connection))->of(1));
        self::assertSame('', $this->currencyOfRoot(10));
        $this->assertNichtsMehrZuTun();
    }

    /**
     * What run() cannot resolve, shouldRun() must not report as work.
     *
     * contao:migrate runs a migration until it declares itself done, and
     * aborts after 19 rounds with exit 1 — while it reports success every time
     * ("0 root page(s)"). The abort then points at Contao instead of at the
     * cause. This migration did exactly that in three states that its own
     * class comment describes as intended; the termination test had only
     * checked the healthy case.
     */
    private function assertNichtsMehrZuTun(): void
    {
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testEineBereitsGesetzteWaehrungWirdNichtUeberschrieben(): void
    {
        // A start point on which someone has already selected by hand — even
        // if the selection does not match the shop's old column. The migration
        // fills in values, it does not correct them.
        $this->insertShop(1, 'Kräuterhaus', 'EUR');
        $this->connection->insert('tl_page', ['id' => 10, 'type' => 'root', 'consho_shop' => 1, 'consho_currency' => 'CHF']);

        $this->migration()->run();

        self::assertSame('CHF', $this->currencyOfRoot(10));
    }

    public function testNachDemEntfernenDerAltspalteLaeuftSieNichtMehr(): void
    {
        // Task 10 drops tl_consho_shop.currency later on. From then on this
        // migration has no source any more — it must stay silent, not
        // fail.
        $this->connection->executeStatement('DROP TABLE tl_consho_shop');
        $this->connection->executeStatement("CREATE TABLE tl_consho_shop (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', currencies BLOB)");

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieNeueSpalteLaeuftDieMigrationNicht(): void
    {
        // contao:migrate runs in two passes, with the schema update in
        // between. In the first one the column does not exist yet — without
        // this check the pass fails on an unknown column.
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
