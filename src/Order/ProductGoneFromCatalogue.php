<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * A line refers to a product that no longer exists.
 *
 * Fatal at creation time, and only there. Once the order exists its lines carry
 * frozen values, so deleting the product afterwards changes nothing — that is
 * the whole point of freezing. What must not happen is an order coming into
 * being with a name and price nobody could look up.
 */
final class ProductGoneFromCatalogue extends \RuntimeException
{
    public static function withId(int $productId): self
    {
        return new self(sprintf('Product %d is no longer in the catalogue.', $productId));
    }
}
