<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One property group a filter offers, with its options in their editorial order.
 */
final class FilterGroupView
{
    /**
     * @param list<FilterOptionView> $options ordered by `sorting`, then `id`
     */
    public function __construct(
        public readonly int $id,
        /** The record's own alias — language neutral, it is what `cq` carries. */
        public readonly string $alias,
        /** The title in the page's language, the default language's otherwise. */
        public readonly string $label,
        public readonly array $options,
    ) {
    }

    /**
     * @param list<FilterOptionView> $options
     */
    public function withOptions(array $options): self
    {
        return new self($this->id, $this->alias, $this->label, $options);
    }
}
