<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;

/**
 * Reads what the address of a listing page asks for.
 *
 * Once per page and request: the listing, the filter element and the page head
 * all ask the same question, and only one of them should ask the database.
 * Keyed by page, because the answer belongs to the page — the same segment
 * means something else below another category.
 *
 * ── Why auto_item goes through Input::get() ───────────────────────────────
 *
 * Contao records every path segment as unused (InputEnhancer) and turns an
 * unused one into a 404 once the response is ready
 * (LegacyRouteParametersListener). Input::get() is what marks it used. Reading
 * the request attribute directly returns the same value and leaves the page
 * answering 404 — and so does Input::get() with its third argument set to true.
 *
 * That is also the safety net: a segment under a page whose listing does not
 * follow the address is never read, and Contao answers 404 by itself.
 *
 * Input is Contao's legacy parameter handling. Reading it in this one class
 * keeps a later move away from it to one place.
 *
 * ── Why cq is read through query->all() ───────────────────────────────────
 *
 * InputBag::get() throws BadRequestException for a non-scalar value, so ?cq[]=x
 * would answer 400. FilterStateCodec::decode() never throws on bad input, and
 * the way to it must not either (Block B, 10).
 */
final class ListingAddressResolver implements ResetInterface
{
    /** @var array<int, ListingAddress> */
    private array $resolved = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly FilterStateCodec $codec,
        private readonly FacetPageRepositoryInterface $facets,
    ) {
    }

    public function resolve(PageModel $page, Request $request): ListingAddress
    {
        return $this->resolved[(int) $page->id] ??= $this->read((int) $page->id, $request);
    }

    /**
     * The cache must not outlive the request once the kernel is reused.
     */
    public function reset(): void
    {
        $this->resolved = [];
    }

    private function read(int $pageId, Request $request): ListingAddress
    {
        $raw = $request->query->all()[FilterStateCodec::PARAMETER] ?? null;
        $query = $this->codec->decode(\is_string($raw) ? $raw : null);

        // A sort key the listing does not know is no sort order: the listing
        // falls back to its default, and every reader of the address has to see
        // the same. Left in, it would drop the page number from the canonical
        // and travel on in every filter link — a cq of garbage treated like an
        // empty one, the same as ?cq=Foo, which decodes to nothing anyway.
        if ('' !== $query->sort && !ProductSort::isKnown($query->sort)) {
            $query = new FilterState($query->filters, '', $query->availableOnly);
        }

        // Idempotent, so it costs nothing where the framework is already up —
        // see ProductUrlGenerator for where it was not.
        $this->framework->initialize();

        $alias = $this->framework->getAdapter(Input::class)->get('auto_item');

        if (!\is_string($alias) || '' === $alias) {
            return ListingAddress::category($query);
        }

        $facet = $this->facets->findByAlias($pageId, $alias);

        // A landing page carries its own filters. Filters in cq on top of it
        // make an address Consho never links to: the union of both has exactly
        // one address, and that is the category with cq. Only a sort order may
        // stay, because it produces no new set of products — and "Nur
        // verfügbare", which narrows by stock, not by what the page is about
        // (block Verfügbarkeit, V5).
        if (null === $facet || [] !== $query->filters) {
            return ListingAddress::invalid();
        }

        return ListingAddress::landingPage(
            $facet,
            new FilterState($this->codec->decode($facet->filterState)->filters, $query->sort, $query->availableOnly),
        );
    }
}
