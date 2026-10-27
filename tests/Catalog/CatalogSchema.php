<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * An SQLite test schema that matches the real database — not just
 * "at least as strict". Exactly that deviation hid a real bug twice:
 * `DbalProductTileRepositoryTest` created
 * `safetyWarnings`/`safetyInformation` as `TEXT NOT NULL DEFAULT ""`,
 * while the DB has `longtext NULL DEFAULT NULL` — so the missing COALESCE in
 * VisibilityCriteria went unnoticed. Likewise an `INTEGER` for
 * `start`/`stop` (actually `varchar(10) DEFAULT ''`) would have hidden a
 * comparison against an empty string.
 *
 * The columns used to be copied by hand from `SHOW COLUMNS`. Now DcaSchema
 * builds every Consho table from its DCA (`contao/dca/tl_consho_*.php`) — a
 * new column thus arrives here without a follow-up edit, and the tests use
 * the production column names; a hand-written schema goes stale with the
 * next column. The SQLite type choice is in DcaSchema::column(): `NUMERIC`
 * for `decimal`, because SQLite compares TEXT lexicographically ("10" would
 * come before "8.33").
 *
 * Only `tl_member_group` stays hand-written — Contao's table, for which the
 * bundle has no DCA.
 *
 * The inserters write **only** the columns set via `$overrides`.
 * Unwritten columns therefore really carry `NULL` (where the column
 * allows it) or the default stored in `CREATE TABLE` — not a null value sent
 * along from PHP that would hide this.
 */
trait CatalogSchema
{
    private Connection $catalogConnection;

    /**
     * Creates all catalogue tables (B-5). This method must have run before
     * any inserter is called.
     */
    protected function createCatalogSchema(Connection $c): void
    {
        $this->catalogConnection = $c;

        // ── tl_consho_product ───────────────────────────────────────────────
        // Products AND variants (pid != 0). As in the DB: alias/sku only carry
        // a simple index — the uniqueness of `alias` is Contao's
        // `eval.unique`, not a database constraint (see tl_consho_product.php).
        DcaSchema::create($c, 'tl_consho_product');

        // Block G: the stock, from its DCA, with the production column names —
        // every test with a catalogue can then keep stock for an article.
        DcaSchema::create($c, 'tl_consho_stock');
        DcaSchema::create($c, 'tl_consho_stock_movement');

        // ── tl_consho_product_option ────────────────────────────────────────
        // Variant axis: which option MAKES this row the variant.
        DcaSchema::create($c, 'tl_consho_product_option');

        // ── tl_consho_product_property ──────────────────────────────────────
        // Filterable properties of the MAIN PRODUCT — deliberately a second
        // table instead of a role column, see the DCA comment there.
        DcaSchema::create($c, 'tl_consho_product_property');

        // ── tl_consho_property_group / _option, product_type, manufacturer ──
        DcaSchema::create($c, 'tl_consho_property_group');
        DcaSchema::create($c, 'tl_consho_property_option');
        DcaSchema::create($c, 'tl_consho_product_type');
        DcaSchema::create($c, 'tl_consho_manufacturer');

        // ── tl_consho_translation ───────────────────────────────────────────
        // `alias` nullable without a default (as in the DCA): two groups/options
        // without an alias may exist side by side, because SQLite — like MySQL —
        // never lets NULL collide with another NULL in a UNIQUE index.
        DcaSchema::create($c, 'tl_consho_translation');

        // ── tl_consho_price ─────────────────────────────────────────────────
        // The actual price table (origin `List`/`Scale`/`CustomerGroup`
        // in PriceCalculator), not the projection below it.
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
        // Projection, not the price table itself — see the DCA comment there.
        // From the DCA since Block H (H-2): the hand-written version did not know
        // the columns of the strike-through price.
        DcaSchema::create($c, 'tl_consho_product_list_price');

        // ── tl_consho_product_visibility ────────────────────────────────────
        // Visibility projection (B-10) — after contao/dca/
        // tl_consho_product_visibility.php; net/gross nullable, because
        // variant rows carry no from-price. `currency` part of the unique key
        // since C-1c, task 5b: two start points of the same
        // shop in the same language and two currencies are two rows. A
        // test schema more lenient here than the table would hide
        // exactly the case this is about.
        DcaSchema::create($c, 'tl_consho_product_visibility');

        // ── tl_consho_projection_state ──────────────────────────────────────
        DcaSchema::create($c, 'tl_consho_projection_state');

        // ── tl_consho_sold_out ──────────────────────────────────────────────
        // Block Verfügbarkeit (availability): "Nur verfügbare" and "Nicht
        // Verfügbares ans Ende" read it in every list with this filter.
        DcaSchema::create($c, 'tl_consho_sold_out');
    }

    /**
     * The start points from which ShopLanguages reads the languages of a shop
     * — and since C-1c DbalMarketRepository the currency too. A method of its
     * own, not part of createCatalogSchema(): several tests create tl_page
     * themselves.
     *
     * `consho_currency` after contao/dca/tl_page.php: string(3) NOT NULL
     * DEFAULT '' — so **empty** as the column default, not 'EUR'. A
     * start point written past the field must not silently become
     * a euro start point; this column is meant to show exactly that case.
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
     * The convenience 'EUR' is here and not in the column default —
     * the same split as for {@see insertPrice()}: in the test code, where it
     * is read, it hides nothing; in the table definition it would hide
     * the empty start point. Whoever needs the case passes ''.
     */
    protected function insertRootPage(int $id, string $language, int $shopId, string $currency = 'EUR'): void
    {
        $this->catalogConnection->insert('tl_page', ['id' => $id, 'type' => 'root', 'title' => 'Start '.$language, 'language' => $language, 'consho_shop' => $shopId, 'consho_currency' => $currency]);
    }

    /**
     * The convenience 'EUR' is here and not in the column default —
     * the same split as for {@see insertPrice()} and
     * {@see insertRootPage()}: in the test code it hides nothing, in the
     * table definition it would hide a row written past the projector
     * without a currency. Whoever needs the case passes ''.
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
        // The currency is defaulted HERE and no longer by the column:
        // tl_consho_price.currency has an empty default, so that a
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
     * Assigns a filterable property to the MAIN PRODUCT (note: not a
     * variant axis — use {@see insertProductOption()} for that).
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertProductProperty(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product_property', $overrides);
    }

    /**
     * Makes a row a specific variant via the option set.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertProductOption(array $overrides = []): void
    {
        $this->catalogConnection->insert('tl_consho_product_option', $overrides);
    }
}
