<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\Controller;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Deleting a main product deletes its variants — with their prices, translations and join rows, into the SAME undo
 * entry, so that restoring brings the product back whole.
 *
 * Before, the variants stayed behind with a pid pointing nowhere and showed in
 * the list like products of their own (until CW-3).
 *
 * Why a callback and not the ctable: with this table in its own ctable,
 * DC_Table::reviseTable() deletes every row whose pid matches no parent — for
 * this table, every main product. See tl_consho_product.
 *
 * DC_Table writes tl_undo BEFORE the ondelete callbacks and passes its id
 * (DC_Table::delete()); this adds the variant rows to it. Under the key
 * tl_consho_product they follow the main product, and undo() restores table by
 * table — products before their prices — calling onundo for every row.
 *
 * Runs after the other ondelete callbacks (priority): KeyPoolListener and
 * StockFieldsListener look the variants up by their main product and must
 * still find them.
 */
class VariantDeleteListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.ondelete', priority: -16)]
    public function onDelete(DataContainer $dc, int|string $undoId): void
    {
        $variants = array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_product WHERE pid = ? ORDER BY id',
            [(int) $dc->id],
        ));

        if ([] === $variants) {
            return;
        }

        /** @var array<string, list<int>> $delete */
        $delete = ['tl_consho_product' => $variants];

        foreach ($variants as $variant) {
            $this->collectChildren('tl_consho_product', $variant, $delete);
        }

        $this->connection->transactional(function () use ($delete, $undoId): void {
            $undo = $this->connection->fetchAssociative('SELECT data, affectedRows FROM tl_undo WHERE id = ?', [(int) $undoId]);

            if (false === $undo) {
                throw new \RuntimeException(\sprintf('The undo entry %s is missing; the variants were not deleted.', $undoId));
            }

            $data = StringUtil::deserialize($undo['data'], true);
            $rows = 0;

            foreach ($delete as $table => $ids) {
                foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT * FROM %s WHERE id IN (?) ORDER BY id', $table), [$ids], [ArrayParameterType::INTEGER]) as $row) {
                    $data[$table][] = $row;
                    ++$rows;
                }
            }

            $this->connection->update('tl_undo', ['data' => serialize($data), 'affectedRows' => (int) $undo['affectedRows'] + $rows], ['id' => (int) $undoId]);

            // Children first, the variants last — as DC_Table deletes.
            foreach (array_reverse($delete, true) as $table => $ids) {
                $this->connection->executeStatement(\sprintf('DELETE FROM %s WHERE id IN (?)', $table), [$ids], [ArrayParameterType::INTEGER]);
            }
        });
    }

    /**
     * The rows hanging off a record through the ctable, recursively — the
     * rule of DC_Table::deleteChildren(), dynamicPtable and
     * doNotDeleteRecords included. The table itself is skipped: a variant
     * has no variants.
     *
     * @param array<string, list<int>>     $delete
     * @param-out array<string, list<int>> $delete
     */
    private function collectChildren(string $table, int $id, array &$delete): void
    {
        foreach ($GLOBALS['TL_DCA'][$table]['config']['ctable'] ?? [] as $child) {
            $child = (string) $child;

            if ($child === $table) {
                continue;
            }

            // Loaded already where the delete came from DC_Table (its own
            // deleteChildren() loads every ctable before ondelete runs).
            if (!isset($GLOBALS['TL_DCA'][$child])) {
                Controller::loadDataContainer($child);
            }

            $config = $GLOBALS['TL_DCA'][$child]['config'] ?? [];

            if ($config['doNotDeleteRecords'] ?? false) {
                continue;
            }

            $ids = ($config['dynamicPtable'] ?? false)
                ? $this->connection->fetchFirstColumn(\sprintf('SELECT id FROM %s WHERE pid = ? AND ptable = ? ORDER BY id', $child), [$id, $table])
                : $this->connection->fetchFirstColumn(\sprintf('SELECT id FROM %s WHERE pid = ? ORDER BY id', $child), [$id]);

            foreach ($ids as $childId) {
                $delete[$child][] = (int) $childId;
                $this->collectChildren($child, (int) $childId, $delete);
            }
        }
    }
}
