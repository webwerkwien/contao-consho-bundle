<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Contao\Pagination;
use Contao\StringUtil;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalProductTileRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityLabelImages;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingElement;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingVerdict;
use Webwerkwien\ContaoConshoBundle\Catalog\PinnedPager;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;
use Webwerkwien\ContaoConshoBundle\Catalog\SortLinks;
use Webwerkwien\ContaoConshoBundle\Catalog\TilePrices;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TileTemplateOptionsListener;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddress;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddressResolver;
use Webwerkwien\ContaoConshoBundle\Seo\ListingHead;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tax\NotTaxableArticles;

/**
 * A listing of products — the category page of a Consho shop.
 *
 * A content element, not a front end module. Contao is retiring modules class
 * by class ("will no longer work in Contao 6"), and layout sections have taken
 * content elements since 5.7 — see docs/block-b-katalog-frontend.md, section 2.
 *
 * ── Where the products come from ──────────────────────────────────────────
 *
 * Two sources, and they are not equal:
 *
 * | `conshoFilter` at the element | the CATEGORY. Set by the editor, not negotiable |
 * | the `cq` parameter           | what the VISITOR chose, narrowing within it      |
 *
 * A visitor must not be able to click their way out of the category, which is
 * why the two are merged by FilterResolver::narrow() rather than concatenated.
 *
 * ── ⚠️ The type is declared, not derived ──────────────────────────────────
 *
 * Contao builds it from the class name — ProductListController becomes
 * "product_list", with no bundle prefix. Measured on the test server on
 * 2026-09-11, where the palette said consho_product_list and the front end
 * logged: Content element class "" (content element "consho_product_list")
 * does not exist. The element simply rendered nothing, and the page looked
 * like an empty article.
 *
 * A generic "product_list" would also collide with the first other shop
 * extension installed beside us.
 *
 * ── Pins (B-7) ────────────────────────────────────────────────────────────
 *
 * Nested `consho_product_pin` elements put products first. Five rules, from
 * docs/block-b-katalog-frontend.md 3.1:
 *
 * 1. Pinned first, in the dragged order; the rule after them WITHOUT them.
 * 2. New products keep appearing by themselves — membership is the preset.
 * 3. A pin beats the preset, never visibility or a missing price.
 * 4. Pins stand at the start of the sequence (PinnedPager, E1).
 * 5. A visitor who sorts gets their order and no pins. A visitor who filters
 *    keeps the pins that match.
 */
