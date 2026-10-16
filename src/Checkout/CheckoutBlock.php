<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * The four fixed building blocks a checkout is made of (C13). How many steps
 * they are spread over is set on the content element; which blocks exist is
 * not configurable — a shop without an address block is not a checkout.
 *
 * `Shipping` drops out when the basket holds nothing shippable (4.3).
 */
enum CheckoutBlock: string
{
    case Address = 'address';
    case Shipping = 'shipping';
    case Payment = 'payment';
    case Review = 'review';

    /**
     * The translation key for a step, built from the blocks IT contains.
     *
     * 🔴 Found in the review of 2026-09-22. The first version numbered the
     * names — `step.1`, `step.2` — against a fixed list, so with three steps a
     * screen reader announced "Bestellung / Angaben / Versand und Zahlung"
     * while the third step was in fact "Prüfen und kaufen". Every name but the
     * first was wrong, and with four steps everything was off by one.
     *
     * A name that comes from the content cannot drift from it: there are
     * seven groups across the four layouts, and each has its own sentence.
     *
     * @param list<self> $blocks
     */
    public static function labelOf(array $blocks): string
    {
        return implode('_', array_map(static fn (self $block): string => $block->value, $blocks));
    }

    /**
     * How the blocks are grouped for a given number of steps (Michael,
     * 2026-09-22).
     *
     * "Review stays on its own", from one step up: § 8 FAGG wants the
     * essential details directly above the button, and a step of its own gets
     * that right without anybody having to think about it. With a single step
     * everything is on one page, and the details still sit directly above the
     * button because nothing else follows them.
     *
     * A block that does not apply is filtered out afterwards, not here: the
     * grouping is a decision, "no shipping needed" is a fact about a basket,
     * and mixing them would make the table below untestable on its own.
     *
     * @return list<list<self>>
     */
    public static function steps(int $count): array
    {
        return match (max(1, min(4, $count))) {
            1 => [[self::Address, self::Shipping, self::Payment, self::Review]],
            2 => [[self::Address, self::Shipping, self::Payment], [self::Review]],
            3 => [[self::Address], [self::Shipping, self::Payment], [self::Review]],
            default => [[self::Address], [self::Shipping], [self::Payment], [self::Review]],
        };
    }
}
