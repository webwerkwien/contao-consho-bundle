<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener;

use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\SitemapEvent;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\FacetPageHits;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Adds product addresses and facet landing pages to Contao's own sitemap
 * (plan B-6, specification 8.2).
 *
 * Contao's SitemapController walks the page tree and generates each page's
 * address WITHOUT parameters; the product page needs an alias, so it is
 * skipped silently. Afterwards it dispatches contao.sitemap for exactly this.
 *
 * ── Products: the listing's query, not a copy of it ──────────────────────
 *
 * ProductQuery::find() is what the listing asks: visible after section 6.6,
 * priced (decision E1 — a product without a price is not reported), in the
 * start point's language. A condition written here would be the copy the
 * specification warns about, and the forgotten copy is always the sitemap's.
 * No variants: they have no address. No cq: robots.txt excludes it.
 *
 * ── Landing pages: only where Contao reported their listing page ─────────
 *
 * Whether a page belongs in a sitemap is Contao's decision — published, not
 * protected, not noindex, routable (SitemapController). Rebuilding that would
 * be a second copy again. So a landing page is added only when its listing
 * page's own address is already in the document: it inherits every one of
 * Contao's rules, including future ones.
 *
 * And only while it shows something (B-8): a landing page matching nothing
 * answers 404, see FacetPageHits.
 */
#[AsEventListener(ContaoCoreEvents::SITEMAP)]
final class SitemapListener
{
    private const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ShopResolver $shops,
        private readonly ShopLanguages $languages,
        private readonly ProductQuery $products,
        private readonly ProductUrlGenerator $productUrls,
        private readonly FacetPageRepositoryInterface $facetPages,
        private readonly FilterUrlGenerator $filterUrls,
        private readonly FilterStateCodec $codec,
        private readonly ContentUrlGenerator $pageUrls,
        private readonly FacetPageHits $hits,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(SitemapEvent $event): void
    {
        $this->framework->initialize();

        $pages = $this->framework->getAdapter(PageModel::class);
        $now = null !== $this->clock ? ($this->clock)() : time();
        $present = $this->addressesIn($event->getDocument());
        $shopRoots = [];

        foreach ($event->getRootPageIds() as $rootId) {
            $root = $pages->findById((int) $rootId);

            if (!$root instanceof PageModel) {
                continue;
            }

            $shop = $this->shops->resolveForPage($root);

            if (null === $shop) {
                continue;
            }

            $shopId = (int) $shop->id;

            // The currency of this start point (spec 4.0), which is also what
            // keys the visibility projection read below. Two start points of
            // one shop in two currencies therefore get two product lists, not
            // one — which is the whole reason the sitemap is built per root
            // page rather than per shop.
            $currency = $this->shops->currencyOfPage($root);
            $shopRoots[(int) $root->id] = [$shopId, (string) $root->language, $currency];

            $ids = $this->products->find(
                $shopId,
                [],
                (string) $root->language,
                $currency,
                $this->languages->fallbackOf($shopId),
                ProductSort::TITLE,
            );

            foreach ($ids as $productId) {
                try {
                    $address = $this->productUrls->generate($productId, (int) $root->id);
                } catch (ProductPageMissing) {
                    // A start point with a shop but no product page: nothing to
                    // address, and every product would fail the same way.
                    break;
                }

                $this->add($event, $present, $address);
            }
        }

        foreach ($this->facetPages->findAll() as $landing) {
            $page = $pages->findWithDetails($landing->pageId);

            if (!$page instanceof PageModel || !isset($shopRoots[(int) $page->rootId])) {
                continue;
            }

            try {
                $pageAddress = $this->pageUrls->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL);
            } catch (ExceptionInterface) {
                continue;
            }

            if (!isset($present[$pageAddress])) {
                continue;
            }

            [$shopId, $language, $currency] = $shopRoots[(int) $page->rootId];

            if (!$this->hits->any($landing, $shopId, $now, $language, $currency)) {
                continue;
            }

            $this->add(
                $event,
                $present,
                $this->filterUrls->addressOf($page, $this->codec->decode($landing->filterState), UrlGeneratorInterface::ABSOLUTE_URL),
            );
        }
    }

    /**
     * @return array<string, true>
     */
    private function addressesIn(\DOMDocument $document): array
    {
        $present = [];

        foreach ($document->getElementsByTagNameNS(self::NAMESPACE, 'loc') as $loc) {
            $present[trim($loc->textContent)] = true;
        }

        return $present;
    }

    /**
     * @param array<string, true> $present
     */
    private function add(SitemapEvent $event, array &$present, string $address): void
    {
        if (isset($present[$address])) {
            return;
        }

        $event->addUrlToDefaultUrlSet($address);
        $present[$address] = true;
    }
}
