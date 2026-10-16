<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * What a product page has to show under the GPSR.
 *
 * The contract Block B renders against. Every field is a string, never null:
 * a template that has to distinguish null from empty gets the distinction wrong
 * once and prints "null" on a live shop.
 *
 * ── Two different obligations, and they are NOT displayed in the same place ──
 *
 * The manufacturer block belongs in a clearly labelled tab — that is expressly
 * acceptable, provided the tab says what is in it and the buyer can reach it
 * before ordering.
 *
 * The warnings do not. "Clearly and visibly" for a hazard means next to the
 * goods, not one click away. A tab that hides "contains alcohol" is the kind of
 * compliance that satisfies a checklist and not a regulator.
 */
final class ProductSafetyInfo
{
    public function __construct(
        public readonly bool $required = false,
        public readonly string $manufacturerName = '',
        public readonly string $manufacturerAddress = '',
        public readonly string $manufacturerEmail = '',
        public readonly string $manufacturerContactUrl = '',
        public readonly string $euResponsibleName = '',
        public readonly string $euResponsibleAddress = '',
        public readonly string $euResponsibleEmail = '',
        public readonly string $warnings = '',
        public readonly string $safetyInformation = '',
    ) {
    }

    /**
     * Whether there is a manufacturer block to render at all.
     *
     * Distinct from `$required`: required says the law wants one, this says
     * whether one exists. A shop mid-setup has the first without the second,
     * and the template must render nothing rather than an empty frame — the
     * gap is reported on the back end start page, which is where the operator
     * can act on it.
     */
    public function hasManufacturer(): bool
    {
        return '' !== $this->manufacturerName;
    }

    public function hasEuResponsible(): bool
    {
        return '' !== $this->euResponsibleName;
    }
}
