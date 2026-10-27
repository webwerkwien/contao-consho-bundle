<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * Where the shop pages of a site are — the one rule, in one place.
 *
 * Three places want the basket: the add route redirects there (C12), the mini
 * basket links to it (C10), and a finding nags when it is missing. They used to
 * read `tl_page.consho_cartPage` each for themselves, which is exactly the kind
 * of duplication that drifts — one of them catching the routing exception, the
 * next one not.
 *
 * Renamed from CartPageUrl in C-5, which added the checkout page and is one of
 * five such pages in the end: C-6 brings terms, withdrawal and privacy, C-10
 * the order page. Every one of them is a page id on the root page, resolved and
 * routed the same way; a class per page would be the same body five times.
 *
 * ── Why null rather than an exception ────────────────────────────────────
 *
 * A basket page can be unset, deleted, unpublished, or sit in a site without a
 * root — and none of that may take down the page a visitor is looking at.
 * ContentUrlGenerator throws for a page it cannot route (the same reason
 * SitemapListener catches it), so that throw is caught here and answered with
 * null. Every caller then decides for itself: the add route falls back to the
 * page the visitor came from, the mini basket shows its number without a link.
 *
 * `PageModel::getAbsoluteUrl()` would be the shorter road and is deprecated
 * since Contao 5.3 — it reports that on every call.
 */
final class ShopPageUrls
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ShopResolver $shops,
        private readonly ContentUrlGenerator $urls,
    ) {
    }

    /**
     * The basket page of the site this page belongs to.
     */
    public function ofPage(PageModel $page): string|null
    {
        $root = $this->shops->rootOf($page);

        return null === $root ? null : $this->ofRoot($root);
    }

    /**
     * The same, for a caller that already holds the root page — the add route
     * does, and asking for it again would be one query for a value in hand.
     */
    public function ofRoot(PageModel $root): string|null
    {
        return $this->urlOf($root, 'consho_cartPage');
    }

    /**
     * The checkout page (C13). The basket links here, and every step of the
     * checkout is an address under it.
     */
    public function checkoutOfPage(PageModel $page): string|null
    {
        $root = $this->shops->rootOf($page);

        return null === $root ? null : $this->urlOf($root, 'consho_checkoutPage');
    }

    /**
     * The privacy page (9). Linked in the checkout, never agreed to.
     */
    public function privacyOfPage(PageModel $page): string|null
    {
        $root = $this->shops->rootOf($page);

        return null === $root ? null : $this->urlOf($root, 'consho_privacyPage');
    }

    /**
     * The page carrying the harmonised guarantee notice (§ 4 Abs 1 Z 12 FAGG).
     */
    public function warrantyOfPage(PageModel $page): string|null
    {
        $root = $this->shops->rootOf($page);

        return null === $root ? null : $this->urlOf($root, 'consho_warrantyPage');
    }

    /**
     * The order page (C21). Where a buyer lands after the purchase.
     */
    public function orderOfPage(PageModel $page): string|null
    {
        $root = $this->shops->rootOf($page);

        return null === $root ? null : $this->urlOf($root, 'consho_orderPage');
    }

    /**
     * The order page of a start point already in hand — the resend of a
     * confirmation (C-9) has the root, not a page of the site.
     */
    public function orderOfRoot(PageModel $root): string|null
    {
        return $this->urlOf($root, 'consho_orderPage');
    }

    /**
     * The page with Contao's "Lost password" module (K1).
     */
    public function passwordOfRoot(PageModel $root): string|null
    {
        return $this->urlOf($root, 'consho_passwordPage');
    }

    /**
     * Any page by id, routed the same way and failing the same way.
     *
     * The legal texts need this one: their page is not a field on the root
     * page but follows from the element the operator picked (C17), so the
     * caller arrives with an id instead of a field name.
     */
    public function ofPageId(int $pageId): string|null
    {
        return $this->route($pageId);
    }

    private function urlOf(PageModel $root, string $field): string|null
    {
        $pageId = (int) ($root->row()[$field] ?? 0);

        // Read from the row array: these columns come from Consho's own DCA
        // and are not declared on Contao's PageModel — the same reason
        // ShopResolver::shopIdOfPage() does it that way.
        return $this->route($pageId);
    }

    private function route(int $pageId): string|null
    {
        if ($pageId < 1) {
            return null;
        }

        $page = $this->framework->getAdapter(PageModel::class)->findById($pageId);

        if (!$page instanceof PageModel) {
            return null;
        }

        try {
            return $this->urls->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
