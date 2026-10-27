<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart\Discount;

/**
 * What became of the code the customer typed (block H, H-4) — said in the
 * basket, so a code that does nothing never looks as if it worked.
 */
enum CodeStatus: string
{
    // No code typed.
    case None = 'none';

    case Applied = 'applied';

    // No published rule has exactly this code (W6: case counts).
    case Unknown = 'unknown';

    // A rule with this code exists, but not now: before its start or after its end.
    case NotActive = 'notActive';

    // Not in this shop, this currency or for this customer's groups.
    case NotForYou = 'notForYou';

    // The goods do not reach the minimum order value (W3).
    case MinimumOrder = 'minimumOrder';

    // No goods line the rule may reduce: other products, subscriptions only,
    // or only reduced goods while the rule leaves those out.
    case NoMatchingGoods = 'noMatchingGoods';

    // All uses taken, in total or by this customer (W5).
    case Exhausted = 'exhausted';

    // Valid, but a rule without code takes more off — the better one applies (C3).
    case Superseded = 'superseded';

    // Free shipping where the order has no shipping to save (H): not applied, not used up.
    case NothingToSave = 'nothingToSave';
}
