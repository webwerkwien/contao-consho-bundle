<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Deleting a page takes its facet landing pages along — with undo.
 *
 * tl_consho_facet_page is not a child table of tl_page (see
 * contao/dca/tl_page.php for why), so DC_Table::delete() does not collect it.
 * This listener does the collecting instead, in the same format.
 *
 * Order in DC_Table::delete() (Contao 5.7): collect the page and its subpages
 * plus their child records → write tl_undo → ondelete_callback → delete. So the
 * undo entry exists when this runs, and the pages are still in the database.
 *
 * ⚠️ The page IDs come from the undo entry, not from $dc->id. For a tree table
 * delete() also removes every subpage; $dc->id is only the page that was
 * clicked, and the landing pages of the subpages would be left behind.
 *
 * The entry's data is serialize()d, keyed by table, one row per record.
 * DC_Table::undo() re-inserts every table it finds there, so appending the rows
 * under their own table is all a restore needs. The undo list reads only
 * data[fromTable][0], which stays untouched.
 */
class FacetPageDeleteListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[AsCallback(table: 'tl_page', target: 'config.ondelete')]
    public function __invoke(DataContainer $dc, string $undoId): void
    {
        $undo = $this->connection->fetchAssociative('SELECT data, affectedRows FROM tl_undo WHERE id = ?', [$undoId]);

        if (false === $undo || !\is_string($undo['data'])) {
            return;
        }

        $data = unserialize($undo['data'], ['allowed_classes' => false]);

        if (!\is_array($data) || !\is_array($data['tl_page'] ?? null)) {
            return;
        }

        $pageIds = [];

        foreach ($data['tl_page'] as $page) {
            if (\is_array($page) && isset($page['id'])) {
                $pageIds[] = (int) $page['id'];
            }
        }

        if ([] === $pageIds) {
            return;
        }

        $landingPages = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_consho_facet_page WHERE pid IN (?) ORDER BY id',
            [$pageIds],
            [ArrayParameterType::INTEGER],
        );

        // Nothing to add: the entry stays exactly as the core wrote it.
        if ([] === $landingPages) {
            return;
        }

        foreach ($landingPages as $row) {
            $data['tl_consho_facet_page'][] = $row;
        }

        // affectedRows is what the undo list shows as the number of records.
        $this->connection->update(
            'tl_undo',
            ['data' => serialize($data), 'affectedRows' => (int) $undo['affectedRows'] + \count($landingPages)],
            ['id' => $undoId],
        );

        $this->connection->executeStatement(
            'DELETE FROM tl_consho_facet_page WHERE id IN (?)',
            [array_map(static fn (array $row): int => (int) $row['id'], $landingPages)],
            [ArrayParameterType::INTEGER],
        );
    }
}
