<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Ein SQLite-Testschema, das der echten Datenbank gleicht — nicht nur
 * „mindestens so streng". Am 2026-09-13 hat genau die Abweichung zweimal einen
 * echten Fehler verdeckt: `DbalProductTileRepositoryTest` legte
 * `safetyWarnings`/`safetyInformation` als `TEXT NOT NULL DEFAULT ""` an,
 * während die DB `longtext NULL DEFAULT NULL` hat — das fehlende COALESCE in
 * VisibilityCriteria fiel deshalb nicht auf. Ebenso hätte ein `INTEGER` für
 * `start`/`stop` (tatsächlich `varchar(10) DEFAULT ''`) einen Vergleich gegen
 * eine leere Zeichenkette verdeckt.
 *
 * Bis 2026-10-07 waren die Spalten von Hand abgeschrieben (`SHOW COLUMNS` auf
 * dem Testserver am 2026-09-13). Seither baut DcaSchema jede Consho-Tabelle aus
 * ihrer DCA (`contao/dca/tl_consho_*.php`) — eine neue Spalte kommt so ohne
 * Nachtrag hier an (Prüfungsnotiz, Fall 31/57/62). Die SQLite-Typwahl steht in
 * DcaSchema::column(): `NUMERIC` für `decimal`, weil SQLite TEXT
 * lexikographisch vergleicht („10" stünde vor „8.33").
 *
 * Von Hand bleibt nur `tl_member_group` — Contaos Tabelle, für die es keine
 * DCA im Bundle gibt.
 *
 * Die Einfüger schreiben **nur** die per `$overrides` gesetzten Spalten.
 * Ungeschriebene Spalten tragen dadurch wirklich `NULL` (wo die Spalte das
 * zulässt) bzw. den in `CREATE TABLE` hinterlegten Vorgabewert — nicht einen
 * von PHP aus mitgeschickten Nullwert, der das verdecken würde.
 */
trait CatalogSchema
{
    private Connection $catalogConnection;

    /**
     * Legt alle Katalog-Tabellen an, die Task 3 (Plan B-5) benennt. Vor jedem
     * Aufruf eines Einfügers muss diese Methode gelaufen sein.
     */
    protected function createCatalogSchema(Connection $c): void
    {
        $this->catalogConnection = $c;

        // ── tl_consho_product ───────────────────────────────────────────────
        // Produkte UND Varianten (pid != 0). Stand DB: alias/sku tragen nur
        // einen einfachen Index — die Eindeutigkeit von `alias` ist Contaos
        // `eval.unique`, kein Datenbank-Constraint (siehe tl_consho_product.php).
        DcaSchema::create($c, 'tl_consho_product');

        // Block G: the stock, from its DCA (Fall 31) — every test with a
        // catalogue can then keep stock for an article.
        DcaSchema::create($c, 'tl_consho_stock');
        DcaSchema::create($c, 'tl_consho_stock_movement');

        // ── tl_consho_product_option ────────────────────────────────────────
        // Variantenachse: welche Option MACHT diese Zeile zur Variante.
        DcaSchema::create($c, 'tl_consho_product_option');

        // ── tl_consho_product_property ──────────────────────────────────────
        // Filterbare Eigenschaften des HAUPTPRODUKTS — bewusst eine zweite
        // Tabelle statt einer Rollenspalte, siehe DCA-Kommentar dort.
        DcaSchema::create($c, 'tl_consho_product_property');

        // ── tl_consho_property_group / _option, product_type, manufacturer ──
        DcaSchema::create($c, 'tl_consho_property_group');
        DcaSchema::create($c, 'tl_consho_property_option');
        DcaSchema::create($c, 'tl_consho_product_type');
        DcaSchema::create($c, 'tl_consho_manufacturer');

        // ── tl_consho_translation ───────────────────────────────────────────
        // `alias` NULL-bar ohne Vorgabewert (so die DCA): zwei Gruppen/Optionen
        // ohne Alias dürfen nebeneinander bestehen, weil SQLite — wie MySQL —
        // NULL in einem UNIQUE-Index nie mit einem anderen NULL kollidieren lässt.
        DcaSchema::create($c, 'tl_consho_translation');

        // ── tl_consho_price ─────────────────────────────────────────────────
        // Die eigentliche Preistabelle (Herkunft `List`/`Scale`/`CustomerGroup`
        // in PriceCalculator), nicht die Projektion darunter.
        DcaSchema::create($c, 'tl_consho_price');

        // ── tl_consho_product_shop, tl_consho_shop ──────────────────────────
        DcaSchema::create($c, 'tl_consho_product_shop');
        DcaSchema::create($c, 'tl_consho_shop');

        // ── tl_member_group ─────────────────────────────────────────────────
        // Contao's own table, not ours. Only what C-4 reads from it: the id to
        // check a selection against, and the name nobody reads any more once
        // the row is gone.
        $c->executeStatement(
            'CREATE TABLE tl_member_group (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT \'\',
                disable INTEGER NOT NULL DEFAULT 0,
                start TEXT NOT NULL DEFAULT \'\',
                stop TEXT NOT NULL DEFAULT \'\'
            )',
        );

