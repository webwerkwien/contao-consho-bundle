<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

/**
 * What a shipping type does at hand-over (C19): the age it verifies (0 for
 * none) and whether the checkout has to ask for a date of birth — and since
 * E-3 whether it collects cash on delivery (Z2), which cash on delivery as a
 * payment method asks before it is offered — and since E-6 whether the carrier
 * needs the recipient's phone (V6): only then does the checkout ask for it,
 * and only then is it stored (data minimisation, not a field "just in case").
 */
final class ShippingCapabilities
{
    private const AGE_CHECKS = [0, 16, 18];

    public function __construct(
        public readonly int $ageCheck,
        public readonly bool $needsDateOfBirth,
        public readonly bool $cashOnDelivery = false,
        public readonly bool $needsPhone = false,
    ) {
        if (!\in_array($ageCheck, self::AGE_CHECKS, true)) {
            throw new \InvalidArgumentException(\sprintf('A hand-over age check is 0, 16 or 18, got %d.', $ageCheck));
        }
    }
}
