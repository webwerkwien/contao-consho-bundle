<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * Filter and sort state of a product listing.
 *
 * Pagination is deliberately NOT part of this. Filter state is excluded from
 * crawling; page numbers must stay crawlable, or products that appear only on
 * page three are never discovered.
 */
final class FilterState
{
    /**
     * @param array<string, list<string>> $filters       property group alias => option aliases
     * @param bool                        $availableOnly "Nur verfügbare" (block Verfügbarkeit, V5) — like the sort
     *                                                   order no filter of its own: it never makes a 404 and may
     *                                                   stand on a landing page
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly string $sort = '',
        public readonly bool $availableOnly = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->filters && '' === $this->sort && !$this->availableOnly;
    }
}
