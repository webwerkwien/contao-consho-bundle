<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The only place a product address is put together.
 *
 * ── Why one place ─────────────────────────────────────────────────────────
 *
 * The address pattern is an operator setting (tl_page.conshoPathTemplate). A
 * single hard-coded "/produkt/" anywhere else — in a listing, a sitemap, a
 * canonical tag, an order confirmation — makes that setting a lie, and the lie
 * is invisible until somebody changes the pattern. There is a test for this
 * that greps the source rather than calling anything.
 *
 * ── Why it does not build the URL itself ──────────────────────────────────
 *
 * Contao's ContentUrlGenerator already turns a page plus parameters into an
 * address, honouring url prefix, url suffix, domain and https per root page.
 * Rebuilding that would be principle 5 in reverse — and it is the part that
 * quietly breaks on a multilingual site.
 */
final class ProductUrlGenerator
{
    /**
     * The query parameter that preselects a variant on its main product's page.
     *
     * A variant has no address of its own (specification 6.5), and this
     * parameter does not give it one: the canonical address stays the
     * product's. It carries the id, not an alias — variants have no
     * translation rows, so an alias would be wrong in every language but one.
     */
    public const VARIANT_PARAMETER = 'variant';

    public function __construct(
        private readonly ContentUrlGenerator $urls,
        private readonly ProductPageRepositoryInterface $repository,
        private readonly ContaoFramework $framework,
        private readonly ShopResolver $shopResolver,
        private readonly ShopLanguages $languages,
    ) {
    }

    /**
     * @throws ProductPageMissing
     */
    public function generate(
        int $productId,
        int $rootPageId,
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_URL,
        int $variantId = 0,
    ): string {
        $pageId = $this->repository->findForRootPage($rootPageId);

        if (null === $pageId) {
            throw ProductPageMissing::belowRootPage($rootPageId);
        }

        /*
         * ⚠️ Not optional, and the reason is worth keeping.
         *
         * This is called from an exception listener as well (B-2, the redirect
         * from a superseded address). A 404 raised by Symfony's router happens
         * BEFORE Contao has initialized its framework — PageModel::findById()
         * then finds nothing, this method reports a missing product page, and
         * the redirect silently does not happen. Measured on the test server on
         * 2026-09-11: the row was there, the query returned it, and the address
         * still answered 404.
         *
         * initialize() is idempotent, so paying for it here costs nothing when
         * the framework is already up.
         */
        $this->framework->initialize();

        $pageAdapter = $this->framework->getAdapter(PageModel::class);
        $page = $pageAdapter->findById($pageId);

        if (!$page instanceof PageModel) {
            throw ProductPageMissing::belowRootPage($rootPageId);
        }

        [$language, $fallback] = $this->languageOf($rootPageId, $pageAdapter);

        $values = $this->valuesUsedBy($page, $productId, $language, $fallback);

        // Not declared by the route, so Symfony's generator appends it as a
        // query — the very behaviour valuesUsedBy() exists to prevent for the
        // pattern's own values, and exactly what a preselection needs.
        if ($variantId > 0) {
            $values[self::VARIANT_PARAMETER] = (string) $variantId;
        }

        return $this->urls->generate($page, $values, $referenceType);
    }

    /**
     * The language of the address, and the shop's fallback language.
     *
     * Not $page->language: $page is the product DETAIL page, and that
     * property only carries its startpoint's language after loadDetails() has
     * run (PageModel.php:1035) — which nothing here calls. The root page is
     * not a detour around that: $rootPageId already names it, it is the
     * detail page's own startpoint, and a root page's `language` column is
     * authoritative on itself without loadDetails() (loadDetails() assigns it
     * from itself for type 'root' — see the same code, a few lines up).
     *
     * @param Adapter<PageModel> $pageAdapter
     *
     * @return array{0: string, 1: string}
     */
    private function languageOf(int $rootPageId, Adapter $pageAdapter): array
    {
        $root = $pageAdapter->findById($rootPageId);

        if (!$root instanceof PageModel) {
            return ['', ''];
        }

        /*
         * Deliberately not `$root->language ?? ''`: Model::__isset() checks
         * $arrData directly, so `??` would short-circuit to '' on a mock
         * whose __get() is stubbed but whose __isset() is not — the exact
         * "PageModel without loaded details" trap this method exists to
         * avoid. Model::__get() already returns null for an unset key, and
         * (string) null is '', so the cast alone is enough.
         */
        $language = (string) $root->language;
        $shopId = $this->shopResolver->shopIdOfPage($root);

        return [$language, $this->languages->fallbackOf($shopId)];
    }

    /**
     * Only the values the page's pattern actually contains.
     *
     * 🔴 Measured on the test server on 2026-09-11, and it cost a wrong canonical
     * address: handing all four values to the generator produced
     *
     *     /produkt/swiss-herbs-ag/schwedenbitter.html?sku=SB-250&type=
     *
     * ContentUrlResolverInterface promises that unused parameters are ignored
     * rather than appended — but that promise covers getParametersForContent()
     * of a resolver, NOT values passed straight into generate(). Those reach
     * Symfony's generator, which appends what the route does not declare.
     *
     * @return array<string, string>
     */
    private function valuesUsedBy(PageModel $page, int $productId, string $language, string $fallback): array
    {
        $stored = (string) ($page->row()['conshoPathTemplate'] ?? '');

        try {
            $template = ProductPathTemplate::fromString('' !== $stored ? $stored : ProductPageController::DEFAULT_TEMPLATE);
        } catch (InvalidPathTemplate) {
            // Same fallback as the route builder, for the same reason: a
            // pattern written past the DCA must not take every address down.
            $template = ProductPathTemplate::fromString(ProductPageController::DEFAULT_TEMPLATE);
        }

        $values = $this->repository->urlValues($productId, $language, $fallback);

        return array_intersect_key($values, array_flip($template->placeholders()));
    }
}