#[AsContentElement(type: 'consho_product_list', category: 'consho', nestedFragments: ['allowedTypes' => [DbalPinRepository::TYPE]])]
class ProductListController extends AbstractContentElementController
{
    public function __construct(
        private readonly ShopResolver $shops,
        private readonly FilterResolver $filters,
        private readonly ProductQuery $products,
        private readonly DbalProductTileRepository $tiles,
        private readonly ShopLanguages $languages,
        private readonly ListingAddressResolver $addresses,
        private readonly ListingHead $head,
        private readonly ListingPageRepository $listings,
        private readonly SortLinks $sortLinks,
        private readonly ProductUrlGenerator $urls,
        private readonly TilePrices $prices,
        private readonly DbalPinRepository $pins,
        private readonly TokenChecker $tokenChecker,
        private readonly PriceAudienceResolver $audiences,
        private readonly DbalCatalogSchedule $schedule,
        /*
         * The kind of the article (DL1; acceptance DL, find 2): "zzgl.
         * Versandkosten" only where something is shipped. Optional for the
         * tests built by hand — without it the sentence stays as it was.
         */
        private readonly ArticleShippingDataInterface|null $articles = null,
        // Block Wertgutscheine (acceptance WG-7): the tax sentence. Optional
        // like the one above.
        private readonly NotTaxableArticles|null $notTaxable = null,
        // GA8: the nested GARAN label on the tile. Optional like the above.
        private readonly DurabilityLabelImages|null $durability = null,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $page = $request->attributes->get('pageModel');
        $shop = $page instanceof PageModel ? $this->shops->resolveForPage($page) : null;

        if (null === $shop) {
            /*
             * The element sits on a page outside any shop. Rendering an empty
             * listing would look like an empty catalogue; saying nothing at all
             * is the honest answer, and the start page reports the setup gap.
             */
            $template->set('products', []);
            $template->set('configured', false);

            // Set here too, although this branch renders no tile: the template
            // reads them unconditionally, and a listing that fatals outside a
            // shop would turn a setup gap into a broken page.
            $template->set('showsNet', false);
            $template->set('ownPriceGroup', '');

            return $template->getResponse();
        }

        /*
         * Read through row(): Consho adds these to tl_content through its own
         * DCA, so they are not part of ContentModel's declared surface — same
         * as consho_shop on the page in ShopResolver. Saying it out loud keeps
         * static analysis meaningful; silencing it would hide a typo in a
         * column name just as well.
         */
        $row = $model->row();
        $elementId = (int) $model->id;

        /*
         * cq and the path segment are parameters of the PAGE, not of this
         * element. A listing that does not follow them — a curated cross-selling
         * row, say — ignores both, never answers 404 and leaves the head alone:
         * otherwise ?cq=colour:red would take the whole page down with it
         * wherever that row has nothing red (B-4 decision 4).
         *
         * Not reading the segment is also what keeps it safe: an unread segment
         * is a 404 by Contao's own hand (ListingAddressResolver).
         */
        $follows = (bool) ($row['conshoFollowsAddress'] ?? true);
        $address = $follows ? $this->addresses->resolve($page, $request) : ListingAddress::category(new FilterState());

        if (!$address->valid) {
            throw new PageNotFoundException('No landing page or filter state for this address');
        }

        $state = $address->state;
        $chosen = $this->filters->resolve($state);
        $groups = $this->filters->narrow($this->filters->group($this->presetOptions($model)), $chosen);

        $template->set('configured', true);
        $template->set('showCounts', (bool) ($row['conshoShowCounts'] ?? false));
        $template->set('imageSize', StringUtil::deserialize($row['conshoTileSize'] ?? null, true));
        $template->set('tileTemplate', self::tileTemplate((string) ($row['conshoTileTemplate'] ?? '')));

        $shopId = (int) $shop->id;

        // The currency of this start point (spec 4.0) — it also picks the row
        // of the visibility projection, whose key carries it. The home country
        // stays with the shop: it is the seat of the invoicing party.
        $currency = $this->shops->currencyOfPage($page);
        $country = (string) $shop->country;
        $now = time();

        // At this point $page is a PageModel instance — PHPStan follows that
        // through the ternary expression on $shop: had $page not been a
        // PageModel there, $shop would stay null and the method would
        // already have returned above. Unlike `consho_shop`, `language` is a
        // declared core property of PageModel (tl_page.language) — no
        // row() access needed.
        //
        // ⚠️ And it is NOT this page's own raw column value, but that of its
        // root page: PageModel::loadDetails() overwrites the property
        // (PageModel.php:1035, `$this->language =
        // $objParentPage->language`). Looking at the database shows
        // something different on an ordinary page — on the test server the
        // listing page carries `de`, its root page `en`, and `en` is what
        // counts.
        //
        // That assumes loadDetails() has run. ShopResolver already makes the
        // same assumption with `rootId`; if it were false, the resolver
        // would not find the shop at all and the method would have exited
        // above.
        $language = (string) ($page->language ?? '');
        $fallback = $this->languages->fallbackOf($shopId);

        $sort = ProductSort::isKnown($state->sort) ? $state->sort : $this->defaultSort($row);

        /*
         * Net or gross depends on the visitor, and since C-4 the answer is
         * really asked: a member of one of the shop's net price groups reads
         * net, everybody else gross. It travels into the sorting as well,
         * because the projection carries both columns (C-1a) and a list sorted
         * by one number while showing another is a bug nobody reports.
         *
         * Reverse charge (A-5) is a second road to the same display and is not
         * this one: it hangs on a VAT id, not on a group, and it belongs to
         * the checkout, which is C-5.
         */
        $audience = $this->audiences->forShop($shopId, $currency, $now);
        $showsNet = $audience->showsNet;

        $perPage = max(0, (int) ($row['conshoPerPage'] ?? 0));
        $requestedPage = $perPage > 0 ? $this->requestedPage($request, $elementId) : 1;
        $pinsOnly = (bool) ($row['conshoPinsOnly'] ?? false);

        // Block Verfügbarkeit: the visitor's "Nur verfügbare" (V5) and the
        // element's "Nicht Verfügbares ans Ende" (V4).
        $availableOnly = $state->availableOnly;
        $unavailableLast = (bool) ($row['conshoUnavailableLast'] ?? false);

        // Behind an alias element the model carries the alias's id; the pins
        // hang on the original, and the core asks for nested elements the same
        // way (Controller::getContentElement()).
        $listId = (int) (($row['origId'] ?? 0) ?: $model->id);

        $pins = [];
        $pinIds = [];
        $rest = 0;
        $restIds = [];

        // Null groups ask for nothing — ListingVerdict answers that.
        if (null !== $groups) {
            /*
             * Rule 5: a visitor who sorts asked for an order, and pins would
             * break it. A curated list has no other order to give way to.
             *
             * Checked against the VISITOR's filter ($chosen), not the preset —
             * rule 3. A landing page's state counts as the visitor's.
             */
            if ($pinsOnly || !ProductSort::isKnown($state->sort)) {
                $pinIds = $this->pins->productIdsOf($listId, $now, $this->tokenChecker->isPreviewMode());
                $pins = $this->products->visibleAmong($pinIds, $shopId, $chosen, $language, $currency, $availableOnly);
            }

            // With pagination the rule is counted, because find() returns one
            // page; without it, find() returns it all and counting is free.
            if (!$pinsOnly && $perPage > 0) {
                $rest = $this->products->count($shopId, $groups, $language, $currency, $pins, $availableOnly);
            } elseif (!$pinsOnly) {
                $restIds = $this->products->find($shopId, $groups, $language, $currency, $fallback, $sort, $showsNet, 0, 0, $pins, $availableOnly, $unavailableLast);
                $rest = \count($restIds);
            }
        }

        $slice = PinnedPager::slice($pins, $rest, $perPage, $requestedPage, (bool) ($row['conshoPinsExtra'] ?? false));

        $matches = self::verdictMatches($this->products, $slice->matches, $availableOnly, $groups, $pinIds, $pinsOnly, $shopId, $chosen, $language, $currency);

        // The exception ends the whole page, on purpose: an element runs as a
        // forward sub-request with ignore_errors false, and Contao renders its
        // 404 page from it. Which is exactly why it hangs on $follows.
        if ($follows && ListingVerdict::isNotFound($address, $groups, $matches, $requestedPage, $slice->pages, self::optionCount($chosen))) {
            throw new PageNotFoundException(\sprintf('Page not found: %s', $request->getUri()));
        }

        if (null !== $groups && $perPage > 0) {
            // The slice is within range once the verdict has passed. A listing
            // that does not follow the address never answers 404 and keeps
            // B-3's fallback. A page filled by pins asks the rule for nothing.
            if (!$pinsOnly && 0 !== $slice->limit) {
                $restIds = $this->products->find($shopId, $groups, $language, $currency, $fallback, $sort, $showsNet, $slice->offset, (int) $slice->limit, $pins, $availableOnly, $unavailableLast);
            }

            $template->set('pagination', $this->pagination($slice->paginationTotal, $perPage, $elementId));
        }

        $ids = [...$slice->pins, ...$restIds];

        /*
         * Only the first listing of the page that follows the address writes
         * the head — or, with none in an article, the one in the layout
         * section. See ListingHead::isWrittenBy().
         */
        if ($follows && ListingHead::isWrittenBy($this->listings->firstOnPage((int) $page->id, $now), $elementId)) {
            $this->head->apply($page, $address, $elementId, $requestedPage);
        }

        $template->set('sort', $sort);

        // A curated list keeps the dragged order — nothing to sort.
        $template->set('sortLinks', $follows && !$pinsOnly ? $this->sortLinks->for($page, $state, $this->defaultSort($row)) : []);
        $tiles = $this->tiles->forIds($ids, $currency, $country, $showsNet, $shopId, $language, $fallback);
        $template->set('products', $this->linked($tiles, (int) $page->rootId, $currency, $language));
        $template->set('shippedTiles', $this->shippedOf(array_map(static fn ($tile): int => $tile->id, $tiles)));
        // Acceptance WG-7: no "inkl. USt." under a tile that is not taxable.
        $template->set('untaxedTiles', $this->notTaxable?->each(array_map(static fn ($tile): int => $tile->id, $tiles)) ?? []);
        // GA8 (Michael, 2026-10-10: "GA8a"): the nested label where one is set.
        $template->set('garanTiles', $this->durability?->nestedOf(array_map(static fn ($tile): int => $tile->id, $tiles)) ?? []);

        /*
         * Two statements about the audience, and neither is a price (C-4, E1).
         *
         * `showsNet` decides which tax sentence stands under a tile.
         *
         * `ownPriceGroup` is the name of the group whose prices this visitor
         * gets — the listing says where to find them, because the tiles cannot
         * show them: the projection holds one row per article and market and
         * knows no audience ("kein Preis je Kundengruppe in der
         * Materialisierung", block A 3.6).
         *
         * ⚠️ AT THE LISTING, not on the tile, and empty for everyone who has
         * no own prices. Per tile it would mean comparing the list price
         * against the group price for each one — exactly the work this avoids
         * — and for a guest it would promise a discount that the shop, in all
         * likelihood, does not give them: a group comes from the operator, not
         * from signing up.
         */
        $template->set('showsNet', $showsNet);
        $template->set('ownPriceGroup', $audience->groupLabel);

        /*
         * Kaufknopf auf der Kachel (Michael, 2026-09-22). Die Kachel
         * entscheidet dann je Produkt, ob sie ihn zeigen darf — ein
         * Variantenprodukt hat keinen kaufbaren Artikel.
         *
         * `purchasePage` ist die Seite, auf der die Liste steht: Die
         * Warenkorb-Route braucht sie, um den Shop aufzulösen und nach dem
         * Hinzufügen zurückzufinden (C12).
         */
        $template->set('tilePurchase', (bool) ($row['conshoTilePurchase'] ?? false));
        $template->set('purchasePage', (int) $page->id);

        $response = $template->getResponse();

        // Any catalogue change invalidates every listing (B-7b, E1): a product
        // just published has no tag here yet. See CatalogCacheTags.
        $this->tagResponse([CatalogCacheTags::LISTINGS, CatalogCacheTags::CATALOG]);

        /*
         * A pin or a product starting or stopping later changes this listing
         * then — not when the page cache happens to expire (B-7b, E2).
         * SubrequestCacheSubscriber takes the lowest s-maxage of the page's
         * fragments, the same way the core's own addSharedMaxAgeToResponse()
         * works for an element's start and stop.
         */
        $nextChange = self::earliest($this->pins->nextChange($listId, $now), $this->schedule->nextProductChange($now));

        if (null !== $nextChange) {
            $response->setSharedMaxAge(max(1, $nextChange - $now));
        }

        return $response;
    }

