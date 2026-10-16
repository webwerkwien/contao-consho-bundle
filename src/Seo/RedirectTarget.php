<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * What a superseded address leads to.
 *
 * Carries the shop as well as the root page because the next question after
 * "which product" is always "may it still be shown here" — and that is a
 * question about the shop, not about the page.
 */
final class RedirectTarget
{
    public function __construct(
        public readonly int $productId,
        public readonly int $rootPageId,
        public readonly int $shopId,
        // The startpoint's language — visibility is asked in it, as the page itself would be.
        public readonly string $language = '',
    ) {
    }
}
