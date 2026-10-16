<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\StringUtil;

/**
 * Title, description and canonical of a product page (B-5 decision 7).
 *
 * | field       | value                                                        |
 * |-------------|--------------------------------------------------------------|
 * | title       | the main product's title                                     |
 * | description | 1. the main product's own metaDescription, never cut         |
 * |             | 2. else its teaser, cut at a word to TEASER_LENGTH           |
 * |             | 3. else untouched — the page's own stays                     |
 * | canonical   | the product address, never with ?variant=, always set        |
 * | robots      | never touched                                                |
 *
 * Both texts arrive in the language of the page, already fallen back to the
 * default language (ProductDetailRepository). Decided by Michael, 2026-09-14;
 * no excerpt of the long description.
 *
 * ── Why there is no variant parameter ─────────────────────────────────────
 *
 * The head of a product page names the PRODUCT, never the variant: a variant
 * address is canonical to its product (B-5 decision 3, Google's guidance on
 * product variants), and a title that changed with ?variant= would contradict
 * that. Rather than accept a variant and ignore it, this takes none — the
 * caller passes the main product's id, title and teaser.
 *
 * ── Why the canonical is always set ───────────────────────────────────────
 *
 * Contao's default would drop ?variant= on its own (HtmlHeadBag::
 * getCanonicalUriForRequest()). But a canonicalLink the editor maintains on
 * the product page is written into the head before the element runs
 * (CoreResponseContextFactory), and left alone it would make every product
 * canonical to that one address. A set canonical also beats a maintained
 * canonicalKeepParams, so not even "variant" in that list brings it back.
 *
 * Robots are never touched. Variant addresses stay crawlable: the variant
 * links on the page lead there, and opened with ?variant= the page marks up
 * that variant's Offer (plan B-6, ProductJsonLd — one Product with an
 * AggregateOffer otherwise, not a ProductGroup). Google advises against
 * noindex on top (docs/block-a-fundament.md, 2).
 *
 * ── When it takes effect ──────────────────────────────────────────────────
 *
 * The same limits as ListingHead: the element runs as a forward sub-request
 * sharing the response context, so a layout that renders its head without
 * defer, or an element registered with renderer "esi", loses what is set here.
 * And Contao prints a canonical only where enableCanonical is on at the root
 * page. JSON-LD: ProductJsonLd.
 */
final class ProductHead
{
    /**
     * Where a teaser used as the description is cut.
     *
     * Contao sets a meta description as it gets it (HtmlHeadBag::
     * setMetaDescription()) and leaves the cutting to the search engine, which
     * does it mid-word. A teaser is written for the page, not for the results
     * list, so it is cut here at a word boundary instead. 160 is the number
     * the core's own SERP preview cuts the page description at
     * (core-bundle assets/controllers/serp-preview-controller.js) — what an
     * editor sees under tl_page.description is what a product gets.
     *
     * A meta description of the product's own is never cut: whoever writes
     * one decides its length.
     */
    private const TEASER_LENGTH = 160;

    public function __construct(
        private readonly ResponseContextAccessor $contexts,
        private readonly ProductUrlGenerator $urls,
        private readonly HtmlDecoder $decoder,
    ) {
    }

    /**
     * @param string $metaDescription input-encoded, the main product's own
     * @param string $teaser          input-encoded, the main product's
     */
    public function apply(int $productId, int $rootPageId, string $title, string $metaDescription, string $teaser): void
    {
        $context = $this->contexts->getResponseContext();

        if (null === $context || !$context->has(HtmlHeadBag::class)) {
            return;
        }

        $head = $context->get(HtmlHeadBag::class);

        // Input-encoded in the database, decoded the way the core decodes the
        // page's own (CoreResponseContextFactory). Meta description and teaser
        // are textareas without allowHtml, so the same applies to them.
        $head->setTitle($this->decoder->inputEncodedToPlainText($title));

        $description = $this->description($metaDescription, $teaser);

        // Nothing to say: the page's own description stays.
        if ('' !== $description) {
            $head->setMetaDescription($description);
        }

        try {
            $canonical = $this->urls->generate($productId, $rootPageId);
        } catch (ProductPageMissing) {
            // Hardly possible while standing on the product page. Without an
            // address there is no canonical to claim, and the page stays up.
            return;
        }

        $head->setCanonicalUri($canonical);
    }

    /**
     * The product's own meta description, otherwise its teaser cut to
     * TEASER_LENGTH, otherwise '' (Michael, 2026-09-14).
     */
    private function description(string $metaDescription, string $teaser): string
    {
        $own = trim($this->decoder->inputEncodedToPlainText($metaDescription));

        if ('' !== $own) {
            return $own;
        }

        // Cut BEFORE decoding. StringUtil::substr() strips tags, and a decoded
        // "&lt;süß&gt;" would go with them as if it were one. It counts every
        // word with its entities decoded, so the length is the same either way.
        return trim($this->decoder->inputEncodedToPlainText(StringUtil::substr($teaser, self::TEASER_LENGTH)));
    }
}
