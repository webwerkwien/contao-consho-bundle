<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Doctrine\DBAL\Connection;

/**
 * Both lookups run against a unique index — pid,filterState and pid,alias — so
 * each is one key read per request, not a scan.
 */
final class DbalFacetPageRepository implements FacetPageRepositoryInterface
{
    /*
     * An unfinished record is no landing page. Contao inserts an empty row when
     * a record is created, and the editor may save the combination before the
     * alias or the other way round. Without a combination the page would show
     * the whole category under its own title; without an alias it has no path
     * segment to be linked by.
     */
    private const COMPLETE = "filterState IS NOT NULL AND filterState != '' AND alias IS NOT NULL AND alias != ''";

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByState(int $pageId, string $filterState): ?FacetPageRecord
    {
        return $this->findOne('filterState = :value', $pageId, $filterState);
    }

    public function findByAlias(int $pageId, string $alias): ?FacetPageRecord
    {
        return $this->findOne('alias = :value', $pageId, $alias);
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, pid, alias, filterState, pageTitle, description
               FROM tl_consho_facet_page
              WHERE '.self::COMPLETE.'
              ORDER BY pid, alias',
        );

        return array_map(self::record(...), $rows);
    }

    /**
     * @param string $condition a literal from this class, never user input
     */
    private function findOne(string $condition, int $pageId, string $value): ?FacetPageRecord
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, pid, alias, filterState, pageTitle, description
               FROM tl_consho_facet_page
              WHERE pid = :page AND $condition
                AND ".self::COMPLETE,
            ['page' => $pageId, 'value' => $value],
        );

        return false === $row ? null : self::record($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function record(array $row): FacetPageRecord
    {
        return new FacetPageRecord(
            (int) $row['id'],
            (int) $row['pid'],
            (string) $row['alias'],
            (string) $row['filterState'],
            (string) $row['pageTitle'],
            (string) ($row['description'] ?? ''),
        );
    }
}
