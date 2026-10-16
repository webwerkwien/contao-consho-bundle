<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * The variants of one product and their axes, for the product page.
 *
 * Not VariantResolver::findVariants(): that one asks without any visibility
 * and is meant for the back end. Here every variant goes through
 * VisibilityCriteria::variant() — the same condition the listing, the sitemap
 * and the JSON-LD use — so an unpublished variant cannot become choosable on
 * the one page where it would be bought.
 *
 * ── Axes are not FilterOptionRepository ──────────────────────────────────
 *
 * That class offers every option of a group, and only of filterable groups with
 * an alias the address can carry. An axis is different: only the options this
 * product's variants actually use, from any group (a "supplier group" is never
 * filterable and can still tell two variants apart), and without an alias —
 * the choice travels as `?variant=<id>`. What is borrowed is the label rule:
 * the join aliases `tg`/`tn`, groups by label, options by `sorting`, `id`.
 *
 * Axes are read from ALL variants of the product, visible or not. An option
 * only an unpublished variant has is still shown, without a link (VariantMatrix
 * rule 3) — so that the row of options does not change shape when an editor
 * switches one variant off.
 */
final class VariantRepository
{
    private const PRODUCT = 'consho_variant_product';

    public function __construct(
        private readonly Connection $connection,
        private readonly VisibilityCriteria $criteria,
    ) {
    }

    /**
     * The choice on a product page: visible variants and the axes they span.
     */
    public function matrixFor(int $productId, int $shopId, int $now, string $language = '', string $fallback = ''): VariantMatrix
    {
        $variants = $this->visibleVariants($productId, $shopId, $now, $language, $fallback);

        // Nothing to choose from: no reason to ask for labels.
        return new VariantMatrix($variants, [] === $variants ? [] : $this->axes($productId, $language, $fallback));
    }

    /**
     * @return list<VariantRow> in display order: `sorting`, then `id`
     */
    public function visibleVariants(int $productId, int $shopId, int $now, string $language = '', string $fallback = ''): array
    {
        $visible = $this->criteria->variant($shopId, $now, 'v', $language, $fallback);

        // LEFT JOINs: a published variant without options is still a variant.
        // No axis leads to it, but `?variant=<id>` does.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT v.id AS variantId, o.pid AS groupId, o.id AS optionId
                 FROM tl_consho_product v
                 LEFT JOIN tl_consho_product_option po ON po.pid = v.id
                 LEFT JOIN tl_consho_property_option o ON o.id = po.optionId
                 WHERE v.pid = :%s AND %s
                 ORDER BY v.sorting, v.id, o.sorting, o.id',
                self::PRODUCT,
                $visible->sql,
            ),
            [...$visible->params, self::PRODUCT => $productId],
        );

        /** @var array<int, array<int, int>> $options */
        $options = [];

        foreach ($rows as $row) {
            $variantId = (int) $row['variantId'];
            $options[$variantId] ??= [];

            // No option row, or one pointing at a deleted option.
            if (null === $row['groupId']) {
                continue;
            }

            // UNIQUE (pid, optionId) does not stop two options of ONE group on
            // a variant. The first by `sorting`, `id` wins, so the answer does
            // not depend on what the engine returns first.
            $options[$variantId][(int) $row['groupId']] ??= (int) $row['optionId'];
        }

        $variants = [];

        foreach ($options as $variantId => $variantOptions) {
            $variants[] = new VariantRow($variantId, $variantOptions);
        }

        return $variants;
    }

    /**
     * @return array<int, array{label: string, options: array<int, string>}> groupId => label and
     *                                                                        optionId => label; groups
     *                                                                        by label, options by
     *                                                                        `sorting`, then `id`
     */
    public function axes(int $productId, string $language = '', string $fallback = ''): array
    {
        // Join aliases tg/tn as in FilterOptionRepository: `to` would be a
        // reserved word in MySQL. In the default language both joins are ''
        // and the labels are the plain columns.
        $groupLabel = new TranslationJoin('tl_consho_property_group', $language, $fallback, 'g', 'tg');
        $optionLabel = new TranslationJoin('tl_consho_property_option', $language, $fallback, 'o', 'tn');

        // No DISTINCT: MySQL would then insist on o.sorting in the select list.
        // An option several variants share is folded below.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT g.id AS groupId, %s AS groupLabel, o.id AS optionId, %s AS optionLabel
                 FROM tl_consho_product v
                 JOIN tl_consho_product_option po ON po.pid = v.id
                 JOIN tl_consho_property_option o ON o.id = po.optionId
                 JOIN tl_consho_property_group g ON g.id = o.pid
                 %s
                 %s
                 WHERE v.pid = :%s
                 ORDER BY groupLabel, g.id, o.sorting, o.id',
                $groupLabel->coalesce('title'),
                $optionLabel->coalesce('title'),
                $groupLabel->sql(),
                $optionLabel->sql(),
                self::PRODUCT,
            ),
            [...$groupLabel->params(), ...$optionLabel->params(), self::PRODUCT => $productId],
        );

        /** @var array<int, array{label: string, options: array<int, string>}> $axes */
        $axes = [];

        foreach ($rows as $row) {
            $groupId = (int) $row['groupId'];

            $axes[$groupId] ??= ['label' => (string) $row['groupLabel'], 'options' => []];
            $axes[$groupId]['options'][(int) $row['optionId']] ??= (string) $row['optionLabel'];
        }

        return $axes;
    }
}
