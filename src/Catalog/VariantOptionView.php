<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One option of a variant axis on the product page.
 *
 * VariantMatrix fills everything but the address: it stays free of Contao and
 * testable without a router. The element adds the address through withUrl(),
 * built by ProductUrlGenerator from $targetId — the same split as
 * FilterOptionView and FilterPanelBuilder.
 */
final class VariantOptionView
{
    public function __construct(
        public readonly int $optionId,
        /** The title in the page's language, the default language's otherwise. */
        public readonly string $label,
        /** Part of the chosen variant. */
        public readonly bool $selected,
        /** The variant a click leads to; null when no visible variant has this option. */
        public readonly ?int $targetId,
        /** The click also changes another axis, because the exact combination does not exist. */
        public readonly bool $changesOthers,
        /** Where a click leads; null until the element sets it, and whenever $targetId is null. */
        public readonly ?string $url = null,
        /**
         * The variant a click leads to is sold out (Block G, G7). Still a
         * link: its page stays, says so, and offers the notification.
         */
        public readonly bool $unavailable = false,
    ) {
    }

    public function withUrl(?string $url): self
    {
        return new self($this->optionId, $this->label, $this->selected, $this->targetId, $this->changesOthers, $url, $this->unavailable);
    }

    public function withUnavailable(bool $unavailable): self
    {
        return new self($this->optionId, $this->label, $this->selected, $this->targetId, $this->changesOthers, $this->url, $unavailable);
    }
}