    /**
     * How many products the address has, for the 404 verdict.
     *
     * "Nur verfügbare" is no filter for the verdict (block Verfügbarkeit): the
     * stock moves, and a bookmark must not turn into the error page when the
     * last red one sells. Empty because of it is an empty listing — asked
     * without it, once, and only when empty: the pins and, unless the list is
     * curated, the rule (review Verfügbarkeit, 1 — a sold-out pin was the only
     * match).
     *
     * A method of its own so the recount is tested against the real query
     * (open point Verfügbarkeit: getResponse() itself has no test — too many
     * collaborators, FragmentTemplate is final).
     *
     * @param int                        $matches what the listing found, with the visitor's filter
     * @param array<int, list<int>>|null $groups  the rule; null asks for nothing
     * @param list<int>                  $pinIds
     * @param array<int, list<int>>      $chosen  the visitor's filter, which pins are checked against
     */
    public static function verdictMatches(ProductQuery $products, int $matches, bool $availableOnly, array|null $groups, array $pinIds, bool $pinsOnly, int $shopId, array $chosen, string $language, string $currency): int
    {
        if (!$availableOnly || 0 !== $matches || null === $groups) {
            return $matches;
        }

        return \count($products->visibleAmong($pinIds, $shopId, $chosen, $language, $currency))
            + ($pinsOnly ? 0 : $products->count($shopId, $groups, $language, $currency));
    }

