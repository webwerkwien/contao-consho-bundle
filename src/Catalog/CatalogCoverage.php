<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * What the catalogue commands report (plan B-8): which products no listing
 * shows, which landing pages lost their page, and how each start point stands.
 *
 * ── Why this is a command and not a start page finding ────────────────────
 *
 * It holds every listing's preset and pins against every product, per start
 * point and language. The start page runs its sources several times per
 * request (box, bell, alerts); this has to compute (B-8, rule "cheap and
 * certain").
 *
 * ── The listing's own questions ───────────────────────────────────────────
 *
 * "Shown" is what ProductListController shows without a visitor's filter: the
 * pins that are visible, and — unless the listing is "pins only" — what
 * ProductQuery finds for the preset. A preset whose options were all deleted
 * groups to nothing and shows the whole catalogue, in the listing and here.
 *
 * A listing outside an article (layout section, nested) belongs to no page and
 * is counted for every start point: the report may then miss a product that
 * such a listing does not reach in fact, but it never names one that is shown.
 */
class CatalogCoverage
{
    /**
     * @var array<int, int|null> page id => start point id
     */
    private array $roots = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ListingPageRepository $listings,
        private readonly FilterResolver $filters,
        private readonly ProductQuery $products,
        private readonly DbalPinRepository $pins,
        private readonly VisibilityCriteria $criteria,
        private readonly ShopLanguages $languages,
        private readonly VisibilityRows $rows,
    ) {
    }

    /**
     * Visible, priced main products that no visible listing of their start
     * point shows.
     *
     * @return list<array{rootId: int, rootTitle: string, productId: int, title: string}>
     */
    public function unlisted(int $now): array
    {
        $listings = $this->listings->allVisible($now);
        $unlisted = [];

        foreach ($this->startPoints() as $root) {
            /*
             * A website not yet published has no listings yet, and that is no
             * finding. Two unpublished
             * start points made the command fail with five products — an
             * operator building a second site would see it fail for weeks.
             */
            if (!$root['published']) {
                continue;
            }

            $fallback = $this->languages->fallbackOf($root['shopId']);
            $visible = $this->products->find($root['shopId'], [], $root['language'], $root['currency'], $fallback);
            $shown = [];

            foreach ($this->listingsOf($root['id'], $listings) as $listing) {
                $shown[] = $this->products->visibleAmong($this->pins->productIdsOf($listing->id, $now), $root['shopId'], [], $root['language'], $root['currency']);

                if (!$listing->pinsOnly) {
                    $shown[] = $this->products->find($root['shopId'], $this->filters->group($listing->preset), $root['language'], $root['currency'], $fallback);
                }
            }

            $missing = array_values(array_diff($visible, [] === $shown ? [] : array_merge(...$shown)));

            foreach ($this->titles($missing) as $productId => $title) {
                $unlisted[] = ['rootId' => $root['id'], 'rootTitle' => $root['title'], 'productId' => $productId, 'title' => $title];
            }
        }

        return $unlisted;
    }

    /**
     * Landing pages whose listing page no longer exists — deleted past the back
     * end, where FacetPageDeleteListener does not run.
     *
     * @return list<array{id: int, pageId: int, alias: string}>
     */
    public function orphanLandingPages(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'pageId' => (int) $row['pid'], 'alias' => (string) $row['alias']],
            $this->connection->fetchAllAssociative(
                'SELECT f.id, f.pid, f.alias FROM tl_consho_facet_page f
                 LEFT JOIN tl_page p ON p.id = f.pid
                 WHERE p.id IS NULL
                 ORDER BY f.id',
            ),
        );
    }

    /**
     * A plain account per start point, for consho:catalog:diagnose.
     *
     * @return list<array{rootId: int, rootTitle: string, language: string, published: bool, visible: int, priced: int, listings: int, unfiltered: int, pageCache: int|null}>
     */
    public function summary(int $now): array
    {
        $listings = $this->listings->allVisible($now);
        $summary = [];

        foreach ($this->startPoints() as $root) {
            $fallback = $this->languages->fallbackOf($root['shopId']);
            $visible = $this->criteria->mainProduct($root['shopId'], $now, 'p', $root['language'], $fallback);
            // Live, like "visible": the diagnosis describes the source data. A
            // stale projection is reported by consho:visibility:verify (B-10).
            $priced = $this->rows->mains($root['shopId'], $root['language'], $fallback, $root['currency'], $root['country'], $now);
            $own = $this->listingsOf($root['id'], $listings);

            $summary[] = [
                'rootId' => $root['id'],
                'rootTitle' => $root['title'],
                'language' => $root['language'],
                'published' => $root['published'],
                'visible' => (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM tl_consho_product p WHERE %s', $visible->sql), $visible->params),
                'priced' => (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM (%s) priced', $priced->sql), $priced->params, $priced->types),
                'listings' => \count($own),
                'unfiltered' => \count(array_filter($own, static fn (ListingElement $listing): bool => [] === $listing->preset && !$listing->pinsOnly)),
                // Contao's default is no shared cache: includeCache off, cache 0.
                'pageCache' => $root['includeCache'] && $root['cache'] > 0 ? $root['cache'] : null,
            ];
        }

        return $summary;
    }

    /**
     * @return list<array{id: int, title: string, language: string, published: bool, shopId: int, currency: string, country: string, includeCache: bool, cache: int}>
     */
    private function startPoints(): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                // Stored encoded by Contao ("Shop &#40;DE&#41;"), shown on a console.
                'title' => html_entity_decode((string) $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'language' => (string) $row['language'],
                'published' => (bool) $row['published'],
                'shopId' => (int) $row['consho_shop'],
                'currency' => (string) $row['currency'],
                'country' => (string) $row['country'],
                'includeCache' => (bool) $row['includeCache'],
                'cache' => (int) $row['cache'],
            ],
            $this->connection->fetchAllAssociative(
                /*
                 * 🔴 The currency comes from the START PAGE and the home
                 * country from its shop — MarketCriteria says the same rule for
                 * the list prices, and it is the one rule (C-1c, spec 4.0).
                 * This row is per start page, so `s.currency` was the shop's
                 * answer to a question about the page: in the DACH case it
                 * asked the franc start page in euro — on BOTH sides of the
                 * comparison, since find()/visibleAmong() read the projection
                 * and rows->mains() calculates what it should hold. The report
                 * then named products with confidence and no ground.
                 */
                "SELECT p.id, p.title, p.language, p.published, p.consho_shop, p.consho_currency AS currency, s.country, p.includeCache, p.cache
                 FROM tl_page p
                 JOIN tl_consho_shop s ON s.id = p.consho_shop
                 WHERE p.type = 'root'
                 ORDER BY p.id",
            ),
        );
    }

    /**
     * @param list<ListingElement> $listings
     *
     * @return list<ListingElement>
     */
    private function listingsOf(int $rootId, array $listings): array
    {
        return array_values(array_filter(
            $listings,
            fn (ListingElement $listing): bool => 0 === $listing->pageId || $this->rootOf($listing->pageId) === $rootId,
        ));
    }

    private function rootOf(int $pageId): int|null
    {
        if (\array_key_exists($pageId, $this->roots)) {
            return $this->roots[$pageId];
        }

        $id = $pageId;

        // Bounded, in case a broken tree points at itself.
        for ($depth = 0; $depth < 64 && $id > 0; ++$depth) {
            $row = $this->connection->fetchAssociative('SELECT pid, type FROM tl_page WHERE id = ?', [$id]);

            if (false === $row) {
                break;
            }

            if ('root' === $row['type']) {
                return $this->roots[$pageId] = $id;
            }

            $id = (int) $row['pid'];
        }

        return $this->roots[$pageId] = null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function titles(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $titles = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title FROM tl_consho_product WHERE id IN (:ids) ORDER BY title, id', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]) as $row) {
            $titles[(int) $row['id']] = html_entity_decode((string) $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $titles;
    }
}
