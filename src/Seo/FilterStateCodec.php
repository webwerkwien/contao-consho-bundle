<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * Encodes filter and sort state into ONE query parameter.
 *
 * One parameter rather than one per filter, so a single robots.txt line covers
 * the whole combinatorics — for ever, and without anyone maintaining rules as
 * new filters appear.
 *
 * The encoding is sorted, always. Two clicks in a different order have to
 * produce the same URL, or the same page exists twice and competes with
 * itself.
 */
final class FilterStateCodec
{
    public const PARAMETER = 'cq';

    /**
     * Aliases as Contao writes them: lowercase letters, digits, hyphens.
     * Everything else is rejected before it can reach a query.
     *
     * Public, because whoever offers a filter link has to leave out what this
     * pattern rejects (FilterOptionRepository) — a second copy would drift.
     */
    public const ALIAS = '/^[a-z0-9\-]+$/';

    /**
     * Sort keys are ours, not the editor's, and read "price_asc".
     */
    private const SORT = '/^[a-z0-9_\-]+$/';

    /**
     * With an underscore, which ALIAS rejects: no property group can ever be
     * called this. It was `sort` until 2026-10-09 — and a group with the alias
     * "sort" was read as the sort order and could not be filtered (review
     * Verfügbarkeit, open point; Michael: "ja" to renaming it). Consho was not
     * live anywhere; an old `sort:` link keeps its page and loses only its order.
     */
    private const SORT_KEY = 'sort_by';

    /**
     * "Nur verfügbare" (block Verfügbarkeit, V5). With an underscore for the
     * same reason as SORT_KEY.
     */
    private const AVAILABLE_KEY = 'only_available';

    public function encode(FilterState $state): ?string
    {
        $filters = array_filter($state->filters, static fn (array $options): bool => [] !== $options);
        ksort($filters);

        $parts = [];

        foreach ($filters as $group => $options) {
            sort($options);
            $parts[] = $group.':'.implode(',', $options);
        }

        if ($state->availableOnly) {
            $parts[] = self::AVAILABLE_KEY.':1';
        }

        if ('' !== $state->sort) {
            $parts[] = self::SORT_KEY.':'.$state->sort;
        }

        return [] === $parts ? null : implode(';', $parts);
    }

    public function decode(?string $raw): FilterState
    {
        if (null === $raw || '' === trim($raw)) {
            return new FilterState();
        }

        $filters = [];
        $sort = '';
        $availableOnly = false;

        foreach (explode(';', $raw) as $part) {
            $colon = strpos($part, ':');

            if (false === $colon) {
                continue;
            }

            $key = substr($part, 0, $colon);
            $value = substr($part, $colon + 1);

            // Only "1": any other value is not what a link of ours writes.
            if (self::AVAILABLE_KEY === $key) {
                $availableOnly = '1' === $value;

                continue;
            }

            if (self::SORT_KEY === $key) {
                if (preg_match(self::SORT, $value)) {
                    $sort = $value;
                }

                continue;
            }

            if (!preg_match(self::ALIAS, $key)) {
                continue;
            }

            $options = array_values(array_filter(
                explode(',', $value),
                static fn (string $option): bool => 1 === preg_match(self::ALIAS, $option),
            ));

            if ([] !== $options) {
                sort($options);
                $filters[$key] = $options;
            }
        }

        ksort($filters);

        return new FilterState($filters, $sort, $availableOnly);
    }
}