        // ── tl_consho_product_list_price ────────────────────────────────────
        // Projektion, nicht die Preistabelle selbst — siehe DCA-Kommentar dort.
        // Aus der DCA seit Block H (H-2): die handgeschriebene Fassung kannte
        // die Spalten des Streichpreises nicht (Prüfungsnotiz, Fall 31).
        DcaSchema::create($c, 'tl_consho_product_list_price');

        // ── tl_consho_product_visibility ────────────────────────────────────
        // Projektion der Sichtbarkeit (B-10) — nach contao/dca/
        // tl_consho_product_visibility.php; net/gross NULL-bar, weil
        // Variantenzeilen keinen Ab-Preis tragen. `currency` seit C-1c
        // Aufgabe 5b im eindeutigen Schlüssel: Zwei Startpunkte desselben
        // Shops in derselben Sprache und zwei Währungen sind zwei Zeilen. Ein
        // Testschema, das hier großzügiger wäre als die Tabelle, verbärge
        // genau den Fall, um den es geht.
        DcaSchema::create($c, 'tl_consho_product_visibility');

        // ── tl_consho_projection_state ──────────────────────────────────────
        DcaSchema::create($c, 'tl_consho_projection_state');

        // ── tl_consho_sold_out ──────────────────────────────────────────────
        // Block Verfügbarkeit: „Nur verfügbare" und „Nicht Verfügbares ans
        // Ende" lesen sie in jeder Liste mit diesem Filter.
        DcaSchema::create($c, 'tl_consho_sold_out');
    }

    /**
     * Die Startpunkte, aus denen ShopLanguages die Sprachen eines Shops liest
     * — und seit C-1c auch DbalMarketRepository die Währung. Eigene Methode,
     * nicht Teil von createCatalogSchema(): mehrere Tests legen tl_page schon
     * selbst an.
     *
     * `consho_currency` nach contao/dca/tl_page.php: string(3) NOT NULL
     * DEFAULT '' — also **leer** als Spaltenvorgabe, nicht 'EUR'. Ein
     * Startpunkt, der am Feld vorbei geschrieben wird, darf nicht stillschweigend
     * zum Euro-Startpunkt werden; genau diesen Fall soll die Spalte zeigen.
     */
    protected function createRootPageTable(Connection $c): void
    {
        $c->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                type TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                dns TEXT NOT NULL DEFAULT '',
                useSSL TEXT NOT NULL DEFAULT '1',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                consho_currency TEXT NOT NULL DEFAULT '',
                published TEXT NOT NULL DEFAULT '1',
                start TEXT NOT NULL DEFAULT '',
                stop TEXT NOT NULL DEFAULT ''
            )",
        );
    }

    /**
     * Die Bequemlichkeit 'EUR' steht hier und nicht in der Spaltenvorgabe —
     * dieselbe Aufteilung wie bei {@see insertPrice()}: im Testcode, wo sie
     * gelesen wird, verdeckt sie nichts; in der Tabellendefinition verdeckte
     * sie den leeren Startpunkt. Wer den Fall braucht, übergibt ''.
     */
    protected function insertRootPage(int $id, string $language, int $shopId, string $currency = 'EUR'): void
    {
        $this->catalogConnection->insert('tl_page', ['id' => $id, 'type' => 'root', 'title' => 'Start '.$language, 'language' => $language, 'consho_shop' => $shopId, 'consho_currency' => $currency]);
    }

    /**
     * Die Bequemlichkeit 'EUR' steht hier und nicht in der Spaltenvorgabe —
     * dieselbe Aufteilung wie bei {@see insertPrice()} und
     * {@see insertRootPage()}: Im Testcode verdeckt sie nichts, in der
     * Tabellendefinition verdeckte sie eine Zeile, die am Projektor vorbei
     * ohne Währung geschrieben wurde. Wer den Fall braucht, übergibt ''.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertVisibility(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product_visibility', $overrides + ['currency' => 'EUR']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertProduct(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product', $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertTranslation(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_translation', $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertPrice(array $overrides = []): void
    {
        // A saved row. tstamp 0 is a price mask that was opened and never
        // saved, and every price reader skips it (DbalPriceRepository).
        //
        // The currency is defaulted HERE and no longer by the column: since
        // 2026-09-21 tl_consho_price.currency has an empty default, so that a
        // row written past the mask cannot silently become a euro price. A
        // test fixture may well be convenient — but the convenience belongs in
        // the test code, where it is read, not in the table definition, where
        // it would hide exactly the case the default now guards against.
        $this->catalogConnection->insert('tl_consho_price', $overrides + ['tstamp' => 1, 'currency' => 'EUR']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertListPrice(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product_list_price', $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertProductShop(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product_shop', $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertShop(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_shop', $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertManufacturer(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_manufacturer', $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertProductType(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product_type', $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertPropertyGroup(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_property_group', $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertPropertyOption(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_property_option', $overrides);
    }

    /**
     * Ordnet dem HAUPTPRODUKT eine filterbare Eigenschaft zu (Achtung: keine
     * Variantenachse — dafür {@see insertProductOption()}).
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertProductProperty(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product_property', $overrides);
    }

    /**
     * Macht eine Zeile über die gesetzte Option zu einer bestimmten Variante.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertProductOption(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product_option', $overrides);
    }
}
