<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\PageModel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingElement;

/**
 * Title, description and canonical of a listing page — Contao's own where they
 * are right, set where they are not (docs/block-b-katalog-frontend.md, 4.5).
 *
 * | address                                  | title, description | canonical                              |
 * |------------------------------------------|--------------------|----------------------------------------|
 * | category, no cq                          | the page's         | Contao's, keeping page_c<ID>           |
 * | category, any other cq                   | the page's         | Contao's — the bare category           |
 * | landing page                             | the landing page's | set: its path address, + page_c<ID>    |
 * | category, cq = a released combination    | the landing page's | set: that path address, + page_c<ID>   |
 * | either of the last two, sorted           | the landing page's | set: the path address, no page number  |
 *
 * ── Why the canonical is left alone on the category ───────────────────────
 *
 * HtmlHeadBag::getCanonicalUriForRequest() drops every query parameter it is
 * not told to keep, so a cq address already points to the category. What it
 * also drops is the page number, which would make page two canonical to page
 * one — hence the keep param, and only without cq: a filtered page two is no
 * page of anything indexed. A canonicalLink the editor maintains on the page
 * still wins there, as it should.
 *
 * On a landing page the canonical is set explicitly for the opposite reason:
 * left to the default, that maintained canonicalLink would win here too.
 *
 * Robots are never touched. cq addresses are kept from crawling by robots.txt,
 * and Google advises against noindex on top (docs/block-a-fundament.md, 2).
 *
 * ── When it takes effect ──────────────────────────────────────────────────
 *
 * The element runs as a forward sub-request, which duplicates the main request
 * and with it the response context. The head is read after the elements: in
 * PageRegular "AFTER the modules have been generated", in the modern layout
 * inside {% defer %}. Three limits:
 *
 * - A layout template that overrides {% block head %} without defer renders
 *   the head before the listing has written to it.
 * - The element must not be registered with renderer "esi": a fragment cached
 *   on its own writes no head on a cache hit.
 * - Contao prints a canonical only where enableCanonical is on, and a page
 *   inherits that from its root page (PageModel::loadDetails()). Without it,
 *   everything set here stays unprinted.
 *
 * The page schema in JSON-LD keeps the category's title (B-6).
 */
final class ListingHead
{
    public function __construct(
        private readonly ResponseContextAccessor $contexts,
        private readonly FacetPageResolver $facets,
        private readonly FilterUrlGenerator $urls,
        private readonly HtmlDecoder $decoder,
    ) {
    }

    /**
     * Whether a listing that follows the address writes the head.
     *
     * The first such listing in the page's articles does, and no other: two of
     * them would both set the canonical, and whichever rendered last would win.
     *
     * Without one in an article, the listing that renders does. That is the
     * listing in a layout section, which firstOnPage() never finds — without
     * this, its category would get neither a landing page title nor a
     * canonical. Two listings following the address in the same layout would
     * both write again; one per layout is the setup this supports.
     */
    public static function isWrittenBy(?ListingElement $firstOnPage, int $elementId): bool
    {
        return null === $firstOnPage || $firstOnPage->id === $elementId;
    }

    public function apply(PageModel $page, ListingAddress $address, int $elementId, int $pageNumber): void
    {
        // The listing answers 404 for it; nothing here should look canonical.
        if (!$address->valid) {
            return;
        }

        $context = $this->contexts->getResponseContext();

        if (null === $context || !$context->has(HtmlHeadBag::class)) {
            return;
        }

        $head = $context->get(HtmlHeadBag::class);
        $state = $address->state;
        $pageParameter = ListingElement::pageParameter($elementId);

        // A category address with a released combination in cq is that landing
        // page's content: an old bookmark, or typed by hand. It gets its head,
        // not a redirect (decided 2026-09-13).
        $landing = $address->facet ?? $this->facets->landingFor((int) $page->id, $state);

        if (null === $landing) {
            // Only from page two on. The controller passes 1 for page one AND
            // for a listing without pagination, where any ?page_c<ID>=… is noise
            // a visitor typed. Keeping it unconditionally made every such value
            // canonical — measured on the test server on 2026-09-13, where
            // ?page_c636=0 came out as its own canonical address.
            if ($state->isEmpty() && $pageNumber > 1) {
                $head->addKeepParamsForCanonical($pageParameter);
            }

            return;
        }

        // Input-encoded in the database, decoded the way the core decodes the
        // page's own (CoreResponseContextFactory).
        $head->setTitle($this->decoder->inputEncodedToPlainText($landing->pageTitle));

        if ('' !== $landing->description) {
            $head->setMetaDescription($this->decoder->inputEncodedToPlainText($landing->description));
        }

        // Every page of the set is its own canonical. Sorted, page three shows
        // different products than unsorted page three, so the number would
        // claim something false and is left out.
        // "Nur verfügbare" (block Verfügbarkeit) narrows the set, so its page
        // three is no page of the landing page either.
        $query = '' === $state->sort && !$state->availableOnly && $pageNumber > 1 ? [$pageParameter => $pageNumber] : [];

        $head->setCanonicalUri(
            $this->urls->addressOf($page, new FilterState($state->filters), UrlGeneratorInterface::ABSOLUTE_URL, $query),
        );
    }
}
