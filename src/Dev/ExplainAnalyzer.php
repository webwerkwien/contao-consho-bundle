<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dev;

/**
 * Reads MariaDB/MySQL EXPLAIN rows for full table scans (plan B-9).
 *
 * A fast query for the wrong reason is no finding: at 5,000 products a scan is
 * cheap and invisible in the timing, at 100,000 it is the first thing to break.
 */
final class ExplainAnalyzer
{
    /**
     * @param list<array<string, mixed>> $rows EXPLAIN output, one row per table access
     *
     * @return list<string> "table (≈rows)" for every access of type ALL
     */
    public static function fullScans(array $rows): array
    {
        return array_map(
            static fn (array $row): string => \sprintf('%s (≈%d rows)', (string) ($row['table'] ?? '?'), (int) ($row['rows'] ?? 0)),
            self::scanRows($rows),
        );
    }

    /**
     * Full scans that grow with the catalogue — what the visibility projection
     * is meant to remove from every listing query (B-10, section 5.7).
     *
     * EXPLAIN names a table by its alias (p, child, parent, m …), so "a scan of
     * tl_consho_product" cannot be told apart reliably by name. What matters is
     * the size instead: a scan estimated at half the main products or more reads
     * the catalogue; a scan of twelve property groups does not.
     *
     * @param list<array<string, mixed>> $rows         EXPLAIN output
     * @param int                        $mainProducts main products in the measured catalogue; 0 = unknown, nothing reported
     *
     * @return list<string> "table (≈rows)"
     */
    public static function catalogueScans(array $rows, int $mainProducts): array
    {
        if ($mainProducts <= 0) {
            return [];
        }

        $threshold = max(1, intdiv($mainProducts, 2));

        return array_map(
            static fn (array $row): string => \sprintf('%s (≈%d rows)', (string) ($row['table'] ?? '?'), (int) ($row['rows'] ?? 0)),
            array_values(array_filter(self::scanRows($rows), static fn (array $row): bool => (int) ($row['rows'] ?? 0) >= $threshold)),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>> the accesses of type ALL, keys lower-cased
     */
    private static function scanRows(array $rows): array
    {
        $scans = [];

        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);

            if ('ALL' === strtoupper((string) ($row['type'] ?? ''))) {
                $scans[] = $row;
            }
        }

        return $scans;
    }
}
