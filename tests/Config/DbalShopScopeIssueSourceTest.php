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
 * Shop 1 "Kräuterhaus" accepts EUR and CHF; a start point "Österreich" in EUR.
 *
 * Checked is what can come about past the form: a start point without a
 * currency and one in a currency the shop does not (or no longer) accept.
 */
class DbalShopScopeIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Both from their DCA, so the types are as in the DB:
        // `currencies` is a blob, `consho_currency` a varchar(3) with an
        // empty default (contao/dca/tl_consho_shop.php, tl_page.php).
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::createCore($this->connection, 'tl_page');

        $this->shop(1, 'Kräuterhaus', ['EUR', 'CHF']);
    }

    public function testEinVollstaendigerStartpunktErzeugtKeinenBefund(): void
    {
        $this->root(1, 'Österreich', shop: 1, currency: 'EUR');

        self::assertSame([], $this->issues());
    }

    public function testEinStartpunktOhneWaehrungWirdBeimNamenGenannt(): void
    {
        // Past the form: a mandatory field that can stay empty via import or
        // SQL. Below this page the shop shows no price.
        $this->root(7, 'Österreich', shop: 1, currency: '');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::RootWithoutCurrency, ['page' => 'Österreich (ID 7)', 'shop' => 'Kräuterhaus'], 1)],
            $this->issues(),
        );
    }

    public function testEineWaehrungDieDerShopNichtMehrAnnimmtWirdGemeldet(): void
    {
        // The silent case: shop ['EUR','CHF'], start point CHF, the operator
        // removes CHF → the shop has only one currency left → the field is
        // hidden, save() never runs again. Up to here nobody notices.
        $this->root(7, 'Schweiz', shop: 1, currency: 'CHF');
        $this->shop(1, 'Kräuterhaus', ['EUR']);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::RootCurrencyNotSold, ['page' => 'Schweiz (ID 7)', 'currency' => 'CHF', 'shop' => 'Kräuterhaus'], 1)],
            $this->issues(),
        );
    }

    public function testDerLeereFallFaelltNichtUnterDieNichtAngenommeneWaehrung(): void
    {
        // The empty currency is in no list of allowed currencies — without the
        // case distinction it would report RootCurrencyNotSold and the text
        // would speak of a currency "" that nobody can remove.
        $this->root(7, 'Österreich', shop: 1, currency: '');

        self::assertSame(
            [ConfigurationFinding::RootWithoutCurrency],
            array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $this->issues()),
        );
    }

    public function testDieWaehrungWirdUnabhaengigVonGrossKleinschreibungVerglichen(): void
    {
        // Task 3 migrates the old shop value to the start points without it
        // ever having run through a save callback. A lower-case "eur" is the
        // same currency, not a finding.
        $this->root(7, 'Österreich', shop: 1, currency: 'eur');

        self::assertSame([], $this->issues());
    }

    public function testEineWaehrungAusLauterLeerzeichenGiltAlsKeine(): void
    {
        // varchar(3) from an import: "   " is not a currency but a missing
        // one. Without trim() the finding would report a currency named "   "
        // that nobody can re-allow on the shop.
        $this->root(7, 'Österreich', shop: 1, currency: '   ');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::RootWithoutCurrency, ['page' => 'Österreich (ID 7)', 'shop' => 'Kräuterhaus'], 1)],
            $this->issues(),
        );
    }

    public function testEineUngueltigeWaehrungAmShopZaehltNichtAlsAngenommen(): void
    {
        // CurrencySelection discards whatever is not an ISO 4217 code — the shop
        // does not accept "XYZ", even if it is stored on the shop.
        $this->shop(1, 'Kräuterhaus', ['XYZ']);
        $this->root(7, 'Fantasien', shop: 1, currency: 'XYZ');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::RootCurrencyNotSold, ['page' => 'Fantasien (ID 7)', 'currency' => 'XYZ', 'shop' => 'Kräuterhaus'], 1)],
            $this->issues(),
        );
    }

    public function testSeitenOhneShopWerdenNichtGeprueft(): void
    {
        // A start point without a shop configuration switches the shop functions
        // off (Block A, section 7) — no currency applies there at all.
        $this->root(7, 'Verein', shop: 0, currency: '');

        self::assertSame([], $this->issues());
    }

    public function testNurStartpunkteWerdenGeprueft(): void
    {
        // An ordinary page does not carry the field in its palette; a leftover
        // in the column is not a finding.
        $this->connection->insert('tl_page', ['id' => 7, 'type' => 'regular', 'title' => 'Impressum', 'consho_shop' => 1, 'consho_currency' => '']);

        self::assertSame([], $this->issues());
    }

    public function testEinStartpunktMitGeloeschtemShopErzeugtKeinenWaehrungsbefund(): void
    {
        // There is no shop that could accept or reject a currency — the message
        // would name a shop without a name. The same case as above: no shop
        // configuration, no shop functions.
        $this->root(7, 'Verwaist', shop: 99, currency: 'CHF');
        $this->root(8, 'Auch verwaist', shop: 99, currency: '');

        self::assertSame([], $this->issues());
    }

    public function testJederBetroffeneStartpunktBekommtSeinenEigenenBefund(): void
    {
        // Titles without umlauts, so that the expected order stays directly
        // readable: sorting is bytewise, "Österreich" would come after
        // "Schweiz".
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
        // The titles deliberately contradict the id order. PRAGMA
        // reverse_unordered_selects: without ORDER BY, SQLite would otherwise
        // read the table in ascending id order anyway — by chance not the
        // expected order, but only by chance. Only with the PRAGMA does a
        // missing ORDER BY fail reliably.
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
        // Both times the start point shows prices nobody can collect —
        // nothing to click away, and after 11.2 that blocks the checkout.
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
        // The fingerprint includes the context: whoever acknowledged the
        // situation with two shops has said nothing about the third.
        $this->shop(2, 'Almhütte', ['EUR']);
        $zwei = $this->issues()[0];

        $this->shop(3, 'Waldhaus', ['EUR']);
        $drei = $this->issues()[0];

        self::assertNotSame($zwei->fingerprint(), $drei->fingerprint());
    }

    public function testDerHinweisZuGeteiltenMitgliedernIstNieEineWarnung(): void
    {
        // 🔴 Several shops are allowed and intended. A warning would block the
        // shop's checkout (Block C, 11.2) — this finding blocks nothing,
        // it only says what the operator is getting into.
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
