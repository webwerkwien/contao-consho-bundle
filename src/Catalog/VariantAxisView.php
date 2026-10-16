<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One variant axis — a property group — with its options in editorial order.
 */
final class VariantAxisView
{
    /**
     * @param list<VariantOptionView> $options ordered by `sorting`, then `id`
     */
    public function __construct(
        public readonly int $groupId,
        /** The group title in the page's language, the default language's otherwise. */
        public readonly string $label,
        public readonly array $options,
    ) {
    }

    /**
     * @param list<VariantOptionView> $options
     */
    public function withOptions(array $options): self
    {
        return new self($this->groupId, $this->label, $options);
    }
}
