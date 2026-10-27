<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

/**
 * How long a basket lives — fixed in code, not configurable (by decision).
 *
 * checkoutData carries addresses, the VAT ID result and a date of birth from
 * C-5 on. A setting per shop would mean every installation has to get it
 * right, and the one that does not keeps personal data for ten years without
 * anybody noticing. Two constants are the whole configuration surface.
 *
 * CONVERTED is shorter than OPEN because everything worth keeping has moved
 * into the order by then: the basket is only still there so that a second
 * click on "buy" finds it and answers with the order page (7.3, C-7).
 */
final class CartLifetime
{
    /** An open basket, counted from its last change. */
    public const OPEN = 30 * 86400;

    /** A basket that became an order. */
    public const CONVERTED = 7 * 86400;

    /**
     * What the visitor entered in the checkout, counted from its last entry
     * and independent of the lines (spec C, C-5 point 1; E1). A member finds the address at the
     * account again, a guest types it anew; nobody needs it for a month.
     */
    public const CHECKOUT_DATA = 7 * 86400;

    /** The cookie — as long as an open basket, so neither outlives the other. */
    public const COOKIE = self::OPEN;
}
