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
        // `currency` steht bis zur Abnahme von C-1c noch in der Tabelle (die
        // Spalte fällt erst mit Step 10.4) und bleibt hier absichtlich LEER —
        // genau der Zustand, den der Plan am 2026-09-21 gemessen hat. Eine
        // Quelle, die sie weiterhin läse, bekäme '' und meldete jede Pauschale;
        // die Tests unten schlagen dann fehl, statt an einer fehlenden Spalte
        // zu scheitern und die eigentliche Aussage zu verdecken. Aus der DCA,
        // dort ebenfalls DEFAULT ''.
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
            // Block I: mit dem Shop, sonst steht der Befund bei jedem Shop.
            [new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, ['method' => 'Post', 'shop' => 'Wien', 'currency' => 'EUR'], 1)],
            $this->issues(),
        );
    }

    /**
     * E-4: Die Gewichtsstaffel braucht gespeicherte Stufen wie die Pauschale
     * ihren Betrag — ohne Zeile in der Währung ist sie nicht verfügbar.
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
        // Unveröffentlicht bietet der Checkout sie ohnehin nicht an.
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
        // Spec 11.3: sperrt diese Art (C-6) — nichts zum Wegklicken.
        self::assertTrue(ConfigurationFinding::ShippingPriceMissing->isWarning());
    }

    public function testUnvollstaendigGespeicherteArtenErzeugenKeinenBefund(): void
    {
        // act=toggle veröffentlicht ohne die Pflichtprüfungen der Maske: type
        // '' oder taxClass 0 heißt, die Zeile wurde nie gespeichert (Task 1,
        // Review) — dieselbe Bedingung wie im Checkout (DbalShippingMethodRepository::offerable()).
        $this->connection->insert('tl_consho_shipping_method', ['id' => 4, 'title' => 'Ohne Typ', 'published' => 1, 'type' => '', 'taxClass' => 1]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 5, 'title' => 'Ohne Steuerklasse', 'published' => 1, 'type' => 'flat_rate', 'taxClass' => 0]);
        $this->shop(1, 'Wien', ['EUR'], ['4', '5']);

        self::assertSame([], $this->issues());
    }

    public function testEineVollstaendigeArtEinesAnderenTypsOhneBetragWirdNichtGemeldet(): void
    {
        // Der Befund gilt nur für die Pauschale (Task 2, Review): ein
        // Frachtführer-Modul kann seinen Preis auch ohne gespeicherte Zeile
        // berechnen. Eine spätere Art, die eine Zeile braucht, muss sich hier
        // selbst eintragen (offener Punkt, erstes Frachtführer-Modul / C-6).
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'DHL', 'published' => 1, 'type' => 'dhl', 'taxClass' => 1]);
        $this->shop(1, 'Wien', ['EUR'], ['6']);

        self::assertSame([], $this->issues());
    }

    public function testDieZweiteErlaubteWaehrungOhneBetragWirdGemeldet(): void
    {
        // Der Shop nimmt beide an, die Pauschale hat nur in einer einen Betrag.
        // Vorher prüfte die Quelle nur gegen die EINE Shop-Währung und schwieg
        // über die andere — dieselbe Form wie der Fehler, den Task 4 in der
        // Vollständigkeitsquelle behoben hat.
        //
        // ⚠️ Bezahlt wird die alphabetisch ERSTE (ShopCurrencies sortiert),
        // gemeldet die zweite. Andersherum überlebte eine Fassung, die nur die
        // erste Währung prüft — gemessen am 2026-09-21.
        $this->shop(1, 'Wien', ['EUR', 'CHF'], ['3']);
        $this->price(3, 'CHF');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, ['method' => 'Post', 'shop' => 'Wien', 'currency' => 'EUR'], 1)],
            $this->issues(),
        );
    }

    public function testUndEbensoDieErsteErlaubteWaehrung(): void
    {
        // Die Gegenprobe zum Test darüber: Diesmal fehlt der Betrag in der
        // alphabetisch ersten. Eine Fassung, die nur die letzte Währung prüft,
        // stirbt hier.
        $this->shop(1, 'Wien', ['EUR', 'CHF'], ['3']);
        $this->price(3, 'EUR');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, ['method' => 'Post', 'shop' => 'Wien', 'currency' => 'CHF'], 1)],
            $this->issues(),
        );
    }

    public function testEinShopOhneErlaubteWaehrungMeldetKeineVersandart(): void
    {
        // Gemessen am 2026-09-21: Ohne Filter wurde der Schlüssel „3|" und traf
        // nie — JEDE Pauschale meldete einen Befund, im Text mit leerem
        // Währungsplatzhalter. Die Ursache nennt CurrencyMissing bzw.
        // RootWithoutCurrency, nicht die Versandkosten.
        $this->shop(1, 'Wien', [], ['3']);

        self::assertSame([], $this->issues());
    }

    public function testDieWaehrungAmShopWirdUnabhaengigVonGrossKleinschreibungVerglichen(): void
    {
        // Ein migrierter Kleinbuchstabe aus der alten Spalte `currency`
        // (Task 3) ist dieselbe Währung.
        $this->shop(1, 'Wien', ['eur'], ['3']);
        $this->price(3, 'EUR');

        self::assertSame([], $this->issues());
    }

    public function testDieWaehrungDerPreiszeileWirdUnabhaengigVonGrossKleinschreibungVerglichen(): void
    {
        // Und dieselbe Frage von der anderen Seite: Die Preiszeile ist über die
        // Maske entstanden, könnte aber ebenso aus einem Import stammen.
        $this->shop(1, 'Wien', ['EUR'], ['3']);
        $this->price(3, 'eur');

        self::assertSame([], $this->issues());
    }

    public function testDieReihenfolgeFolgtDemShoptitelNichtDerId(): void
    {
        // Die Titel widersprechen absichtlich der id-Reihenfolge: Käme die
        // Sortierung in Wahrheit von der id (oder gar nicht vor), müsste
        // dieser Test es zeigen.
        // PRAGMA reverse_unordered_selects: ohne ORDER BY liest SQLite die
        // Tabelle sonst ohnehin aufsteigend nach id — zufällig nicht die hier
        // erwartete Reihenfolge, aber eben nur zufällig. Erst mit der PRAGMA
        // liefert ein fehlendes ORDER BY garantiert eine andere (nämlich nach
        // id absteigende) Reihenfolge, und der Test scheitert zuverlässig.
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
