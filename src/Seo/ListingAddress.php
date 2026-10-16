<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * What the address of a listing page asks for — read by ListingAddressResolver.
 *
 * Three shapes, and only these:
 *
 * | category     | the page itself, with whatever cq carries        |
 * | landing page | a released combination below the page, plus sort |
 * | invalid      | an address Consho never links to — a 404        |
 *
 * An invalid address carries an empty state rather than null, so a caller that
 * forgets to check $valid shows the whole category instead of failing on null.
 */
final class ListingAddress
{
    private function __construct(
        public readonly bool $valid,
        public readonly FilterState $state,
        public readonly ?FacetPageRecord $facet,
    ) {
    }

    public static function category(FilterState $state): self
    {
        return new self(true, $state, null);
    }

    public static function landingPage(FacetPageRecord $facet, FilterState $state): self
    {
        return new self(true, $state, $facet);
    }

    public static function invalid(): self
    {
        return new self(false, new FilterState(), null);
    }
}
