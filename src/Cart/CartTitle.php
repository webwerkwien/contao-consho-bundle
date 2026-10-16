<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

/**
 * What one basket line is called: the main product's title, and the article's
 * own item number that tells two lines of the same product apart.
 *
 * Two fields rather than one composed string. The composing is the template's
 * job — it is the only place with a translator, and the same pair is needed
 * twice over, once visibly and once in the accessible name of the quantity
 * field and the buttons.
 *
 * `sku` is empty where none is maintained; it is an optional field in
 * tl_consho_product. CartController then falls back to the line's position in
 * the basket, which needs no data at all.
 */
final class CartTitle
{
    public function __construct(
        public readonly string $title,
        public readonly string $sku,
    ) {
    }
}
