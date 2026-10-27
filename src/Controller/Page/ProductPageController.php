<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Page;

use Contao\CoreBundle\Controller\Page\AbstractPageController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsPage;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Routing\Page\DynamicRouteInterface;
use Contao\CoreBundle\Routing\Page\PageRoute;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Seo\InvalidPathTemplate;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressLookup;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPathTemplate;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The page every product detail address belongs to.
 *
 * ── Why a page type and not a segment under the category ──────────────────
 *
 * A product is honestly in several categories. Putting one of them in the
 * address forces somebody to nominate the real one, and every shop that tried
 * had to invent a "main category" afterwards — see
 * Block B, section 4.2. One page, one canonical
 * address per product, and moving an article between categories breaks no link.
 *
 * ── Why the route is built here and not declared in the attribute ─────────
 *
 * `AsPage(path: …)` is compiled once for the whole type. Our path differs per
 * page, because each page carries its own template — so it has to be built at
 * runtime, which is what DynamicRouteInterface is for. The attribute therefore
 * declares no path at all.
 *
 * ── Why there is no `requireItem` ─────────────────────────────────────────
 *
 * Contao's flag exists to turn `(/.+?)?` into `/.+?` on the default route. Our
 * path has required placeholders of its own, so a request to the bare page
 * matches no route and answers 404 without a flag to forget.
 */
#[AsPage(type: ProductPageController::TYPE)]
class ProductPageController extends AbstractPageController implements DynamicRouteInterface
{
    public const TYPE = 'consho_product';

    /**
     * What the operator gets before touching anything: the shortest template
     * that works.
     */
    public const DEFAULT_TEMPLATE = '{alias}';

    /**
     * Every segment is one path element, so a slash may not slip into a value.
     */
    private const SEGMENT = '[^/]+';

    public function __construct(
        private readonly ShopResolver $shops,
        private readonly ProductAddressLookup $lookup,
        private readonly ShopLanguages $languages,
    ) {
    }

    public function configurePageRoute(PageRoute $route): void
    {
        $page = $route->getPageModel();

        /*
         * Read through row() rather than as a property: conshoPathTemplate is
         * added to tl_page by Consho's own DCA, so it is not part of PageModel's
         * declared surface — same as consho_shop in ShopResolver.
         */
        $stored = (string) ($page->row()['conshoPathTemplate'] ?? '');

        try {
            $template = ProductPathTemplate::fromString('' !== $stored ? $stored : self::DEFAULT_TEMPLATE);
        } catch (InvalidPathTemplate) {
            /*
             * A template is checked when it is saved, so this can only happen
             * to a row that predates the check or was written past the DCA.
             * Falling back keeps the rest of the site routable — one shop page
             * losing its custom shape is a smaller failure than every route in
             * the site failing to build.
             */
            $template = ProductPathTemplate::fromString(self::DEFAULT_TEMPLATE);
        }

        /*
         * Mirrors what PageRoute::__construct does for a relative path. The
         * route already exists at this point, so the prefix has to be applied
         * here rather than handed to the constructor.
         */
        $route->setPath('/'.($page->alias ?: $page->id).'/'.$template->toString());

        $requirements = $route->getRequirements();
        $defaults = $route->getDefaults();

        // The default route brought a catch-all `parameters` segment along.
        // Ours names its segments, so that one has nothing left to match.
        unset($requirements['parameters'], $defaults['parameters']);

        foreach ($template->placeholders() as $placeholder) {
            $requirements[$placeholder] = self::SEGMENT;
        }

        $route->setRequirements($requirements);
        $route->setDefaults($defaults);
    }

    public function getUrlSuffixes(): array
    {
        // The shop uses the site's suffix. Returning anything here would make
        // product pages answer on addresses the rest of the site does not use.
        return [];
    }

    /**
     * Where the resolved product id is put for the detail element in B-5.
     *
     * A request attribute rather than PageRoute::setContent(): the content of a
     * route is decided when the route is built, and at that point the segment
     * values do not exist yet.
     */
    public const PRODUCT_ATTRIBUTE = '_consho_product';

    public function __invoke(PageModel $pageModel, Request $request): Response
    {
        $shop = $this->shops->resolveForPage($pageModel);

        if (null === $shop) {
            /*
             * A product page outside a shop has nothing to show. This is a
             * setup mistake, not a visitor's — but answering 404 is still
             * right: there is no product at this address, and an error page
             * would be indexed.
             */
            throw new PageNotFoundException('Product page is not below a root page with a shop configuration');
        }

        $shopId = (int) $shop->id;

        /*
         * The start point's language, not this page's own column:
         * loadDetails() overwrites the property with the root page's value
         * (PageModel.php:1035) — see ProductListController.
         *
         * Without these two arguments the lookup compared against the default
         * alias and skipped the translation rule in VisibilityCriteria: under
         * a French start point the German alias answered 200, and a product
         * whose safety notices were never translated was shown anyway. M-3
         * had changed the lookup but not this caller, until B-5.
         */
        $productId = $this->lookup->find(
            $this->segmentsOf($request),
            $shopId,
            time(),
            (string) $pageModel->language,
            $this->languages->fallbackOf($shopId),
        );

        if (null === $productId) {
            throw new PageNotFoundException('No visible product for this address');
        }

        $request->attributes->set(self::PRODUCT_ATTRIBUTE, $productId);

        return $this->renderPage($pageModel);
    }

    /**
     * The route's own parameters, and only those.
     *
     * Reading the whole attribute bag would hand the lookup Contao's internals
     * — pageModel, _locale, _scope — and a placeholder named like one of them
     * would then silently filter by it.
     *
     * @return array<string, string>
     */
    private function segmentsOf(Request $request): array
    {
        $segments = [];

        foreach (ProductPathTemplate::KNOWN as $placeholder) {
            if ($request->attributes->has($placeholder)) {
                $segments[$placeholder] = (string) $request->attributes->get($placeholder);
            }
        }

        return $segments;
    }
}
