<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Countries;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Counts the product safety gaps for the back end start page.
 *
 * ⚠️ **Runs on every call of the start page.** Two aggregate queries, no row
 * lists — the same rule as DbalConfigurationIssueSource. A back end that an
 * extension makes sluggish gets switched off, not fixed.
 *
 * A variant with no manufacturer of its own is NOT a gap: empty means it
 * inherits from its main product, so the query only looks at products whose own
 * manufacturer is empty AND whose main product has none either.
 */
final class DbalProductSafetyIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ProductSafetyCheck $check,
    ) {
    }

    public function issues(): array
    {
        $withoutManufacturer = (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM tl_consho_product p
                JOIN tl_consho_product_type t ON t.id = p.productType
                LEFT JOIN tl_consho_product m ON m.id = p.pid
                WHERE t.kind = 'physical'
                  AND p.manufacturer = 0
                  AND (p.pid = 0 OR m.manufacturer = 0 OR m.manufacturer IS NULL)
                SQL,
        );

        /*
         * Only manufacturers a physical product actually points at — directly
         * or through the main product it inherits from. A record created in
         * advance is not a finding.
         */
        $manufacturers = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    f.id,
                    f.title,
                    f.country,
                    f.email,
                    f.euResponsible,
                    COUNT(p.id) AS physicalProducts
                FROM tl_consho_manufacturer f
                JOIN tl_consho_product p
                  ON p.manufacturer = f.id
                  OR (p.manufacturer = 0 AND p.pid IN (SELECT id FROM tl_consho_product WHERE manufacturer = f.id))
                JOIN tl_consho_product_type t ON t.id = p.productType
                WHERE t.kind = 'physical'
                GROUP BY f.id, f.title, f.country, f.email, f.euResponsible
                SQL,
        );

        /*
         * Grouped, not listed: the answer is "which number is doubled", and
         * the operator finds the products by searching for it. The GTIN column
         * is searchable, so that search is one field away.
         */
        $duplicates = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT gtin, COUNT(*) AS products
                FROM tl_consho_product
                WHERE gtin != ''
                GROUP BY gtin
                HAVING COUNT(*) > 1
                SQL,
        );

        return [
            ...$this->check->fromDuplicateGtins($duplicates),
            ...$this->check->fromProductsWithoutManufacturer($withoutManufacturer),
            ...$this->check->fromManufacturers(
                $manufacturers,
                Countries::getNames($GLOBALS['TL_LANGUAGE'] ?? 'en'),
            ),
        ];
    }
}