    /**
     * The tile template chosen at the element, as a Twig path.
     *
     * Empty means the bundled one — and the template says so itself rather than
     * this method inventing a default, so that overriding the bundled tile
     * through the Template Studio keeps working.
     *
     * Only a variant of the tile: TileTemplateOptionsListener stores the full
     * identifier, and anything else — a value from before B-5, another
     * element's template — would receive `product` and render something else.
     *
     * @internal public for the test only
     */
    public static function tileTemplate(string $chosen): string
    {
        $prefix = TileTemplateOptionsListener::IDENTIFIER.'/';

        if (!str_starts_with($chosen, $prefix) || $prefix === $chosen) {
            return '';
        }

        return '@Contao/'.$chosen.'.html.twig';
    }

    /**
     * Each tile with its address and the price as a buyer reads it.
     *
     * One query per tile for the address (ProductUrlGenerator::generate() asks
     * urlValues() each time). Accepted for B-5; a batch lookup belongs to B-9.
     *
     * @param list<ProductTile> $tiles
     *
     * @return list<ProductTile>
     */
    private function linked(array $tiles, int $rootPageId, string $currency, string $language): array
    {
        $hasProductPage = true;
        $linked = [];
        // "1,20 € / Monat" for a product listed with a plan (Abo-2b, P88).
        $prices = $this->prices->formatted($tiles, $currency, $language);
        // The price struck through next to a promotion (block H, H-2).
        $compares = $this->prices->compares($tiles, $currency, $language);

        foreach ($tiles as $index => $tile) {
            $url = '';

            if ($hasProductPage) {
                try {
                    $url = $this->urls->generate($tile->id, $rootPageId, UrlGeneratorInterface::ABSOLUTE_PATH);
                } catch (ProductPageMissing) {
                    // A shop without a product page keeps its listing, unlinked.
                    // Every tile shares the root page, so the answer holds for
                    // the rest as well — no point asking again.
                    $hasProductPage = false;
                }
            }

            $linked[] = $tile->withLink($url, $prices[$index], $compares[$index]);
        }

        return $linked;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function defaultSort(array $row): string
    {
        $stored = (string) ($row['conshoSort'] ?? '');

        return ProductSort::isKnown($stored) ? $stored : ProductSort::TITLE;
    }

    /**
     * The page number lives OUTSIDE the filter parameter, and that is not a
     * detail.
     *
     * `cq` is excluded from crawling by the robots.txt rule from A-10. Put the
     * page number in there and everything past page one stops being
     * discoverable — products that appear only on page three would never be
     * found. FilterState says so in as many words, and the B-3 plan said the
     * opposite; the plan was wrong.
     */
    private function requestedPage(Request $request, int $elementId): int
    {
        /*
         * As the core reads it (ContentGallery: (int) (Input::get($id) ?? 1)):
         * absent is page one, anything else is cast, so 0 or "abc" is no page
         * and ListingVerdict answers 404. Through query->all() for the same
         * reason as cq — InputBag::get() answers an array with a 400.
         */
        $raw = $request->query->all()[ListingElement::pageParameter($elementId)] ?? null;

        if (null === $raw) {
            return 1;
        }

        return \is_string($raw) ? (int) $raw : 0;
    }

    private function pagination(int $total, int $perPage, int $elementId): string
    {
        $pagination = new Pagination($total, $perPage, 7, ListingElement::pageParameter($elementId));

        return $pagination->generate();
    }

    /**
     * @internal public for the test only
     */
    public static function earliest(int|null ...$moments): int|null
    {
        $set = array_filter($moments, static fn (int|null $moment): bool => null !== $moment);

        return [] === $set ? null : min($set);
    }

    /**
     * @param array<int, list<int>> $chosen
     */
    private static function optionCount(array $chosen): int
    {
        return array_sum(array_map(\count(...), $chosen));
    }

    /**
     * @return list<int>
     */
    private function presetOptions(ContentModel $model): array
    {
        $values = StringUtil::deserialize($model->row()['conshoFilter'] ?? null, true);

        return array_values(array_map(intval(...), array_filter($values, is_numeric(...))));
    }

    /**
     * Per tile, whether its product is shipped (acceptance DL, find 2): the
     * tile says "zzgl. Versandkosten" only then.
     *
     * @param list<int> $ids
     *
     * @return array<int, bool>
     */
    private function shippedOf(array $ids): array
    {
        if (null === $this->articles || [] === $ids) {
            return [];
        }

        return array_map(static fn (array $data): bool => ProductKind::stored($data['kind'])->isPhysical(), $this->articles->of($ids));
    }
}
