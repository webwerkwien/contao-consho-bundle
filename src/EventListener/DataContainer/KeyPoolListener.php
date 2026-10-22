<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPool;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPoolHandler;

/**
 * The key pool of an article (F-6, F19).
 *
 * "Schlüssel einfügen" is a field without a column, like `shops`: pasted
 * codes, one per line, go into tl_consho_key (KeyPool, the same rule as a
 * command) and the field stays empty. Its label names how many are left.
 *
 * A code that was handed out is part of a delivery and cannot be deleted;
 * one still in the pool can.
 */
class KeyPoolListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly KeyPool $pool,
    ) {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.keyImport.save')]
    public function import(mixed $value, DataContainer $dc): null
    {
        $productId = (int) $dc->id;

        if ($productId < 1 || !\is_string($value)) {
            return null;
        }

        $this->pool->add($productId, $value, time());

        return null;
    }

    /**
     * How many codes are still in the pool — in the field's label, where the
     * operator pastes more. On load of the mask, because the widget reads
     * its label before any field callback runs; the label itself comes from
     * the language array, not from the DCA.
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onload')]
    public function label(DataContainer|null $dc = null): void
    {
        $productId = null === $dc ? 0 : (int) $dc->id;
        $label = $GLOBALS['TL_LANG']['tl_consho_product']['keyImport'] ?? null;

        if ($productId < 1 || !\is_array($label) || !isset($label[0])) {
            return;
        }

        $free = $this->pool->free($productId);
        $GLOBALS['TL_LANG']['tl_consho_product']['keyImport'][0] = \sprintf('%s (%s)', $label[0], \sprintf($GLOBALS['TL_LANG']['tl_consho_product']['keysFree'] ?? '%d free', $free));
    }

    #[AsCallback(table: 'tl_consho_product', target: 'list.operations.keys.button')]
    public function keysButton(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord();
        $delivery = (string) ($record['digitalDelivery'] ?? '');

        if ('' === $delivery && (int) ($record['pid'] ?? 0) > 0) {
            $delivery = (string) $this->connection->fetchOne('SELECT digitalDelivery FROM tl_consho_product WHERE id = ?', [(int) $record['pid']]);
        }

        if (KeyPoolHandler::getType() !== $delivery) {
            $operation->hide();
        }
    }

    /**
     * The pool goes with its article — only the codes still in it. Handed
     * out ones belong to a delivery and stay. Not a ctable of the product,
     * see tl_consho_product.
     *
     * The pools of its variants too: they are deleted with a main product
     * (VariantDeleteListener, which runs after this and lets it still find
     * them).
     *
     * Into the article's undo entry, as VariantDeleteListener does (AiContract
     * review, 2026-10-22): the codes were bought from a supplier, and until
     * then deleting the article — in the mask or with consho:product:delete —
     * lost them for good, while "Wiederherstellen" brought the article back
     * with an empty pool.
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.ondelete')]
    public function deletePool(DataContainer $dc, int|string $undoId): void
    {
        $id = (int) $dc->id;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_consho_key WHERE entitlement = 0 AND (pid = ? OR pid IN (SELECT id FROM tl_consho_product WHERE pid = ?)) ORDER BY id',
            [$id, $id],
        );

        if ([] === $rows) {
            return;
        }

        $this->connection->transactional(function () use ($rows, $undoId): void {
            $undo = $this->connection->fetchAssociative('SELECT data, affectedRows FROM tl_undo WHERE id = ?', [(int) $undoId]);

            if (false === $undo) {
                throw new \RuntimeException(\sprintf('The undo entry %s is missing; the key pool was not deleted.', $undoId));
            }

            $data = StringUtil::deserialize($undo['data'], true);
            $data['tl_consho_key'] = [...($data['tl_consho_key'] ?? []), ...$rows];

            $this->connection->update('tl_undo', ['data' => serialize($data), 'affectedRows' => (int) $undo['affectedRows'] + \count($rows)], ['id' => (int) $undoId]);
            // Exactly the rows in the undo entry; `entitlement = 0` again in
            // case one was handed out in the meantime.
            $this->connection->executeStatement(
                'DELETE FROM tl_consho_key WHERE entitlement = 0 AND id IN (?)',
                [array_map(static fn (array $row): int => (int) $row['id'], $rows)],
                [ArrayParameterType::INTEGER],
            );
        });
    }

    #[AsCallback(table: 'tl_consho_key', target: 'config.ondelete')]
    public function refuseAssigned(DataContainer $dc): void
    {
        if ((int) $this->connection->fetchOne('SELECT entitlement FROM tl_consho_key WHERE id = ?', [(int) $dc->id]) > 0) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['tl_consho_key']['assigned'] ?? 'This code was handed out and cannot be deleted.');
        }
    }
}
