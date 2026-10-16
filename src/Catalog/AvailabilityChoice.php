<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * "Nur verfügbare" in the filter element (block Verfügbarkeit, V5) — the same
 * three things an option carries, without a group around it.
 */
final class AvailabilityChoice
{
    public function __construct(
        /** Part of the current address. */
        public readonly bool $selected,
        /** Products left with it ticked; null when the listing shows no numbers. */
        public readonly ?int $count,
        /** Where a click leads — ticking or unticking; null when ticking would leave nothing. */
        public readonly ?string $url,
    ) {
    }
}
