<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * A legal text was no longer readable at the moment of the click.
 *
 * CheckoutReadiness blocks a shop whose terms or withdrawal instruction cannot
 * be reached, and the fingerprint refuses a purchase where one changed or
 * disappeared while the buyer was reading (C-7, F3). This is the sliver
 * between that check and the read that freezes them: an element whose time
 * window ends in those milliseconds.
 *
 * Refusing is the only sensible answer. An order that records a ticked box and
 * no wording proves nothing — it is worse than no record, because it looks
 * like one.
 */
final class LegalTextGoneAtPurchase extends \RuntimeException
{
    public static function forKind(string $kind): self
    {
        return new self(\sprintf(
            'The legal text "%s" could not be read at the moment of the purchase; no order was placed.',
            $kind,
        ));
    }
}
