<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Contao\StringUtil;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterPanelBuilder;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingElement;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddressResolver;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The filter controls of a product listing — links, not a form (B-4 decision 7).
 *
 * A separate element because filters stand in a side column and the listing in
 * the main one. Since 5.7 layout sections take content elements, so ONE filter
 * element in the layout serves every category page
 * (docs/block-b-katalog-frontend.md, 3.3).
 *
 * ── Which listing ─────────────────────────────────────────────────────────
 *
 * conshoListElement empty: the first listing following the address in the
 * current page's articles. Set: exactly that listing — the way to reach one
 * that stands in a layout section itself, which the empty field never finds.
 * A listing set explicitly that stands in an article of ANOTHER page is not
 * used: every link is built for the current page, where nothing would read it.
 *
 * No shop, no listing, a listing that ignores the address, or an address that
 * does not exist: the element renders nothing and says nothing. A half-built
 * page is a normal state, and on a 404 the listing throws — controls would be
 * the only thing left on the error page.
 *
 * ── ⚠️ The type is declared, not derived ──────────────────────────────────
 *
 * Contao would derive "product_filter" from the class name, with no bundle
 * prefix, and the element would silently render nothing — see
 * ProductListController, where that was measured.
 */
#[AsContentElement(type: 'consho_product_filter', category: 'consho')]
class ProductFilterController extends AbstractContentElementController
{
    public function __construct(
        private readonly ShopResolver $shops,
        private readonly ListingPageRepository $listings,
        private readonly ListingAddressResolver $addresses,
        private readonly FilterPanelBuilder $panels,
        private readonly ShopLanguages $languages,
        private readonly PageRegistry $pageRegistry,
        private readonly DbalCatalogSchedule $schedule,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $template->set('groups', []);
        $template->set('available', null);

        $page = $request->attributes->get('pageModel');

        // An unroutable page — the error_404 page a layout filter also renders
        // on — has no address to link to. Stopping here saves the count
        // queries for a panel FilterPanelBuilder would leave empty anyway.
        if (!$page instanceof PageModel || !$this->pageRegistry->isRoutable($page)) {
            return $template->getResponse();
        }

        $shop = $this->shops->resolveForPage($page);

        if (null === $shop) {
            return $template->getResponse();
        }

        $now = time();
        $listing = $this->listingFor($model, $page, $now);

        if (null === $listing || !$listing->followsAddress) {
            return $template->getResponse();
        }

        $address = $this->addresses->resolve($page, $request);

        if (!$address->valid) {
            return $template->getResponse();
        }

        $shopId = (int) $shop->id;

        // The start point's language after loadDetails() — see ProductListController.
        // Its currency likewise, which is what keys the visibility projection
        // the counts are read from (spec 4.0).
        $template->set('groups', $this->panels->build(
            $page,
            $shopId,
            $listing,
            $address,
            self::ids($model->row()['conshoGroups'] ?? null),
            (string) ($page->language ?? ''),
            $this->shops->currencyOfPage($page),
            $this->languages->fallbackOf($shopId),
        ));

        // "Nur verfügbare" (block Verfügbarkeit, V5), offered where the editor
        // switched it on — off by default, so no existing filter changes.
        if ((bool) ($model->row()['conshoAvailableFilter'] ?? false)) {
            $template->set('available', $this->panels->availability(
                $page,
                $shopId,
                $listing,
                $address,
                (string) ($page->language ?? ''),
                $this->shops->currencyOfPage($page),
            ));
        }

        $response = $template->getResponse();

        // The counts move with the catalogue, the same as the listing (B-7b).
        $this->tagResponse([CatalogCacheTags::LISTINGS, CatalogCacheTags::CATALOG]);

        $nextChange = $this->schedule->nextProductChange($now);

        if (null !== $nextChange) {
            $response->setSharedMaxAge(max(1, $nextChange - $now));
        }

        return $response;
    }

    private function listingFor(ContentModel $model, PageModel $page, int $now): ?ListingElement
    {
        // Read through row() for the same reason as in ProductListController:
        // Consho's own columns are not part of ContentModel's declared surface.
        $chosen = (int) ($model->row()['conshoListElement'] ?? 0);

        if ($chosen < 1) {
            return $this->listings->firstOnPage((int) $page->id, $now);
        }

        $listing = $this->listings->byId($chosen);

        // pageId 0: a layout section (or nested), which belongs to every page.
        if (null !== $listing && 0 !== $listing->pageId && $listing->pageId !== (int) $page->id) {
            return null;
        }

        return $listing;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $stored): array
    {
        $values = StringUtil::deserialize($stored, true);

        return array_values(array_map(intval(...), array_filter($values, is_numeric(...))));
    }
}
