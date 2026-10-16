<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tax\TaxClassReferences;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Subscription\SubscriptionSchema;

class TaxClassReferencesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['tl_consho_tax_class', 'tl_consho_product', 'tl_consho_shipping_method', 'tl_consho_payment_method'] as $table) {
            DcaSchema::create($this->connection, $table);
        }
        SubscriptionSchema::create($this->connection);

        $this->connection->insert('tl_consho_tax_class', ['id' => 1, 'title' => 'Allgemeine Waren']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testOhneVerwaisteVerweiseMeldetEsNichts(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Wanderstock', 'taxClass' => 1]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 1]);

        self::assertSame([], $this->references()->orphans());
    }

    public function testEinVerweisAufEineGeloeschteKlasseWirdGenannt(): void
    {
        // Der Weg, den der Voter nicht schließen kann: das Produkt war vor der
        // Klasse gelöscht und kam per Papierkorb danach zurück.
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Wanderstock', 'taxClass' => 99]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 99]);

        self::assertSame(
            [
                ['table' => 'tl_consho_product', 'id' => 5, 'title' => 'Wanderstock', 'taxClass' => 99],
                ['table' => 'tl_consho_shipping_method', 'id' => 6, 'title' => 'Paket', 'taxClass' => 99],
            ],
            $this->references()->orphans(),
        );
    }

    /**
     * 🔴 **Der erste Entwurf nahm `taxClass = 0` aus**, mit der Begründung,
     * ein frischer Katalog würde den Abschnitt sonst mit Zeilen füllen — und
     * mit einem Verweis auf einen „Pflichtfeld-Befund", den es im Code nicht
     * gibt. Beides fiel im Fremdmodell-Review vom 2026-09-23 auf.
     *
     * Nachgemessen war die Begründung doppelt falsch: Das Feld ist in beiden
     * Masken `mandatory`, eine über das Backend gespeicherte Zeile kann also
     * gar nicht 0 sein; und eine Variante trägt ihre **eigene** Klasse, erbt
     * also keine (auf c5: `pid = 1`, `taxClass = 2`). Es gibt kein
     * berechtigtes 0, mit dem Lärm entstehen könnte — ein 0 kommt aus einem
     * Import, aus rohem SQL oder aus einer Installation von vor dem Feld.
     *
     * Und der Schaden ist derselbe: `findRate()` antwortet für 0 wie für 99
     * mit `null`.
     */
    public function testAuchEineLeereZuordnungIstEinVerwaisterVerweis(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Ohne Klasse', 'taxClass' => 0]);

        self::assertSame(
            [['table' => 'tl_consho_product', 'id' => 5, 'title' => 'Ohne Klasse', 'taxClass' => 0]],
            $this->references()->orphans(),
        );
    }

    /**
     * Für die Versandart wiegt es schwerer als für das Produkt:
     * `DbalShippingMethodRepository` filtert `taxClass > 0`, die Art
     * verschwindet also aus der Auswahl, ohne dass irgendwo etwas steht.
     */
    public function testEineVersandartOhneKlasseWirdEbenfallsGenannt(): void
    {
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 0]);

        self::assertSame(
            [['table' => 'tl_consho_shipping_method', 'id' => 6, 'title' => 'Paket', 'taxClass' => 0]],
            $this->references()->orphans(),
        );
    }

    /**
     * E-1: Eine Zahlungsart braucht eine Gebühren-Steuerklasse nur bei einer
     * festen Gebühr. Ihre 0 ist „keine", kein verwaister Verweis — eine
     * gelöschte Klasse dagegen schon, und sie hält die Klasse fest.
     */
    public function testEineZahlungsartOhneGebuehrenklasseIstNichtVerwaist(): void
    {
        $this->connection->insert('tl_consho_payment_method', ['id' => 3, 'title' => 'Vorkasse', 'feeTaxClass' => 0]);
        $this->connection->insert('tl_consho_payment_method', ['id' => 4, 'title' => 'Nachnahme', 'feeTaxClass' => 99]);
        $this->connection->insert('tl_consho_payment_method', ['id' => 5, 'title' => 'Nachnahme AT', 'feeTaxClass' => 1]);

        $orphans = array_values(array_filter($this->references()->orphans(), static fn (array $o): bool => 'tl_consho_payment_method' === $o['table']));

        self::assertSame([['table' => 'tl_consho_payment_method', 'id' => 4, 'title' => 'Nachnahme', 'taxClass' => 99]], $orphans);
        self::assertTrue($this->references()->isInUse(1), 'Die Gebühren-Klasse einer Zahlungsart hält die Klasse fest.');
    }

    /**
     * Abo-3: Das Abo friert die Klasse ein, nicht den Satz (A9). Jede
     * Folgeabrechnung fragt die Klasse neu — ohne sie keine Rechnung.
     */
    public function testEinAboHaeltSeineSteuerklasse(): void
    {
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'taxClass' => 1]);

        self::assertTrue($this->references()->isInUse(1));
    }

    /**
     * Abo-6 (P51): Ein beendetes Abo wird nie wieder abgerechnet — es hält
     * die Klasse nicht mehr und ist auch keine Waise. Ruhend und gekündigt
     * laufen noch.
     */
    public function testNurEinLaufendesAboHaeltDieKlasse(): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => 4, 'subscriptionNumber' => 'WEB-00090-1', 'taxClass' => 1, 'state' => 'ended']);
        $this->connection->insert('tl_consho_subscription', ['id' => 5, 'subscriptionNumber' => 'WEB-00091-1', 'taxClass' => 99, 'state' => 'ended']);

        self::assertFalse($this->references()->isInUse(1));
        self::assertSame([], $this->references()->orphans());

        foreach (['suspended', 'cancelled'] as $state) {
            $this->connection->update('tl_consho_subscription', ['state' => $state], ['id' => 4]);
            self::assertTrue($this->references()->isInUse(1), $state);
        }
    }

    /**
     * Das Abo hat keine Spalte `title` — die Abfrage nennt es bei seiner Nummer.
     */
    public function testEinVerwaistesAboWirdMitSeinerNummerGenannt(): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => 4, 'subscriptionNumber' => 'WEB-00090-1', 'taxClass' => 99]);

        self::assertSame(
            [['table' => 'tl_consho_subscription', 'id' => 4, 'title' => 'WEB-00090-1', 'taxClass' => 99]],
            $this->references()->orphans(),
        );
    }

    public function testInBenutzungFragtBeideTabellen(): void
    {
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 1]);

        self::assertTrue($this->references()->isInUse(1));
        self::assertFalse($this->references()->isInUse(2));
        // Keine Abfrage für eine ID, die es nicht geben kann.
        self::assertFalse($this->references()->isInUse(0));
    }

    /**
     * CW-5: wer die Klasse hält, für eine Ablehnung, die ihn nennt — höchstens
     * so viele wie verlangt, über alle Tabellen zusammen.
     */
    public function testUsedByNenntHoechstensDieVerlangteZahl(): void
    {
        foreach (range(1, 4) as $id) {
            $this->connection->insert('tl_consho_product', ['id' => $id, 'title' => 'P'.$id, 'taxClass' => 1]);
        }

        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 1]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 7, 'title' => 'Express', 'taxClass' => 1]);
        $this->connection->insert('tl_consho_subscription', ['id' => 8, 'subscriptionNumber' => 'WEB-1', 'taxClass' => 1]);

        self::assertSame(
            ['tl_consho_product 1', 'tl_consho_product 2', 'tl_consho_product 3', 'tl_consho_product 4', 'tl_consho_shipping_method 6'],
            $this->references()->usedBy(1),
        );
        self::assertSame(['tl_consho_product 1', 'tl_consho_product 2'], $this->references()->usedBy(1, 2));
    }

    public function testUsedByOhneKlasse(): void
    {
        // Ein Produkt ohne Klasse hält keine „Klasse 0" fest.
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Ohne', 'taxClass' => 0]);

        self::assertSame([], $this->references()->usedBy(0));
    }

    /**
     * 🔴 **Die Liste der verweisenden Tabellen darf keine Behauptung sein.**
     *
     * Sie steht als Konstante in der Klasse, und eine Konstante, die niemand
     * prüft, liest sich wie Wissen — derselbe Fehler wie F7 im Review vom
     * 2026-09-23, wo zwei von drei geprüften Schlüsseln ins Leere sahen.
     *
     * Hier kommt sie aus den DCAs selbst: jedes Feld, dessen `foreignKey`
     * diese Tabelle nennt. Kommt eine dritte Tabelle mit einer Steuerklasse
     * dazu, scheitert dieser Test und nennt sie, statt dass der Löschschutz
     * still weniger deckt, als er behauptet.
     *
     * ⚠️ **Die Sätze sind ausgenommen**, und zwar begründet, nicht
     * weggelassen: `tl_consho_tax_rate.pid` nennt dieselbe Tabelle, aber die
     * Sätze sind Kindzeilen (`ptable`) und gehen mit der Klasse in den
     * Papierkorb. Sie festzuhalten hieße, eine Klasse mit Sätzen wäre nie
     * löschbar — also praktisch keine.
     */
    public function testDieListeDerVerweisendenTabellenIstVollstaendig(): void
    {
        $found = [];

        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            unset($GLOBALS['TL_DCA']);

            // Siehe EveryDcaSurvivesContaoMigrationsTest: zwei Dateien
            // erweitern Kerntabellen und greifen nach einer Palette, die
            // Contao deklariert.
            $GLOBALS['TL_DCA']['tl_member']['palettes']['default'] = '{personal_legend},firstname,lastname;';
            $GLOBALS['TL_DCA']['tl_page']['palettes']['root'] = '{title_legend},title,alias;';
            $GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback'] = '{title_legend},title,alias;';
            $GLOBALS['TL_DCA']['tl_module']['palettes']['registration'] = '{title_legend},name,type;{email_legend},reg_activate;';

            require $file;

            foreach ($GLOBALS['TL_DCA'] ?? [] as $table => $definition) {
                $childOf = 'tl_consho_tax_class' === ($definition['config']['ptable'] ?? null);

                foreach ($definition['fields'] ?? [] as $field => $config) {
                    /*
                     * Nur `pid` einer Kindtabelle ist ausgenommen, nicht die
                     * ganze Tabelle — sonst fiele ein zweites Feld daneben
                     * still aus der Prüfung. Im Review vom 2026-09-23 als zu
                     * weit gefasst erkannt.
                     */
                    if ($childOf && 'pid' === $field) {
                        continue;
                    }

                    if (str_starts_with((string) ($config['foreignKey'] ?? ''), 'tl_consho_tax_class.')) {
                        $found[$table] = $field;
                    }
                }
            }
        }

        ksort($found);
        $expected = TaxClassReferences::REFERENCES;
        ksort($expected);

        self::assertSame(
            $expected,
            $found,
            'TaxClassReferences::REFERENCES and the DCAs disagree about who points at a tax class. '
            .'A table missing from the constant is a table the delete guard does not protect.',
        );
    }

    private function references(): TaxClassReferences
    {
        return new TaxClassReferences($this->connection);
    }
}
