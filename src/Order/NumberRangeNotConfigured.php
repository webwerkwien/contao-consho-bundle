<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * No counter exists for this shop and type.
 *
 * Fatal rather than silently starting at 1: an invoice sequence that quietly
 * restarts is worse than one that fails loudly, because the tax office sees
 * the result and the shop owner does not.
 */
final class NumberRangeNotConfigured extends \RuntimeException
{
    /** Which range was missing — D-3 names it to the operator. */
    public string $type = '';

    public static function for(int $shopId, string $type): self
    {
        $e = new self(sprintf('No number range of type "%s" configured for shop %d.', $type, $shopId));
        $e->type = $type;

        return $e;
    }
}
