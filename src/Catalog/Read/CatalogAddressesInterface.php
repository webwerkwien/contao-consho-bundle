<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog\Read;

/**
 * Absolute addresses for the catalogue reader — the one part of it that
 * needs Contao's router and file system, kept behind an interface so the
 * reader can be tested against a real database without either.
 */
interface CatalogAddressesInterface
{
    /**
     * The product page of an article, with ?variant= for a variant; '' where
     * the start point has no product page.
     */
    public function product(int $mainId, int $variantId, CatalogContext $context): string;

    /**
     * @param list<string> $uuids string UUIDs, in the product's order
     *
     * @return list<string> one absolute address per PUBLIC file that still exists; the rest drop out
     */
    public function images(array $uuids, CatalogContext $context): array;
}
