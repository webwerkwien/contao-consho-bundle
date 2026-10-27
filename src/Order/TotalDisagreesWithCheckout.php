<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The order came to a different figure than the one the buyer pressed the
 * button on — so no order was written.
 *
 * This is the belt to the braces of CheckoutFingerprint. The checksum proves
 * that the INPUT has not changed since the page was rendered; this proves that
 * two pieces of code agree on what that input costs. They are different
 * questions, and the second one was once answered wrong for as long as
 * there had been an answer: the checkout summed rounded line grosses,
 * the order summed rounded net plus rounded tax, and they disagreed for
 * 16.7 % of gross prices at the standard rate — with an invoice issued in the
 * same transaction.
 *
 * A cent is not much. An invoice over an amount the customer never saw is, and
 * § 8 FAGG is about exactly the figure above the button.
 *
 * If this is ever thrown, something in the pricing has drifted apart again.
 * Refusing is the right answer: the alternative is a document that nobody can
 * defend.
 */
final class TotalDisagreesWithCheckout extends \RuntimeException
{
    public static function between(string $expected, string $calculated): self
    {
        return new self(\sprintf(
            'The checkout showed %s, the order came to %s. No order was placed — the two ways of adding up the same basket have drifted apart.',
            $expected,
            $calculated,
        ));
    }
}
