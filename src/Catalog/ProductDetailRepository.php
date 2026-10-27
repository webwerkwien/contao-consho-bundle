<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * Reads what the product detail page shows, one article row at a time.
 *
 * It reads and decides nothing:
 *
 *  - no visibility — the product page resolved the product through
 *    VisibilityCriteria::mainProduct(), and a variant arrives through the
 *    variant matrix, which applies VisibilityCriteria::variant();
 *  - no inheritance — that is ProductDetailAssembler;
 *  - no safety texts — they must not fall back to the default language and are
 *    ProductSafetyResolver's (Block M, 4).
 *
 * Texts and labels fall back to the default language through TranslationJoin.
 * In the default language no join is built at all.
 */
final class ProductDetailRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function row(int $id, string $language = '', string $fallback = ''): ?ProductDetailRow
    {
        // Join aliases as in ProductAddressLookup, which reads the same two
        // tables: tp for the product, tt for its type. Both read
        // tl_consho_translation — the ptable parameter keeps a product's
        // translation off the type with the same id.
        $productTranslation = new TranslationJoin('tl_consho_product', $language, $fallback, 'p', 'tp');
        $typeTranslation = new TranslationJoin('tl_consho_product_type', $language, $fallback, 't', 'tt');

        $row = $this->connection->fetchAssociative(
            \sprintf(
                "SELECT p.id, p.pid, %s AS title, %s AS teaser, %s AS metaDescription, %s AS description,
                        p.sku, p.gtin, p.mpn, p.images,
                        p.unit, p.purchaseUnit, p.referenceUnit, p.minPurchase, p.purchaseSteps,
                        p.minimumAge, p.manufacturer, p.productType,
                        COALESCE(%s, '') AS productTypeTitle,
                        COALESCE(t.detailPartial, '') AS detailPartial
                 FROM tl_consho_product p
                 LEFT JOIN tl_consho_product_type t ON t.id = p.productType
                 %s
                 %s
                 WHERE p.id = :id",
                $productTranslation->coalesce('title'),
                $productTranslation->coalesce('teaser'),
                $productTranslation->coalesce('metaDescription'),
                $productTranslation->coalesce('description'),
                $typeTranslation->coalesce('title'),
                $productTranslation->sql(),
                $typeTranslation->sql(),
            ),
            ['id' => $id, ...$productTranslation->params(), ...$typeTranslation->params()],
        );

        if (false === $row) {
            return null;
        }

        return new ProductDetailRow(
            id: (int) $row['id'],
            pid: (int) $row['pid'],
            title: (string) $row['title'],
            // teaser, metaDescription and description are nullable text columns.
            teaser: (string) ($row['teaser'] ?? ''),
            metaDescription: (string) ($row['metaDescription'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            sku: (string) $row['sku'],
            gtin: (string) $row['gtin'],
            mpn: (string) $row['mpn'],
            images: $this->uuids((string) ($row['images'] ?? '')),
            unit: (string) $row['unit'],
            purchaseUnit: (string) $row['purchaseUnit'],
            referenceUnit: (string) $row['referenceUnit'],
            minPurchase: (string) $row['minPurchase'],
            purchaseSteps: (string) $row['purchaseSteps'],
            minimumAge: (int) $row['minimumAge'],
            manufacturer: (int) $row['manufacturer'],
            productType: (int) $row['productType'],
            productTypeTitle: (string) $row['productTypeTitle'],
            detailPartial: (string) $row['detailPartial'],
        );
    }

    /**
     * The property groups a product carries, with their options.
     *
     * Not FilterOptionRepository: that one offers every option of the
     * filterable groups. The detail page shows the options of ONE product, and
     * from every group — a "Sorte" nobody filters by is still what the article
     * is. Taken over from it: the join aliases tg/tn and the order.
     *
     * Only tl_consho_product_property. The axis that makes a row a variant
     * (tl_consho_product_option) is not a property of the product.
     *
     * @return list<PropertyView>
     */
    public function properties(int $productId, string $language = '', string $fallback = ''): array
    {
        // `to` would be a reserved word in MySQL, hence tg/tn.
        $groupLabel = new TranslationJoin('tl_consho_property_group', $language, $fallback, 'g', 'tg');
        $optionLabel = new TranslationJoin('tl_consho_property_option', $language, $fallback, 'o', 'tn');

        // Groups have no sorting column; as in the filter, the label the
        // visitor reads decides, then the id.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT g.id AS groupId, %s AS groupLabel, %s AS optionLabel
                 FROM tl_consho_product_property pp
                 JOIN tl_consho_property_option o ON o.id = pp.optionId
                 JOIN tl_consho_property_group g ON g.id = o.pid
                 %s
                 %s
                 WHERE pp.pid = :productId
                 ORDER BY groupLabel, g.id, o.sorting, o.id',
                $groupLabel->coalesce('title'),
                $optionLabel->coalesce('title'),
                $groupLabel->sql(),
                $optionLabel->sql(),
            ),
            ['productId' => $productId, ...$groupLabel->params(), ...$optionLabel->params()],
        );

        /** @var array<int, array{label: string, values: list<string>}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $groupId = (int) $row['groupId'];

            $groups[$groupId] ??= ['label' => (string) $row['groupLabel'], 'values' => []];
            $groups[$groupId]['values'][] = (string) $row['optionLabel'];
        }

        $views = [];

        foreach ($groups as $groupId => $group) {
            $views[] = new PropertyView($groupId, $group['label'], $group['values']);
        }

        return $views;
    }

    /**
     * The `images` field is a serialized, ordered list of binary UUIDs, and the
     * order is a statement about the article (A-12). Anything that is not a
     * binary UUID is dropped rather than turned into a string that looks like
     * one.
     *
     * @return list<string>
     */
    private function uuids(string $serialized): array
    {
        if ('' === $serialized) {
            return [];
        }

        $uuids = [];

        foreach (StringUtil::deserialize($serialized, true) as $uuid) {
            if (\is_string($uuid) && 16 === \strlen($uuid)) {
                $uuids[] = StringUtil::binToUuid($uuid);
            }
        }

        return $uuids;
    }
}
