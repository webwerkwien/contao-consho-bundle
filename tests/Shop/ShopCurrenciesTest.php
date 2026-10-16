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

        // Nur die beiden Spalten, um die es hier geht. Das gemeinsame
        // Katalogschema bildet die ganze Tabelle nach; für diesen Dienst wäre
        // das mehr Kulisse als Aussage.
        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    public function testDieErlaubtenWaehrungenKommenSortiertUndOhneDoppelte(): void
    {
        $this->insertShop(1, serialize(['USD', 'CHF', 'EUR', 'CHF']));

        self::assertSame(['CHF', 'EUR', 'USD'], $this->shopCurrencies()->of(1));
    }

    /**
     * Kleinschreibung gehört hierher, nicht nur in den Listener-Test: Nach
     * Task 3 sieht dieser Dienst **migrierte** Werte, die nie durch den
     * Speicher-Callback gelaufen sind. Ein Kleinbuchstabe aus der alten Spalte
     * `currency` würde sonst als unbekannter Code verworfen — und der Shop
     * stünde ohne Währung da.
     */
    public function testKleingeschriebeneCodesZaehlen(): void
    {
        $this->insertShop(1, serialize(['chf', 'eur', 'EUR']));

        self::assertSame(['CHF', 'EUR'], $this->shopCurrencies()->of(1));
    }

    /**
     * Der Kern dieser Klasse: kein stiller Vorgabewert.
     *
     * Ein Shop ohne Auswahl liefert eine leere Liste, ausdrücklich nicht
     * ['EUR'] — sonst verkaufte eine Fehlkonfiguration stillschweigend in Euro.
     * Siehe docs/block-a-fundament.md, Abschnitt 7.
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
     * Ein abgeschnittener oder gar nicht serialisierter Blob ist kein Wert.
     * StringUtil::deserialize() macht daraus sonst eine einelementige Liste mit
     * dem Rohtext darin — die käme als „Währung" durch.
     */
    public function testEinUnlesbarerBlobErgibtKeineWaehrung(): void
    {
        $this->insertShop(1, 'a:2:{i:0;s:3:"EUR');
        $this->insertShop(2, 'EURO');
        $this->insertShop(3, serialize(['XYZ', 'EUR']));

        $shopCurrencies = $this->shopCurrencies();

        self::assertSame([], $shopCurrencies->of(1));
        self::assertSame([], $shopCurrencies->of(2));
        // Nur der gültige Code bleibt übrig.
        self::assertSame(['EUR'], $shopCurrencies->of(3));
    }

    public function testEinUnbekannterShopHatKeineWaehrung(): void
    {
        $this->insertShop(1, serialize(['EUR']));

        self::assertSame([], $this->shopCurrencies()->of(404));
    }

    /**
     * Je Request einmal gelesen: die Maske fragt aus mehreren Callbacks.
     * Gemessen an der Wirkung statt an der Anzahl der Abfragen — nach einer
     * Änderung in der DB muss die alte Antwort stehen bleiben.
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
     * Die Auflösungsregel — dieselbe drei Zeilen wie
     * `HomeCountries::resolve()`, und aus demselben Grund am Dienst statt am
     * DCA-Listener: Die Migration aus C-1c Schritt 3 und der Befund aus
     * Schritt 7 brauchen sie genauso.
     */
    public function testEineEinzigeWaehrungGiltOhneZutun(): void
    {
        $this->insertShop(1, serialize(['CHF']));

        self::assertSame('CHF', $this->shopCurrencies()->resolve(1, ''));

        // Auch gegen eine gespeicherte, die der Shop nicht mehr führt.
        self::assertSame('CHF', $this->shopCurrencies()->resolve(1, 'EUR'));
    }

    public function testEineWeiterhinErlaubteWaehrungBleibtStehen(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF', 'USD']));

        self::assertSame('CHF', $this->shopCurrencies()->resolve(1, 'CHF'));
    }

    /**
     * ⚠️ Der Kern: Wo der Shop eine Wahl lässt, wählt der Dienst **nicht**.
     * Ein stilles `$currencies[0]` wäre die Rückfall-Vorgabe, die Abschnitt 7
     * ausschließt — hier entschiede die alphabetische Sortierung, in welcher
     * Währung ein Startpunkt verkauft.
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
     * Der Titel für die Fehlermeldung. Leer für einen unbekannten Shop — daran
     * unterscheidet der Listener „Shop nimmt die Währung nicht an" von „diese
     * Seite hat gar keinen Shop".
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
