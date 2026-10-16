<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * No product detail page exists below a root page that runs a shop.
 *
 * Thrown rather than returning an empty string: a link to "" renders as a link
 * to the current page, so a shop missing its product page would look like a
 * shop full of products that all point at themselves. Failing loudly during
 * setup is the cheaper outcome.
 */
final class ProductPageMissing extends \RuntimeException
{
    public static function belowRootPage(int $rootPageId): self
    {
        return new self(\sprintf(
            'No published Consho product page exists below root page %d. Product addresses cannot be generated for this site.',
            $rootPageId,
        ));
    }
}
