<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

/**
 * What the basket form asked for. A tiny object rather than two loose
 * variables, so "is this action known" is answered in one place.
 */
final class CartAction
{
    public function __construct(
        public readonly string $name,
        public readonly int $itemId,
    ) {
    }
}
