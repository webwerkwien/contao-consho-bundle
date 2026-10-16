<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One option a filter offers: what the address carries, and what the visitor reads.
 *
 * FilterOptionRepository fills the first three; FilterPanelBuilder adds the
 * rest for the current address through withChoice().
 */
final class FilterOptionView
{
    public function __construct(
        public readonly int $id,
        /** The record's own alias — language neutral, it is what `cq` carries. */
        public readonly string $alias,
        /** The title in the page's language, the default language's otherwise. */
        public readonly string $label,
        /** Products left if it were chosen, its own group left out; null when the listing shows no numbers. */
        public readonly ?int $count = null,
        /** Part of the current address. */
        public readonly bool $selected = false,
        /** Where a click leads — choosing or deselecting it; null when it would lead to nothing. */
        public readonly ?string $url = null,
    ) {
    }

    public function withChoice(?int $count, bool $selected, ?string $url): self
    {
        return new self($this->id, $this->alias, $this->label, $count, $selected, $url);
    }
}
