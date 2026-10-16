<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A kind of shipping method (spec 6.1, C1–C4). The core ships `flat_rate`;
 * carriers come as modules.
 *
 * Registered under a fixed key, never the class name (research, conclusion 1):
 * the key is stored in tl_consho_shipping_method.type, and renaming a class
 * must not orphan every method of that type.
 *
 * A type never reads the session or a table (conclusion 2, C3). It gets its
 * settings as a ready array — the decoded virtual fields of its method — and
 * the cart as a ShippingContext. Should the storage of the settings change,
 * only the module's DCA changes, not its PHP.
 *
 * ⚠️ Reserved key in $config: `net` — the amount from the method's price rows
 * (tl_consho_shipping_price) in the context currency, as a 4-place string, or
 * null without a saved row. The core puts it there; a module must not define a
 * virtual field of that name.
 *
 * ⚠️ Reserved key `rates` as well (E-4): EVERY price row in the context
 * currency as a list of ['maxWeight' => kg, 'net' => amount], 4-place
 * strings, sorted by weight — the brackets of WeightRateShipping.
 *
 * The literal tag is repeated in ShippingMethodTypes; a test holds them together.
 */
#[AutoconfigureTag('consho.shipping_method_type')]
interface ShippingMethodTypeInterface
{
    public static function getType(): string;

    /**
     * Whether this method can serve the cart at all (country, contents).
     *
     * @param array<string, mixed> $config
     */
    public function isAvailable(ShippingContext $context, array $config): bool;

    /**
     * Net amount in the context currency. Tax is split by the core (C4), never here.
     *
     * @param array<string, mixed> $config
     */
    public function calculate(ShippingContext $context, array $config): ShippingQuote;

    /**
     * Age check at hand-over and whether a date of birth is needed (C19).
     * Declared by the code, not configurable — otherwise a flat rate could be
     * told it "checks from 18".
     *
     * @param array<string, mixed> $config
     */
    public function capabilities(array $config): ShippingCapabilities;
}
