<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;

/**
 * Keeps cached catalogue pages current when a back end change moves them (plan
 * B-7b). SitemapCacheTagListener does the same for the sitemap.
 *
 * ── Three ways in, because the core covers only one ───────────────────────
 *
 * 1. Save, delete, copy, version restore: DC_Table::invalidateCacheTags() runs
 *    and hands its tags through oninvalidate_cache_tags — also from
 *    contao-ai-cli since core bundle v0.10.0.
 * 2. The recycle bin: DC_Table::undo() invalidates tl_undo only, never the
 *    restored table (B-6). onundo invalidates directly.
 * 3. 🔴 A save that changes only translations, the shop assignment, the
 *    properties or the variant options: those fields write their own tables
 *    and keep out of DC_Table's UPDATE (`doNotSaveEmpty`). DC_Table::submit()
 *    invalidates only when that UPDATE affected a row (Contao 5.7,
 *    DC_Table.php:3179) — so nothing was invalidated, the sitemap included.
 *    onsubmit invalidates the record's tags once more, callbacks and all. After
 *    a real UPDATE that is twice, which costs nothing.
 *
 * A tax rate changes the gross on every product page of its class (C25) — so
 * tl_consho_tax_rate and tl_consho_tax_class invalidate CATALOG the same way a
 * shop does, not just LISTINGS. Since Connect-1 the shipping methods and their
 * prices do as well: the JSON-LD of a product page states its shipping costs.
 * A module type that calculates in code changes nothing stored and cannot be
 * followed this way — its figure is as current as the page.
 */
class CatalogCacheTagListener
{
    public function __construct(private readonly CatalogCacheTags $catalog)
    {
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.oninvalidate_cache_tags')]
    public function onProduct(DataContainer $dc, array $tags): array
    {
        return CatalogCacheTags::merge($tags, $this->catalog->forProduct((int) $dc->id));
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_price', target: 'config.oninvalidate_cache_tags')]
    public function onPrice(DataContainer $dc, array $tags): array
    {
        return CatalogCacheTags::merge($tags, $this->catalog->forPrice((int) $dc->id));
    }

    /**
     * A landing page gives a listing page its title and canonical.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_facet_page', target: 'config.oninvalidate_cache_tags')]
    public function onFacetPage(DataContainer $dc, array $tags): array
    {
        return CatalogCacheTags::merge($tags, [CatalogCacheTags::LISTINGS]);
    }

    /**
     * Records many products share: a manufacturer's name, a type's partial, an
     * option's label and alias, a shop's currency, a tax rate or class (C25) —
     * a rate change switches the gross on every product page of its class —
     * and since Abo-2 a subscription rule, whose title and conditions stand in
     * the plan choice of every product page offering it.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_property_group', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_property_option', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_shop', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_subscription_rule', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_tax_class', target: 'config.oninvalidate_cache_tags')]
    // Connect-1 (K3): the JSON-LD of a product page names its shipping costs.
    #[AsCallback(table: 'tl_consho_shipping_method', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_shipping_price', target: 'config.oninvalidate_cache_tags')]
    // ZW-4: a custom field's title stands under "Angaben" on every product
    // page of the types offering it.
    #[AsCallback(table: 'tl_consho_custom_field', target: 'config.oninvalidate_cache_tags')]
    public function onShared(DataContainer $dc, array $tags): array
    {
        return CatalogCacheTags::merge($tags, [CatalogCacheTags::CATALOG]);
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onundo')]
    public function onProductUndo(string $table, array $row, DataContainer $dc): void
    {
        $main = CatalogCacheTags::mainOfRow($row);

        $this->catalog->invalidate($main > 0 ? [CatalogCacheTags::LISTINGS, CatalogCacheTags::product($main)] : [CatalogCacheTags::LISTINGS]);
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_price', target: 'config.onundo')]
    public function onPriceUndo(string $table, array $row, DataContainer $dc): void
    {
        // The price row is in hand; its article is read from the table, where
        // the recycle bin has restored it before or along with the price.
        $this->catalog->invalidate($this->catalog->forProduct((int) ($row['pid'] ?? 0)));
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_facet_page', target: 'config.onundo')]
    public function onFacetPageUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->catalog->invalidate([CatalogCacheTags::LISTINGS]);
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_property_group', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_property_option', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_shop', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_subscription_rule', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_tax_class', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_shipping_method', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_shipping_price', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_custom_field', target: 'config.onundo')]
    public function onSharedUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->catalog->invalidate([CatalogCacheTags::CATALOG]);
    }

    /**
     * The tables with fields outside their own UPDATE — translations
     * (TranslationFieldsListener), shops (ProductShopsListener), properties and
     * variant options (ProductPropertiesListener), a custom field's translated
     * title. See point 3 above.
     */
    #[AsCallback(table: 'tl_consho_custom_field', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_product', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_property_group', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_property_option', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $dc->invalidateCacheTags();
    }
}
