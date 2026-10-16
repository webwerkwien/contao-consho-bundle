<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Which shops a product is assigned to (tl_consho_product_shop).
 *
 * The catalogue is shared and visibility is per shop, so the assignment lives
 * in its own table with a UNIQUE index on (pid, shop). Two callers: the mask
 * (ProductShopsListener), whose options already offer only valid shops, uses
 * assign(); a console command (Connect Stufe 2, CW-3) uses set(), which checks
 * what a form would have prevented.
 */
class ProductShops
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Checks and writes the assignment.
     *
     * @param list<int> $shops
     *
     * @throws ProductShopsRefused
     */
    public function set(int $productId, array $shops): void
    {
        $pid = $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$productId]);

        if (false === $pid) {
            throw new ProductShopsRefused(\sprintf('There is no product %d.', $productId));
        }

        $shops = self::normalised($shops);
        $known = [] === $shops ? [] : array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_shop WHERE id IN (?)',
            [$shops],
            [ArrayParameterType::INTEGER],
        ));

        if ([] !== ($unknown = array_diff($shops, $known))) {
            throw new ProductShopsRefused(\sprintf('There is no shop %s.', implode(', ', $unknown)));
        }

        /*
         * A variant may narrow its main product's shops but never widen them —
         * specification A, 3.7. The mask makes the choice unreachable through
         * its options; here it has to be refused.
         */
        if ((int) $pid > 0 && [] !== ($outside = array_diff($shops, $this->of((int) $pid)))) {
            throw new ProductShopsRefused(\sprintf('Variant %d can only be in the shops of its main product %d, not in %s.', $productId, (int) $pid, implode(', ', $outside)));
        }

        $this->assign($productId, $shops);
    }

    /**
     * Writes the assignment as given, without checks.
     *
     * A diff rather than delete-all-then-insert. Nothing else hangs off these
     * rows today, so wiping them would work — but it would also churn every
     * assignment on every save of an unrelated field, and the UNIQUE index
     * turns any ordering mistake into a hard error rather than a quiet
     * duplicate.
     *
     * @param list<int> $shops
     */
    public function assign(int $productId, array $shops): void
    {
        if ($productId < 1) {
            return;
        }

        $selected = self::normalised($shops);
        $current = $this->of($productId);

        foreach (array_diff($current, $selected) as $shopId) {
            $this->connection->delete('tl_consho_product_shop', ['pid' => $productId, 'shop' => $shopId]);
        }

        foreach (array_diff($selected, $current) as $shopId) {
            $this->connection->insert('tl_consho_product_shop', [
                'pid' => $productId,
                'shop' => $shopId,
                'tstamp' => time(),
            ]);
        }
    }

    /**
     * The shops the product is assigned to itself, ascending. A variant with
     * none follows its main product; that rule belongs to the reader.
     *
     * @return list<int>
     */
    public function of(int $productId): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT shop FROM tl_consho_product_shop WHERE pid = ? ORDER BY shop',
            [$productId],
        ));
    }

    /**
     * @param array<mixed> $shops
     *
     * @return list<int>
     */
    private static function normalised(array $shops): array
    {
        $ids = array_values(array_unique(array_map(intval(...), array_filter($shops, is_scalar(...)))));

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }
}
