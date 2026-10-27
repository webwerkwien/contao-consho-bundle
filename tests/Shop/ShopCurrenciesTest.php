<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shop;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class ShopCurrenciesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Only the two columns that matter here. The shared catalogue schema
        // reproduces the whole table; for this service that would be more
        // scenery than statement.
        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    public function testDieErlaubtenWaehrungenKommenSortiertUndOhneDoppelte(): void
    {
        $this->insertShop(1, serialize(['USD', 'CHF', 'EUR', 'CHF']));

        self::assertSame(['CHF', 'EUR', 'USD'], $this->shopCurrencies()->of(1));
    }

    /**
     * Lower case belongs here, not only in the listener test: after task 3
     * this service sees **migrated** values that never ran through the save
     * callback. A lower-case letter from the old column `currency` would
     * otherwise be discarded as an unknown code — and the shop would be left
     * without a currency.
     */
    public function testKleingeschriebeneCodesZaehlen(): void
    {
        $this->insertShop(1, serialize(['chf', 'eur', 'EUR']));

        self::assertSame(['CHF', 'EUR'], $this->shopCurrencies()->of(1));
    }

    /**
     * The core of this class: no silent default.
     *
     * A shop without a selection returns an empty list, explicitly not
     * ['EUR'] — otherwise a misconfiguration would silently sell in euros.
     * See Block A, section 7.
     */
    public function testEinLeererBlobErgibtKeineWaehrung(): void
    {
        $this->insertShop(1, null);
        $this->insertShop(2, '');
        $this->insertShop(3, serialize([]));
        $this->insertShop(4, serialize(['']));

        $shopCurrencies = $this->shopCurrencies();

        self::assertSame([], $shopCurrencies->of(1));
        self::assertSame([], $shopCurrencies->of(2));
        self::assertSame([], $shopCurrencies->of(3));
        self::assertSame([], $shopCurrencies->of(4));
    }

    /**
     * A truncated or not serialised blob at all is not a value.
     * StringUtil::deserialize() would otherwise turn it into a one-element list
     * with the raw text in it — which would come through as a "currency".
     */
    public function testEinUnlesbarerBlobErgibtKeineWaehrung(): void
    {
        $this->insertShop(1, 'a:2:{i:0;s:3:"EUR');
        $this->insertShop(2, 'EURO');
        $this->insertShop(3, serialize(['XYZ', 'EUR']));

        $shopCurrencies = $this->shopCurrencies();

        self::assertSame([], $shopCurrencies->of(1));
        self::assertSame([], $shopCurrencies->of(2));
        // Only the valid code remains.
        self::assertSame(['EUR'], $shopCurrencies->of(3));
    }

    public function testEinUnbekannterShopHatKeineWaehrung(): void
    {
        $this->insertShop(1, serialize(['EUR']));

        self::assertSame([], $this->shopCurrencies()->of(404));
    }

    /**
     * Read once per request: the form asks from several callbacks.
     * Measured by the effect rather than by the number of queries — after a
     * change in the DB the old answer must remain.
     */
    public function testDieShopsWerdenJeRequestNurEinmalGelesen(): void
    {
        $this->insertShop(1, serialize(['EUR']));

        $shopCurrencies = $this->shopCurrencies();

        self::assertSame(['EUR'], $shopCurrencies->of(1));

        $this->connection->executeStatement('UPDATE tl_consho_shop SET currencies = ?', [serialize(['CHF'])]);

        self::assertSame(['EUR'], $shopCurrencies->of(1));
    }

    public function testNachDemZuruecksetzenWerdenSieNeuGelesen(): void
    {
        $this->insertShop(1, serialize(['EUR']));

        $shopCurrencies = $this->shopCurrencies();
        $shopCurrencies->of(1);

        $this->connection->executeStatement('UPDATE tl_consho_shop SET currencies = ?', [serialize(['CHF'])]);
        $shopCurrencies->reset();

        self::assertSame(['CHF'], $shopCurrencies->of(1));
    }

    /**
     * The resolution rule — the same three lines as
     * `HomeCountries::resolve()`, and for the same reason on the service rather
     * than on the DCA listener: the migration from C-1c step 3 and the finding
     * from step 7 need it just as much.
     */
    public function testEineEinzigeWaehrungGiltOhneZutun(): void
    {
        $this->insertShop(1, serialize(['CHF']));

        self::assertSame('CHF', $this->shopCurrencies()->resolve(1, ''));

        // Also against a stored one that the shop no longer carries.
        self::assertSame('CHF', $this->shopCurrencies()->resolve(1, 'EUR'));
    }

    public function testEineWeiterhinErlaubteWaehrungBleibtStehen(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF', 'USD']));

        self::assertSame('CHF', $this->shopCurrencies()->resolve(1, 'CHF'));
    }

    /**
     * ⚠️ The core: where the shop leaves a choice, the service does **not**
     * choose. A silent `$currencies[0]` would be the fallback default that
     * section 7 rules out — here the alphabetical sorting would decide in
     * which currency a start point sells.
     */
    public function testBeiMehrerenWaehrungenWirdNichtStillGewaehlt(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertNull($this->shopCurrencies()->resolve(1, 'USD'));
        self::assertNull($this->shopCurrencies()->resolve(1, ''));
    }

    public function testEinUnbekannterShopLoestNichtsAuf(): void
    {
        self::assertNull($this->shopCurrencies()->resolve(99, 'EUR'));
    }

    /**
     * The title for the error message. Empty for an unknown shop — that is how
     * the listener tells "the shop does not accept the currency" from "this
     * page has no shop at all".
     */
    public function testDerTitelKommtAusDerselbenAbfrage(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wiener Wandern', 'currencies' => serialize(['EUR'])]);

        self::assertSame('Wiener Wandern', $this->shopCurrencies()->titleOf(1));
        self::assertSame('', $this->shopCurrencies()->titleOf(99));
    }

    private function shopCurrencies(): ShopCurrencies
    {
        return new ShopCurrencies($this->connection);
    }

    private function insertShop(int $id, string|null $currencies): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'currencies' => $currencies]);
    }
}
