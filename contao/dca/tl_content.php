<?php declare(strict_types=1);

use Contao\DataContainer;

/*
 * Consho's content elements.
 *
 * The types themselves are registered by their controllers via
 * #[AsContentElement]; only the palettes and fields belong here.
 *
 * ⚠️ No #[AsFrontendModule] anywhere in Consho. Contao is retiring modules
 * class by class — ModuleLogin since 5.6, ModuleChangePassword,
 * ModuleCloseAccount and ModuleTwoFactor since 5.7, each "will no longer work
 * in Contao 6" — and since 5.7 layout sections accept content elements too
 * (ModuleWizard reads tl_content WHERE ptable='tl_theme'). See
 * Block B, section 2.
 */

$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_product_list'] =
    '{type_legend},type'
    .';{consho_filter_legend},conshoFilter,conshoFollowsAddress,conshoShowCounts,conshoPinsOnly'
    .';{consho_display_legend},conshoSort,conshoUnavailableLast,conshoPerPage,conshoPinsExtra,conshoTileSize,conshoTileTemplate,conshoTilePurchase'
    .';{protected_legend:hide},protected'
    .';{expert_legend:hide},cssID'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * The category, not a pre-ticked filter.
 *
 * Whatever stands here holds for every visitor of the page; a visitor's own
 * choice narrows WITHIN it and can never switch it off — see
 * Catalog\FilterResolver::narrow(). That is the whole reason Consho needs no
 * category tree: the page tree carries the structure, and this field says which
 * products belong to a page.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoFilter'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'clr'],
    'sql' => ['type' => 'blob', 'notnull' => false],
];

/*
 * Whether this listing reads the filter state from the address — `cq` and the
 * landing page segment — and answers 404 when that state matches nothing.
 *
 * On by default: that is the category listing. Off for a curated list beside
 * it (cross-selling, "new this week"): `cq` belongs to the whole page, and a
 * list that followed it would take the page down to a 404 whenever someone
 * appended a colour it does not carry. See Block B,
 * section 3.1.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoFollowsAddress'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => true],
];

$GLOBALS['TL_DCA']['tl_content']['fields']['conshoShowCounts'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['tl_class' => 'w50 m12'],

    /*
     * Default on: the numbers are what make a filter usable. The switch exists
     * for operators who do not want them, and as a way out should the
     * measurement in B-9 find them too expensive on a large catalogue.
     */
    'sql' => ['type' => 'boolean', 'default' => true],
];

