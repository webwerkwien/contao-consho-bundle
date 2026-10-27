<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * Two key reads per product page, both on primary keys.
 *
 * `physical` (from the type's kind) comes along with the product because it decides whether anything
 * has to be shown at all, and asking for it separately would be a second query
 * for one boolean.
 */
final class DbalProductSafetyRepository implements ProductSafetyRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function product(int $id, string $language = '', string $fallback = ''): ?array
    {
        if (!$this->isTranslated($language, $fallback)) {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT
                        p.pid,
                        p.manufacturer,
                        p.safetyWarnings,
                        p.safetyInformation,
                        CASE WHEN t.kind = 'physical' THEN 1 ELSE 0 END AS physical
                    FROM tl_consho_product p
                    LEFT JOIN tl_consho_product_type t ON t.id = p.productType
                    WHERE p.id = :id
                    SQL,
                ['id' => $id],
            );

            return false === $row ? null : $row;
        }

        // Alias `tr` as in ProductQuery and DbalProductTileRepository; `t` is
        // taken by the product type here.
        $join = new TranslationJoin('tl_consho_product', $language, $fallback, 'p', 'tr');

        /*
         * Deliberately not TranslationJoin::coalesce(): safety texts have no
         * fallback (Block M, 4.1). A German warning on a French
         * page is not a cosmetic flaw but a breach — and it looks like a
         * maintained product.
         *
         * The two flags use exactly the COALESCE form of VisibilityCriteria: the
         * page must decide "does this variant have texts of its own" the way
         * visibility decided it, or it shows a warning visibility judged
         * differently.
         */
        $row = $this->connection->fetchAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT
                        p.pid,
                        p.manufacturer,
                        NULLIF(tr.safetyWarnings, '') AS safetyWarnings,
                        NULLIF(tr.safetyInformation, '') AS safetyInformation,
                        CASE WHEN COALESCE(p.safetyWarnings, '') != '' THEN 1 ELSE 0 END AS hasOwnSafetyWarnings,
                        CASE WHEN COALESCE(p.safetyInformation, '') != '' THEN 1 ELSE 0 END AS hasOwnSafetyInformation,
                        CASE WHEN t.kind = 'physical' THEN 1 ELSE 0 END AS physical
                    FROM tl_consho_product p
                    LEFT JOIN tl_consho_product_type t ON t.id = p.productType
                    %s
                    WHERE p.id = :id
                    SQL,
                $join->sql(),
            ),
            ['id' => $id, ...$join->params()],
        );

        return false === $row ? null : $row;
    }

    public function manufacturer(int $id, string $language = '', string $fallback = ''): ?array
    {
        // The name falls back like any title (TranslatableFields); the address
        // is not translatable, and the country is named by ProductSafetyResolver.
        $join = new TranslationJoin('tl_consho_manufacturer', $language, $fallback, 'm', 'tm');

        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT %s AS title, m.street, m.postal, m.city, m.state, m.country, m.email, m.contactUrl, m.euResponsible
                 FROM tl_consho_manufacturer m %s WHERE m.id = :id',
                $join->coalesce('title'),
                $join->sql(),
            ),
            ['id' => $id, ...$join->params()],
        );

        return false === $row ? null : $row;
    }

    /**
     * The same test TranslationJoin makes privately and VisibilityCriteria
     * repeats — repeated here as well, because the select list and not only the
     * join depends on it.
     */
    private function isTranslated(string $language, string $fallback): bool
    {
        return '' !== $language && $language !== $fallback;
    }
}
