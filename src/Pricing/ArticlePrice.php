<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * The price of one article as a product page shows it: the price at quantity
 * one, the scale table and the base price.
 *
 * `ownerId` is the article whose price rows were used. For a variant without a
 * single row of its own that is the main product — the whole price set is
 * inherited, scale included.
 *
 * `gross` is the display gross in the country asked for (C25), calculated with
 * the tax class of the article itself — also where the rows are inherited.
 *
 * The base price fields are either all set or all empty.
 *
 * TWO base prices since C-4, for the same reason there are two amounts: a
 * visitor shown net prices has to read a net base price beside them. § 4 PAngV
 * wants the base price given "in the same manner" as the total — a net amount
 * per litre next to a gross price per bottle compares nothing, and a gross one
 * next to a net price is worse than none.
 */
final class ArticlePrice
{
    /**
     * @param list<ScaleStep> $scale empty unless the article has two thresholds or more
     */
    public function __construct(
        public readonly ResolvedPrice $price,
        public readonly string $gross,
        public readonly int $ownerId,
        public readonly array $scale,
        public readonly string $basePrice = '',
        public readonly string $referenceUnit = '',
        public readonly string $unit = '',
        public readonly string $basePriceNet = '',
    ) {
    }

    /**
     * The amount this visitor reads, and the base price that belongs beside it.
     *
     * One method rather than two ternaries at each call site: they would have
     * to stay in step, and the day they do not, a net price gets a gross base
     * price under it.
     *
     * @return array{string, string} the displayed amount and its base price
     */
    public function display(bool $showsNet): array
    {
        return $showsNet
            ? [$this->price->net, $this->basePriceNet]
            : [$this->gross, $this->basePrice];
    }
}
