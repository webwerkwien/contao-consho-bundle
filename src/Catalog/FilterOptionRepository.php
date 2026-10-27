<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * The groups and options a filter offers, labelled in the page's language.
 *
 * Only the LABEL is translated. The alias stays the one on the record, because
 * it is what `cq` carries, and `cq` is language neutral by design
 * (Block M, 6.1). FilterResolver therefore stays untouched.
 *
 * ── What is left out ──────────────────────────────────────────────────────
 *
 * A group that is not `filterable`: FilterResolver::resolve() drops it from
 * the address, so a link to it would silently lead back to the unfiltered
 * listing. A group or option without an alias: it cannot stand in `cq` at all.
 * Both apply to an explicit selection too — the element may still name a group
 * the editor has since made unfilterable.
 *
 * A group or option whose alias FilterStateCodec does not accept, for the same
 * reason as a missing one. Contao's alias check lets through capitals, umlauts,
 * dots and underscores; the codec drops them silently on reading, so the link
 * would lead to another state than the one it shows. Checked in PHP rather than
 * in SQL: SQLite has no REGEXP, and the pattern lives in the codec.
 */
final class FilterOptionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<int> $groupIds empty = every filterable group, ordered by label;
     *                            otherwise these groups, in this order
     *
     * @return list<FilterGroupView>
     */
    public function forGroups(array $groupIds, string $language = '', string $fallback = ''): array
    {
        // Join aliases tg/tn: `to` would be a reserved word in MySQL. Both
        // joins read tl_consho_translation — the ptable parameter of each keeps
        // a group's translation off the option with the same id.
        $groupLabel = new TranslationJoin('tl_consho_property_group', $language, $fallback, 'g', 'tg');
        $optionLabel = new TranslationJoin('tl_consho_property_option', $language, $fallback, 'o', 'tn');

        $params = [...$groupLabel->params(), ...$optionLabel->params()];
        $types = [];
        $selection = '';

        if ([] !== $groupIds) {
            $groupIds = array_values(array_unique($groupIds));
            $selection = 'AND g.id IN (:groupIds)';
            $params['groupIds'] = $groupIds;
            $types['groupIds'] = ArrayParameterType::INTEGER;
        }

        // The alias columns are NOT NULL DEFAULT '' (see the DCAs), so an
        // unfinished record shows up as '' — never as NULL.
        //
        // In the default language both joins are '' and both labels are the
        // plain columns: a monolingual shop runs exactly this query without
        // tl_consho_translation.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                "SELECT g.id AS groupId, g.alias AS groupAlias, %s AS groupLabel,
                        o.id AS optionId, o.alias AS optionAlias, %s AS optionLabel
                 FROM tl_consho_property_group g
                 JOIN tl_consho_property_option o ON o.pid = g.id
                 %s
                 %s
                 WHERE g.filterable = 1 AND g.alias != '' AND o.alias != '' %s
                 ORDER BY groupLabel, g.id, o.sorting, o.id",
                $groupLabel->coalesce('title'),
                $optionLabel->coalesce('title'),
                $groupLabel->sql(),
                $optionLabel->sql(),
                $selection,
            ),
            $params,
            $types,
        );

        /** @var array<int, array{alias: string, label: string, options: list<FilterOptionView>}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            if (!self::carriable((string) $row['groupAlias']) || !self::carriable((string) $row['optionAlias'])) {
                continue;
            }

            $groupId = (int) $row['groupId'];

            $groups[$groupId] ??= [
                'alias' => (string) $row['groupAlias'],
                'label' => (string) $row['groupLabel'],
                'options' => [],
            ];

            $groups[$groupId]['options'][] = new FilterOptionView(
                (int) $row['optionId'],
                (string) $row['optionAlias'],
                (string) $row['optionLabel'],
            );
        }

        // An explicit selection is the editor's order, not the alphabet's.
        $order = [] === $groupIds ? array_keys($groups) : $groupIds;
        $views = [];

        foreach ($order as $groupId) {
            if (!isset($groups[$groupId])) {
                continue;
            }

            $group = $groups[$groupId];
            $views[] = new FilterGroupView($groupId, $group['alias'], $group['label'], $group['options']);
        }

        return $views;
    }

    /**
     * Whether an alias survives FilterStateCodec::decode().
     */
    private static function carriable(string $alias): bool
    {
        return 1 === preg_match(FilterStateCodec::ALIAS, $alias);
    }
}
