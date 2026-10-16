<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;

/**
 * Turns the aliases in a URL into the ids a query can use.
 *
 * `FilterState` carries what the address says — "colour:red;volume:250ml" —
 * because an address has to survive a renamed option and be readable by a
 * person. `ProductQuery` wants ids, because that is what the tables hold. This
 * is the one place the two meet.
 *
 * ── Unknown aliases are dropped, not refused ──────────────────────────────
 *
 * The state comes out of a URL, where anybody can type anything, and where a
 * link from last year may name an option that has since been deleted. Refusing
 * the whole request would turn a stale bookmark into an error page; dropping
 * the part that no longer exists shows the visitor a listing that is one filter
 * wider than they asked for.
 *
 * > 🎯 The empty combination is handled one level up: a filter that yields no
 * > products answers 404 (B-4). That is a different case — there the filter was
 * > understood and simply matches nothing.
 *
 * ── Only groups the editor marked filterable ──────────────────────────────
 *
 * `filterable` exists on the group since block A. Without checking it here, a
 * hand-written URL could filter by "internal marking" or "supplier group" —
 * fields an operator never meant to expose.
 */
final class FilterResolver
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Groups a bare list of option ids — what the editor picked in the element.
     *
     * Unlike resolve() this does NOT check `filterable`: a preset is the
     * editor's own choice on their own page, not something a visitor typed.
     * A shop may well build a category on a dimension it does not offer as a
     * filter — "Aktion" is the obvious one.
     *
     * @param list<int> $optionIds
     *
     * @return array<int, list<int>> property group id => option ids
     */
    public function group(array $optionIds): array
    {
        if ([] === $optionIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, pid FROM tl_consho_property_option WHERE id IN (:ids)',
            ['ids' => $optionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['pid']][] = (int) $row['id'];
        }

        return $grouped;
    }

    /**
     * The editor's preset narrowed by what the visitor chose.
     *
     * Where both name the same group, the visitor's choice narrows **within**
     * the preset rather than replacing it: the preset is the category, not a
     * pre-ticked filter, and a visitor must not be able to click their way out
     * of it. An empty intersection is a legitimate answer — the visitor asked
     * for something this category does not contain, and B-4 turns that into a
     * 404 rather than a page with nothing on it.
     *
     * ⚠️ Returns **null**, not an empty group, when an intersection comes out
     * empty. `ProductQuery` reads an empty group as "this group is not
     * filtered" — the right reading for a group nobody touched, and exactly the
     * wrong one here. Silently turning "matches nothing" into "no filter" would
     * show the visitor the whole category instead of an empty result.
     *
     * @param array<int, list<int>> $preset
     * @param array<int, list<int>> $chosen
     *
     * @return array<int, list<int>>|null null when the combination cannot contain anything
     */
    public function narrow(array $preset, array $chosen): array|null
    {
        foreach ($chosen as $groupId => $optionIds) {
            if (!isset($preset[$groupId])) {
                $preset[$groupId] = $optionIds;

                continue;
            }

            $intersection = array_values(array_intersect($preset[$groupId], $optionIds));

            if ([] === $intersection) {
                return null;
            }

            $preset[$groupId] = $intersection;
        }

        return $preset;
    }

    /**
     * @return array<int, list<int>> property group id => option ids
     */
    public function resolve(FilterState $state): array
    {
        if ([] === $state->filters) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT g.id AS groupId, g.alias AS groupAlias, o.id AS optionId, o.alias AS optionAlias
                FROM tl_consho_property_option o
                JOIN tl_consho_property_group g ON g.id = o.pid
                WHERE g.alias IN (:groups) AND g.filterable = 1
                SQL,
            ['groups' => array_keys($state->filters)],
            ['groups' => ArrayParameterType::STRING],
        );

        $resolved = [];

        foreach ($rows as $row) {
            $wanted = $state->filters[(string) $row['groupAlias']] ?? [];

            if (!\in_array((string) $row['optionAlias'], $wanted, true)) {
                continue;
            }

            $resolved[(int) $row['groupId']][] = (int) $row['optionId'];
        }

        return $resolved;
    }
}
