<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalShippingPriceIssueSource;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalShippingPriceIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // `currency` is still in the table until C-1c is complete (the column
        // only goes with step 10.4) and is deliberately left EMPTY here —
        // exactly the state of a migrated installation. A source that still
        // read it would get '' and report every flat rate; the tests below then
        // fail, instead of failing on a missing column and hiding the actual
        // statement. From the DCA, where it is DEFAULT '' too.
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_shipping_method');
        DcaSchema::create($this->connection, 'tl_consho_shipping_price');
        $this->connection->insert('tl_consho_shipping_method', ['id' => 3, 'title' => 'Post', 'published' => 1, 'type' => 'flat_rate', 'taxClass' => 1]);
    }

    public function testEineGewaehlteArtOhneBetragInDerShopWaehrungWirdGemeldet(): void
    {
        $this->shop(1, 'Wien', ['EUR'], ['3']);
        $this->price(3, 'CHF');

        self::assertEquals(
            // Block I: with the shop, otherwise the finding stands for every shop.
            [new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, ['method' => 'Post', 'shop' => 'Wien', 'currency' => 'EUR'], 1)],
            $this->issues(),
        );
    }

    /**
     * E-4: The weight scale needs saved steps just as the flat rate needs its
     * amount — without a row in the currency it is not available.
     */
    public function testAuchDieGewichtsstaffelOhneBetragWirdGemeldet(): void
    {
        $this->connection->update('tl_consho_shipping_method', ['type' => 'weight_rate'], ['id' => 3]);
        $this->shop(1, 'Wien', ['EUR'], ['3']);

        self::assertCount(1, $this->issues());
    }

    public function testMitGespeichertemBetragGibtEsKeinenBefund(): void
    {
        $this->shop(1, 'Wien', ['EUR'], ['3']);
        $this->price(3, 'EUR');

        self::assertSame([], $this->issues());
    }

    public function testEinNieGespeicherterBetragZaehltNicht(): void
    {
        $this->shop(1, 'Wien', ['EUR'], ['3']);
        $this->price(3, 'EUR', tstamp: 0);

        self::assertCount(1, $this->issues());
    }

    public function testUnveroeffentlichteUndNichtGewaehlteArtenWerdenNichtGemeldet(): void
    {
        // Unpublished, the checkout does not offer it anyway.
        $this->connection->insert('tl_consho_shipping_method', ['id' => 4, 'title' => 'Spedition', 'published' => 0]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 5, 'title' => 'Abholung', 'published' => 1]);
        $this->shop(1, 'Wien', ['EUR'], ['4']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Leer', 'currencies' => serialize(['EUR'])]);

        self::assertSame([], $this->issues());
    }

    public function testJederShopPrueftSeineEigeneWaehrung(): void
    {
        $this->shop(1, 'Wien', ['EUR'], ['3']);
        $this->shop(2, 'Zürich', ['CHF'], ['3']);
        $this->price(3, 'EUR');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, ['method' => 'Post', 'shop' => 'Zürich', 'currency' => 'CHF'], 2)],
            $this->issues(),
        );
    }

    public function testDerBefundIstEineWarnung(): void
    {
        // Spec 11.3: blocks this method (C-6) — nothing to click away.
        self::assertTrue(ConfigurationFinding::ShippingPriceMissing->isWarning());
    }

    public function testUnvollstaendigGespeicherteArtenErzeugenKeinenBefund(): void
    {
        // act=toggle publishes without the form's mandatory checks: type
        // '' or taxClass 0 means the row was never saved (task 1) — the
        // same condition as in the checkout (DbalShippingMethodRepository::offerable()).
        $this->connection->insert('tl_consho_shipping_method', ['id' => 4, 'title' => 'Ohne Typ', 'published' => 1, 'type' => '', 'taxClass' => 1]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 5, 'title' => 'Ohne Steuerklasse', 'published' => 1, 'type' => 'flat_rate', 'taxClass' => 0]);
        $this->shop(1, 'Wien', ['EUR'], ['4', '5']);

        self::assertSame([], $this->issues());
    }

    public function testEineVollstaendigeArtEinesAnderenTypsOhneBetragWirdNichtGemeldet(): void
    {
        // The finding only applies to the flat rate (task 2): a carrier module
        // can calculate its price even without a saved row. A later type that
        // needs a row must register itself here (open point, first carrier
        // module / C-6).
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'DHL', 'published' => 1, 'type' => 'dhl', 'taxClass' => 1]);
        $this->shop(1, 'Wien', ['EUR'], ['6']);

        self::assertSame([], $this->issues());
    }

    public function testDieZweiteErlaubteWaehrungOhneBetragWirdGemeldet(): void
    {
        // The shop accepts both, the flat rate only has an amount in one.
        // Before, the source only checked against the ONE shop currency and
        // said nothing about the other — the same shape as the error that
        // task 4 fixed in the completeness source.
        //
        // ⚠️ The alphabetically FIRST one is paid (ShopCurrencies sorts), the
        // second is reported. The other way round, a version that only checks
        // the first currency would survive.
        $this->shop(1, 'Wien', ['EUR', 'CHF'], ['3']);
        $this->price(3, 'CHF');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, ['method' => 'Post', 'shop' => 'Wien', 'currency' => 'EUR'], 1)],
            $this->issues(),
        );
    }

    public function testUndEbensoDieErsteErlaubteWaehrung(): void
    {
        // The counter-check to the test above: this time the amount is missing
        // in the alphabetically first one. A version that only checks the last
        // currency dies here.
        $this->shop(1, 'Wien', ['EUR', 'CHF'], ['3']);
        $this->price(3, 'EUR');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, ['method' => 'Post', 'shop' => 'Wien', 'currency' => 'CHF'], 1)],
            $this->issues(),
        );
    }

    public function testEinShopOhneErlaubteWaehrungMeldetKeineVersandart(): void
    {
        // Without the filter the key became "3|" and never matched — EVERY
        // flat rate reported a finding, with an empty currency placeholder in
        // the text. The cause is named by CurrencyMissing or
        // RootWithoutCurrency, not by the shipping costs.
        $this->shop(1, 'Wien', [], ['3']);

        self::assertSame([], $this->issues());
    }

    public function testDieWaehrungAmShopWirdUnabhaengigVonGrossKleinschreibungVerglichen(): void
    {
        // A migrated lower-case value from the old column `currency`
        // (task 3) is the same currency.
        $this->shop(1, 'Wien', ['eur'], ['3']);
        $this->price(3, 'EUR');

        self::assertSame([], $this->issues());
    }

    public function testDieWaehrungDerPreiszeileWirdUnabhaengigVonGrossKleinschreibungVerglichen(): void
    {
        // And the same question from the other side: the price row was created
        // through the form, but could just as well come from an import.
        $this->shop(1, 'Wien', ['EUR'], ['3']);
        $this->price(3, 'eur');

        self::assertSame([], $this->issues());
    }

    public function testDieReihenfolgeFolgtDemShoptitelNichtDerId(): void
    {
        // The titles deliberately contradict the id order: if the sorting
        // actually came from the id (or did not happen at all), this test
        // would have to show it.
        // PRAGMA reverse_unordered_selects: without ORDER BY SQLite otherwise
        // reads the table in ascending id order anyway — by chance not the
        // order expected here, but only by chance. Only with the PRAGMA does a
        // missing ORDER BY guarantee a different order (namely descending by
        // id), and the test fails reliably.
        $this->connection->executeStatement('PRAGMA reverse_unordered_selects = ON');

        $this->shop(1, 'Mitte', ['EUR'], ['3']);
        $this->shop(2, 'Abholung', ['EUR'], ['3']);
        $this->shop(3, 'Wien', ['EUR'], ['3']);

        self::assertSame(
            ['Abholung', 'Mitte', 'Wien'],
            array_map(static fn (ConfigurationIssue $issue): string => $issue->context['shop'], $this->issues()),
        );
    }

    /**
     * @param list<string> $currencies
     * @param list<string> $methods
     */
    private function shop(int $id, string $title, array $currencies, array $methods): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'title' => $title, 'currencies' => serialize($currencies), 'shippingMethods' => serialize($methods)]);
    }

    private function price(int $pid, string $currency, int $tstamp = 5): void
    {
        $this->connection->insert('tl_consho_shipping_price', ['pid' => $pid, 'tstamp' => $tstamp, 'currency' => $currency, 'net' => '4.0833']);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalShippingPriceIssueSource($this->connection, new ShopCurrencies($this->connection)))->issues();
    }
}
