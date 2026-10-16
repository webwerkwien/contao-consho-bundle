<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPoolHandler;

/**
 * Keeps tl_consho_stock in step with the articles of a product (Block G, G1,
 * G10).
 *
 * The ARTICLES of a product are what goes into a basket: its variants, or the
 * product itself when it has none (spec A 3.7). Each gets a row when its stock
 * is limited:
 *
 *  - `manual`: a physical good whose main product keeps stock (G1);
 *  - `keys`: a digital article delivered from its key pool (G10) — its own
 *    delivery, or its main product's where it has none (DigitalProductSettings).
 *    The row carries no figure, the free codes are the stock; it is there to
 *    be locked at the purchase click and to be listed.
 *
 * A row nobody needs any more — stock switched off, kind or delivery changed,
 * article deleted, variants added to a product that used to be sold itself —
 * stays with `active = 0`. Never deleted: its movements would lose their
 * article, and an undo of a deletion would come back to a stock of zero.
 *
 * Run from the product mask (StockFieldsListener) after every save, copy,
 * undo and restore — idempotent, so running it once too often costs a query.
 */
class StockRows
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param int $productId a main product or one of its variants
     */
    public function sync(int $productId, int $now): void
    {
        $main = $this->mainOf($productId);

        if (null === $main) {
            return;
        }

        $mainId = (int) $main['id'];
        $kind = ProductKind::stored($main['kind']);
        $variants = $this->connection->fetchAllKeyValue('SELECT id, digitalDelivery FROM tl_consho_product WHERE pid = ? ORDER BY id', [$mainId]);
        $articles = [] === $variants ? [$mainId => ''] : $variants;
        $keep = [];

        foreach ($articles as $article => $ownDelivery) {
            $delivery = '' !== (string) $ownDelivery ? (string) $ownDelivery : $main['digitalDelivery'];

            $source = match (true) {
                $kind->isPhysical() && $main['stockTracked'] => 'manual',
                ProductKind::Digital === $kind && KeyPoolHandler::getType() === $delivery => 'keys',
                default => null,
            };

            if (null !== $source) {
                $this->activate((int) $article, $mainId, $source, $now);
                $keep[] = (int) $article;
            }
        }

        // The supplier of the main product, on every row of it (E5: the
        // Lagerstand page filters by it).
        $this->connection->executeStatement('UPDATE tl_consho_stock SET supplier = ? WHERE main = ?', [$main['supplier'], $mainId]);

        // Everything of this product that is not a limited article now.
        $this->connection->executeStatement(
            'UPDATE tl_consho_stock SET active = 0, tstamp = ? WHERE main = ? AND active = 1'.([] === $keep ? '' : ' AND article NOT IN (?)'),
            [] === $keep ? [$now, $mainId] : [$now, $mainId, $keep],
            [] === $keep ? [] : [2 => ArrayParameterType::INTEGER],
        );
    }

    /**
     * Before a product or variant is deleted: its rows go inactive. For a
     * main product, those of its variants too — Contao deletes them with it.
     */
    public function deactivate(int $productId, int $now): void
    {
        $this->connection->executeStatement(
            'UPDATE tl_consho_stock SET active = 0, tstamp = ? WHERE active = 1 AND (article = ? OR main = ?)',
            [$now, $productId, $productId],
        );
    }

    /**
     * The row of an article, if it keeps stock by hand — the figure the mask
     * shows and books. A key pool's row is not one: its stock are its codes.
     *
     * @return array{id: int, quantity: string, availableFrom: int|null}|null
     */
    public function activeRow(int $article): array|null
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, quantity, availableFrom FROM tl_consho_stock WHERE article = ? AND active = 1 AND source = 'manual'",
            [$article],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'quantity' => (string) $row['quantity'],
            'availableFrom' => null === $row['availableFrom'] || '' === (string) $row['availableFrom'] ? null : (int) $row['availableFrom'],
        ];
    }

    /**
     * @return array{id: int|string, stockTracked: bool, kind: string|null, digitalDelivery: string, supplier: string}|null
     */
    private function mainOf(int $productId): array|null
    {
        $pid = $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$productId]);

        if (false === $pid) {
            return null;
        }

        $mainId = (int) $pid > 0 ? (int) $pid : $productId;

        // The type of the main product decides the kind, no type is physical
        // (the rule of DbalArticleShippingData).
        $row = $this->connection->fetchAssociative(
            'SELECT p.id, p.stockTracked, p.digitalDelivery, p.supplier, t.kind
               FROM tl_consho_product p
               LEFT JOIN tl_consho_product_type t ON t.id = p.productType
              WHERE p.id = ?',
            [$mainId],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => $row['id'],
            'stockTracked' => (bool) $row['stockTracked'],
            'kind' => null === $row['kind'] ? null : (string) $row['kind'],
            'digitalDelivery' => (string) $row['digitalDelivery'],
            'supplier' => (string) $row['supplier'],
        ];
    }

    private function activate(int $article, int $mainId, string $source, int $now): void
    {
        // Not the UPDATE's row count: MySQL reports 0 for a row it found but
        // did not change, and a second save within the same second would
        // then try to insert the article twice.
        if (false !== $this->connection->fetchOne('SELECT id FROM tl_consho_stock WHERE article = ?', [$article])) {
            $this->connection->executeStatement(
                'UPDATE tl_consho_stock SET active = 1, main = ?, source = ?, tstamp = ? WHERE article = ?',
                [$mainId, $source, $now, $article],
            );
        } else {
            $this->connection->insert('tl_consho_stock', [
                'article' => $article,
                'main' => $mainId,
                'source' => $source,
                'quantity' => '0.0000',
                'active' => 1,
                'tstamp' => $now,
            ]);
        }
    }
}