/*
 * Contao's own image size widget. Consho brings no image handling of its own —
 * principle 5, and precisely the thing that grated about Isotope.
 *
 * It sits at the ELEMENT, not at the product: one product appears as a tile, on
 * a detail page and in a basket, in three different sizes. A format on the
 * record could serve only one of them, and a redesign would mean touching 500
 * products instead of one element.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoTileSize'] = [
    'inputType' => 'imageSize',
    'exclude' => true,
    'reference' => &$GLOBALS['TL_LANG']['MSC'],
    'eval' => ['rgxp' => 'natural', 'includeBlankOption' => true, 'tl_class' => 'w50'],
    'options_callback' => static fn (DataContainer|null $dc): array => Contao\System::getContainer()
        ->get('contao.image.sizes')
        ->getOptionsForUser(Contao\BackendUser::getInstance())
    ,
    'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
];

/*
 * The order the listing starts in. A visitor may change it — their choice
 * travels in `cq` and wins for that request — but a sorted view is duplicate
 * content by construction and therefore never becomes a landing page
 * (Seo\FacetPageResolver has refused that since A-10).
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoSort'] = [
    'inputType' => 'select',
    'exclude' => true,
    'options' => Webwerkwien\ContaoConshoBundle\Catalog\ProductSort::ALL,
    'reference' => &$GLOBALS['TL_LANG']['CONSHO']['sort'],
    'eval' => ['tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 32, 'default' => Webwerkwien\ContaoConshoBundle\Catalog\ProductSort::TITLE],
];

/*
 * 0 = no pagination.
 *
 * ⚠️ The page number travels in its OWN parameter, not in `cq`. `cq` is
 * excluded from crawling by the robots.txt rule from A-10, so a page number
 * inside it would make everything past page one undiscoverable — products that
 * appear only on page three would never be found. FilterState says so in as
 * many words; the B-3 plan said the opposite and was wrong.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoPerPage'] = [
    'inputType' => 'text',
    'exclude' => true,
    'eval' => ['rgxp' => 'natural', 'tl_class' => 'w50'],
    'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
];

/*
 * The tile's Twig template. Empty means the bundled one.
 *
 * Template choice sits here and not on the product: one article appears as a
 * tile, on a detail page and in a basket, and a template on the record could
 * serve only one of them. See Block A, section 3.6.
 *
 * Stored: the full identifier, e.g. content_element/consho_product_tile/compact.
 * 128 rather than 64, because that prefix alone takes 36 characters.
 *
 * Options: Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TileTemplateOptionsListener
 * — not Controller::getTemplateGroup(), which throws for an identifier with a
 * slash in 5.7 and took the whole edit mask down with it.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoTileTemplate'] = [
    'inputType' => 'select',
    'exclude' => true,
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
];

/*
 * Only the pinned products, in the dragged order (B-7, E2).
 *
 * An empty preset means the whole catalogue, so "leave the preset empty and
 * pin" would have put every product behind the pins. This is the curated list:
 * cross-selling, "our recommendations". No sort links — its order is the one
 * the editor dragged — but a visitor's filter still narrows it.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoPinsOnly'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

/*
 * How pins meet the pagination (B-7, E1). Every pin stands before every other
 * product either way.
 *
 * Off: they take places — twelve per page stay twelve. On: all of them come on
 * top of page one, and the other pages stay as they would be without pins. See
 * Catalog\PinnedPager.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoPinsExtra'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

/*
 * A pin, nested in a listing — never anywhere else (ProductPinPlacementVoter).
 *
 * No headline, no CSS, no protection: it renders nothing in the front end. What
 * it does carry is what makes a tl_content row worth it — invisible, start and
 * stop per pin, and its sorting as the drag and drop.
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_product_pin'] =
    '{type_legend},type'
    .';{consho_pin_legend},conshoProduct'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * Options: Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PinnedProductOptionsListener
 * — main products only. No foreignKey: a deleted product has to leave the id
 * behind, so that the start page can report the empty pin.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoProduct'] = [
    'inputType' => 'select',
    'exclude' => true,
    'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The product on a product page. It reads the product the page type resolved
 * and renders nothing anywhere else, so there is no product to choose here.
 *
 * fullsize is the core's own field (tl_content, boolean in 5.7): the gallery
 * opens the large image in a lightbox, as Contao's image element does.
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_product_detail'] =
    '{type_legend},type'
    .';{consho_display_legend},conshoGallerySize,fullsize'
    .';{protected_legend:hide},protected'
    .';{expert_legend:hide},cssID'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * The gallery's image size. At the element, not at the product — for the same
 * reason as conshoTileSize: one article, three places, three sizes.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoGallerySize'] = [
    'inputType' => 'imageSize',
    'exclude' => true,
    'reference' => &$GLOBALS['TL_LANG']['MSC'],
    'eval' => ['rgxp' => 'natural', 'includeBlankOption' => true, 'tl_class' => 'w50'],
    'options_callback' => static fn (DataContainer|null $dc): array => Contao\System::getContainer()
        ->get('contao.image.sizes')
        ->getOptionsForUser(Contao\BackendUser::getInstance())
    ,
    'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
];

/*
 * The filter controls, as links to the listing's addresses.
 *
 * Its own legend rather than consho_filter_legend: that one reads "which
 * products", which is the listing's question, not the filter's.
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_product_filter'] =
    '{type_legend},type'
    .';{consho_panel_legend},conshoListElement,conshoGroups,conshoAvailableFilter'
    .';{protected_legend:hide},protected'
    .';{expert_legend:hide},cssID'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * Empty = the first listing in the current page's articles that follows the
 * address.
 *
 * Empty is the normal case, not the lazy one: a filter in a layout section
 * serves every category page, and a fixed element id would be wrong on all of
 * them but one. Set it for a listing that stands in a layout section itself —
 * the empty field never finds that one (ListingPageRepository).
 *
 * Options: Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ListElementOptionsListener
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoListElement'] = [
    'inputType' => 'select',
    'exclude' => true,
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * Empty = every group a visitor can filter by, ordered by title. Set = these
 * groups, in this order.
 *
 * The options offer nothing else: a group that is not filterable would be
 * dropped from the address the moment a visitor clicked it.
 *
 * Options: Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FilterGroupOptionsListener
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoGroups'] = [
    'inputType' => 'checkboxWizard',
    'exclude' => true,
    'eval' => ['multiple' => true, 'tl_class' => 'clr'],
    'sql' => ['type' => 'blob', 'notnull' => false],
];

/*
 * The basket (C-3, specification 4.2). It reads the visitor's own basket, so
 * there is nothing to choose here — and no consho_display_legend either: the
 * quantities, amounts and buttons are the element, not options of it.
 *
 * No `guests` and no `protected`: the element shows one visitor their own
 * basket and is empty for anyone without one. Hiding it from members or from
 * guests would hide a basket somebody is holding, not protect anything.
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_cart'] =
    '{type_legend},type,headline'
    .';{expert_legend:hide},cssID'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * The mini basket (C-3, decision C10). Like the basket itself it reads the
 * visitor's own basket, so there is nothing to choose here.
 *
 * No headline: this one belongs in a layout section beside the navigation, and
 * a heading above a number and an amount would be a line of its own in every
 * design. The basket element has one because it is a page.
 *
 * ⚠️ And that is where it usually lives — which matters to anybody querying for
 * it. Since Contao 5.7 a layout section's elements are tl_content rows with
 * ptable = 'tl_theme', NOT rows hanging off an article. A query that follows
 * the reflex "tl_content means pid → tl_article" misses exactly the placement
 * this element exists for, and no test notices, because such a query's
 * fixtures never contain a tl_theme row either. Any lookup for this type stays
 * ptable-agnostic — see DbalMiniCartIssueSource.
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_mini_cart'] =
    '{type_legend},type'
    .';{expert_legend:hide},cssID'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * The checkout (C-5, decision C13). One choice: how many steps the four fixed
 * building blocks are spread over. Which blocks exist is not configurable — a
 * shop without an address block is not a checkout — and the step itself
 * travels in the address, not in a setting.
 *
 * No `guests` and no `protected`, for the same reason as the basket: guests
 * and members both check out (C14), and hiding the element from either would
 * hide a basket somebody is holding.
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_checkout'] =
    '{type_legend},type,headline'
    .';{consho_display_legend},conshoSteps'
    .';{expert_legend:hide},cssID'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * The order page (C-10, 7.7). It shows the order the link in the address
 * names, so there is nothing to choose — and no `guests` or `protected`:
 * the token is the access, and a guest has no login to be shown or hidden by.
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_order'] =
    '{type_legend},type,headline'
    .';{expert_legend:hide},cssID'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * "Vertrag widerrufen" (D-2, W1): the withdrawal form for a buyer without the
 * mail. Nothing to choose either — and deliberately no `guests`/`protected`
 * in the palette: a function that has to be "leicht zugänglich" must not be
 * hidden behind a login (spec D 2, "Konto").
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['consho_withdrawal'] =
    '{type_legend},type,headline'
    .';{expert_legend:hide},cssID'
    .';{invisible_legend:hide},invisible,start,stop'
;

/*
 * One to four. The grouping per number is fixed in CheckoutBlock::steps() and
 * not a second set of options: four blocks over four numbers is fourteen ways
 * to arrange them, and thirteen of them nobody wants.
 *
 * Default 3 — address, then how it travels and how it is paid, then the
 * review. One step is a long page, four are a lot of clicking.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoSteps'] = [
    'inputType' => 'select',
    'exclude' => true,
    'options' => [1, 2, 3, 4],
    'default' => 3,
    'eval' => ['tl_class' => 'w50'],
    'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 3],
];

/*
 * Purchase button on the tile.
 *
 * OFF by default, for two reasons: existing lists must not change through an
 * update, and a list often stands where a purchase button does not belong — as
 * an overview on the start page, as a teaser in a text. Whoever wants a shop
 * shelf ticks it.
 *
 * ⚠️ The button appears ONLY for products without variants. A main product
 * with variants has no buyable article until someone has chosen one; its tile
 * links to the detail page as before (ProductTile::$isBuyable).
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoTilePurchase'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

/*
 * Block Verfügbarkeit, V4: "derzeit nicht verfügbar" after everything else,
 * before whatever order the visitor or the element chose. Off by default —
 * an existing listing keeps its order.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoUnavailableLast'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

/*
 * Block Verfügbarkeit, V5: the filter element offers "Nur verfügbare". Off by
 * default — an existing filter changes nothing.
 */
$GLOBALS['TL_DCA']['tl_content']['fields']['conshoAvailableFilter'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];
