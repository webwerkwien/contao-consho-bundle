<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\RootCurrencyListener;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Gegen SQLite statt gegen einen Mock von ShopCurrencies: der Dienst ist final
 * und liest selbst, und genau sein Ergebnis — sortiert, entdoppelt, ohne stillen
 * Vorgabewert — entscheidet hier über Sichtbarkeit, Optionen und Ablehnung.
 */
class RootCurrencyListenerTest extends TestCase
{
    /**
     * So aufgebaut wie die echte Palette nach contao/dca/tl_page.php: die
     * Währung steht hinter dem Shop, nicht davor. Das ist keine Deko — beim
     * Speichern arbeitet DC_Table die Palettenfelder der Reihe nach ab.
     */
    private const PALETTE = '{title_legend},title,alias,type;{consho_legend},consho_shop,consho_currency,consho_cartPage;{publish_legend},published';

    private Connection $connection;

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = [
            'rootCurrencyNotAllowed' => 'nicht erlaubt: %1$s im Shop %2$s',
            'rootCurrencyWithoutShop' => 'kein Shop, keine Währung: %1$s',
        ];

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Nur die beiden Spalten, die ShopCurrencies liest — wie in
        // ShopCurrenciesTest.
        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']['CONSHO']);
    }

    /**
     * Fall 1: Ein Shop mit genau einer Währung stellt nichts zur Wahl. Das Feld
     * verschwindet aus der Palette, und der Wert kommt trotzdem an — sonst
     * stünde der Startpunkt ohne Währung da, weil zu einem Feld außerhalb der
     * Palette auch kein Speicher-Callback läuft.
     */
    public function testEineEinzigeWaehrungBlendetDasFeldAusUndSetztDenWertBeimSpeichern(): void
    {
        $this->insertShop(1, serialize(['CHF']));

        self::assertStringNotContainsString(
            'consho_currency',
            $this->listener()->adjustPalette(self::PALETTE, $this->dc(7, 1)),
        );

        $values = $this->listener()->onBeforeSubmit(['consho_shop' => 1], $this->dc(7, 1));

        self::assertSame('CHF', $values['consho_currency']);
    }

    /**
     * Fall 2: Mehrere Währungen sind eine Wahl, also gibt es ein Feld — und
     * darin genau die Währungen dieses Shops, sortiert. Nicht die ganze
     * ISO-Liste: die steht am Shop, weil dort festgelegt wird, was möglich ist.
     */
    public function testMehrereWaehrungenZeigenDasFeldMitGenauDiesenOptionen(): void
    {
        $this->insertShop(1, serialize(['USD', 'EUR', 'CHF']));

        self::assertStringContainsString(
            'consho_currency',
            $this->listener()->adjustPalette(self::PALETTE, $this->dc(7, 1)),
        );

        self::assertSame(
            ['CHF' => 'CHF', 'EUR' => 'EUR', 'USD' => 'USD'],
            $this->listener()->options($this->dc(7, 1)),
        );
    }

    /**
     * Fall 3: Der eigentliche Riegel. Die Optionsliste allein hält nichts auf —
     * Contao hängt einen gespeicherten Wert außerhalb der Optionen als
     * „Unknown option" wieder an und nimmt ihn beim Speichern zurück
     * (SelectMenu::generate()). Die Meldung nennt die Währung, sonst sucht die
     * Redakteurin sie in einer Liste, in der sie gerade nicht mehr steht.
     */
    public function testEineNichtMehrErlaubteWaehrungWirdBeimSpeichernAbgelehnt(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        // Die Meldung nennt auch den Shop: Wer mehrere Konfigurationen führt,
        // kann mit „der Shop dieses Startpunkts" nichts anfangen.
        $this->expectExceptionMessage('nicht erlaubt: USD im Shop Shop 1');

        $this->listener()->save('USD', $this->dc(7, 1, 'USD'));
    }

    /**
     * Der Schreibpfad des sichtbaren Feldes. Ohne diesen Test überlebte ein
     * `return '';` die ganze Suite — und leerte bei jedem Speichern eines
     * Mehrwährungs-Startpunkts still die Währung, die die Redakteurin gerade
     * gewählt hat. Nachsetzen würde sie niemand: Bei zwei oder mehr Währungen
     * wählt `resolve()` ausdrücklich nicht.
     */
    public function testEineErlaubteWaehrungWirdDurchgereicht(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame('EUR', $this->listener()->save('EUR', $this->dc(7, 1)));
    }

    /**
     * Leer geht durch. Ein leeres Pflichtfeld erreicht den Speicher-Callback
     * gar nicht — der Zweig zählt erst, wenn das Feld seine Pflicht verliert.
     * Ohne ihn würde eine leere Eingabe als unbekannte Währung abgelehnt.
     */
    public function testEinLeererWertWirdNichtAlsUnbekannteWaehrungAbgelehnt(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame('', $this->listener()->save('', $this->dc(7, 1)));
    }

    /**
     * Die Abweichung vom Vorbild `saveCurrency()`, das nicht normalisiert:
     * Die Migration aus Schritt 3 schreibt diese Spalte unmittelbar, nie durch
     * eine Maske. `ShopCurrencies` schreibt die Shop-Seite aus genau diesem
     * Grund groß — ohne dieselbe Behandlung hier würde ein übernommenes „eur"
     * als unbekannt abgelehnt, obwohl der Shop EUR annimmt.
     */
    public function testEinKleingeschriebenerCodeWirdGrossGeschriebenStattAbgelehnt(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame('EUR', $this->listener()->save(' eur ', $this->dc(7, 1)));
    }

    /**
     * Contao ruft einen options_callback auch ohne Datensatz, etwa für den
     * Filter über der Seitenliste. Ohne Startpunkt gibt es keinen Shop und
     * damit nichts anzubieten — aber auch keinen Fehler.
     */
    public function testOhneDatensatzGibtEsKeineOptionenUndKeinenFehler(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame([], $this->listener()->options());
    }

    /**
     * Über „mehrere bearbeiten → überschreiben" gibt es gar keine Palette
     * (`DC_Table::overrideAll()` ruft `getPalette()` nicht), die Währung kann
     * also auch auf einer Seite **ohne** Shop landen. Dann darf die Meldung
     * keinen Shop behaupten, den es nicht gibt.
     */
    public function testOhneShopNenntDieMeldungKeinenShop(): void
    {
        $this->expectExceptionMessage('kein Shop, keine Währung: EUR');

        $this->listener()->save('EUR', $this->dc(7, 0));
    }

    /**
     * Der Grund für die Feldreihenfolge, und der einzige Test, der ihn
     * einfangen kann: Die Feld-Callbacks müssen den **gerade gewählten** Shop
     * sehen, nicht den zuletzt gespeicherten. Auf `getCurrentRecord()` fällt
     * dieser Test um, auf `getActiveRecord()` steht er.
     */
    public function testDieOptionenFolgenDemGeradeGewaehltenShop(): void
    {
        $this->insertShop(1, serialize(['EUR']));
        $this->insertShop(2, serialize(['USD', 'GBP']));

        self::assertSame(
            ['GBP' => 'GBP', 'USD' => 'USD'],
            $this->listener()->options($this->dc(7, 1, 'EUR', 2)),
        );
    }

    /**
     * Dieselbe Verwechslung auf dem Schreibweg: Die alte Währung EUR gehört
     * zum alten Shop 1 und ist im gerade gewählten Shop 2 nicht mehr zulässig.
     * Am gespeicherten Satz gemessen ginge sie durch — und der Startpunkt
     * stünde mit einer Währung da, die sein Shop nicht annimmt.
     */
    public function testDasSpeichernPrueftGegenDenGeradeGewaehltenShop(): void
    {
        $this->insertShop(1, serialize(['EUR']));
        $this->insertShop(2, serialize(['USD', 'GBP']));

        $this->expectExceptionMessage('nicht erlaubt: EUR im Shop Shop 2');

        $this->listener()->save('EUR', $this->dc(7, 1, 'EUR', 2));
    }

    /**
     * Fall 4: Ein Startpunkt ohne Shop-Konfiguration hat keine Währung, die
     * irgendwo gälte. Das Feld ist dann nicht leer, sondern gar nicht da.
     */
    public function testEinStartpunktOhneShopHatDasFeldNicht(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertStringNotContainsString(
            'consho_currency',
            $this->listener()->adjustPalette(self::PALETTE, $this->dc(7, 0)),
        );
    }

    /**
     * Ohne Datensatz ist kein Shop bekannt, also gilt keine Währung, also kein
     * Feld — dieselbe Richtung wie überall sonst in der Klasse. Ein
     * Abbruch-Wächter wie in `tl_files::adjustPalettes()` hätte das Gegenteil
     * bewirkt: zeigen, wo am wenigsten bekannt ist.
     */
    public function testOhneDatensatzVerschwindetDasFeld(): void
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('getCurrentRecord')->willReturn(null);

        self::assertStringNotContainsString('consho_currency', $this->listener()->adjustPalette(self::PALETTE, $dc));
    }

    /**
     * Der Callback hängt an tl_page und läuft damit beim Speichern **jeder**
     * Seite. Eine gewöhnliche Unterseite trägt keinen Shop und darf deshalb
     * unberührt bleiben — auch ohne eine einzige Abfrage.
     *
     * ⚠️ Die fehlende Tabelle ist der eigentliche Prüfstein, nicht Kulisse:
     * Ohne den frühen Ausstieg fragte ShopCurrencies auch für Shop 0 die
     * Datenbank und liefe hier in einen Fehler. Mit der Tabelle wäre der Test
     * zahnlos — das Ergebnis wäre über beide Wege dasselbe (gemessen).
     */
    public function testEineSeiteOhneShopWirdNichtAngefasst(): void
    {
        $this->connection->executeStatement('DROP TABLE tl_consho_shop');

        self::assertSame(['title' => 'Impressum'], $this->listener()->onBeforeSubmit(['title' => 'Impressum'], $this->dc(7, 0)));
    }

    /**
     * Wird dem Startpunkt der Shop genommen, gilt auch seine Währung nicht
     * mehr. Sie bliebe sonst als unsichtbarer Rest stehen: Das Feld ist ohne
     * Shop ausgeblendet, niemand sähe den Wert je wieder.
     */
    public function testMitDemShopFaelltAuchDieWaehrung(): void
    {
        $this->insertShop(1, serialize(['CHF']));

        $values = $this->listener()->onBeforeSubmit(['consho_shop' => 0], $this->dc(7, 1, 'CHF'));

        self::assertSame('', $values['consho_currency']);
    }

    /**
     * Der gespeicherte Wert wird gelesen, nicht nur der eingereichte: Eine
     * Änderung an irgendeinem anderen Feld darf eine gültige Währung nicht
     * verlieren.
     */
    public function testEineGespeicherteWaehrungUeberlebtEineFremdeAenderung(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame(['title' => 'Start'], $this->listener()->onBeforeSubmit(['title' => 'Start'], $this->dc(7, 1, 'CHF')));
    }

    /**
     * `valueOf()` nimmt ein Array. Ohne den Rückfall auf `[]` wäre ein
     * fehlender Datensatz kein leerer Wert, sondern ein Typfehler mitten im
     * Speichervorgang.
     */
    public function testOhneDatensatzScheitertDasSpeichernNicht(): void
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('getCurrentRecord')->willReturn(null);

        self::assertSame(['title' => 'Start'], $this->listener()->onBeforeSubmit(['title' => 'Start'], $dc));
    }

    private function listener(): RootCurrencyListener
    {
        return new RootCurrencyListener(new ShopCurrencies($this->connection));
    }

    /**
     * ⚠️ Das Doppel muss unterscheiden, was das Original unterscheidet.
     *
     * `getCurrentRecord()` ist der **gespeicherte** Satz, `getActiveRecord()`
     * derselbe Satz überlagert mit dem, was bis hierher eingereicht wurde
     * (`DC_Table::getActiveRecord()` = `array_merge(getCurrentRecord(),
     * arrSubmit)`). Gäben beide dasselbe zurück, wäre die ganze Testklasse
     * blind für die Verwechslung — und genau die Verwechslung ist der Grund,
     * warum `consho_shop` in der Palette vor `consho_currency` steht.
     *
     * `$submittedShop` ist der gerade in der Maske gewählte Shop, der noch
     * nicht gespeichert ist. Ohne Angabe sind beide gleich: so sieht ein
     * Aufruf aus, bei dem der Shop nicht angefasst wurde.
     */
    private function dc(int $id, int $shopId, string $currency = '', int|null $submittedShop = null): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['id', $id]]);

        $stored = ['id' => $id, 'consho_shop' => $shopId, 'consho_currency' => $currency];

        $dc->method('getCurrentRecord')->willReturn($stored);
        $dc->method('getActiveRecord')->willReturn(['consho_shop' => $submittedShop ?? $shopId] + $stored);

        return $dc;
    }

    private function insertShop(int $id, string|null $currencies): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'title' => 'Shop '.$id, 'currencies' => $currencies]);
    }
}
