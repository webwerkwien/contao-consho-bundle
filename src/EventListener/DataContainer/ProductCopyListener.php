<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * A copied product takes its shops, properties and variant options along.
 *
 * Found at the acceptance of Abo-5 (2026-09-30): "Speichern und duplizieren"
 * copied the row, the prices and the subscription rules — but not the shop
 * assignment. The copy was published and reachable nowhere (a main product
 * is visible only where it is assigned, VisibilityCriteria::assignedToShop).
 *
 * The three live in join tables that only the save callbacks write
 * (ProductShopsListener, ProductPropertiesListener); DC_Table copies columns
 * and child tables, and these fields have no column.
 *
 * ⚠️ Variants are NOT copied along with their main product: they are rows of
 * the same table, and `ctable` names only prices and translations. (The first
 * version of this comment claimed the opposite, without looking — review
 * Abo-6, S3.) A copied VARIANT does come through here, with its own id; its
 * variant options stay behind — copied, they would make a second variant with
 * the same combination under the same main product, and the variant matrix
 * would silently take the first.
 */
class ProductCopyListener
{
    private const TABLES = ['tl_consho_product_shop' => 'shop', 'tl_consho_product_property' => 'optionId', 'tl_consho_product_option' => 'optionId'];

    private const VARIANT_OPTIONS = 'tl_consho_product_option';

    public function __construct(private readonly Connection $connection)
    {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.oncopy')]
    public function onCopy(int|string $insertId, DataContainer $dc): void
    {
        $this->copy((int) $dc->id, (int) $insertId, time());
    }

    public function copy(int $from, int $to, int $now): void
    {
        if ($from < 1 || $to < 1 || $from === $to) {
            return;
        }

        $isVariant = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$from]) > 0;

        foreach (self::TABLES as $table => $column) {
            if ($isVariant && self::VARIANT_OPTIONS === $table) {
                continue;
            }

            $existing = array_map('intval', $this->connection->fetchFirstColumn(\sprintf('SELECT %s FROM %s WHERE pid = ?', $column, $table), [$to]));

            foreach ($this->connection->fetchFirstColumn(\sprintf('SELECT %s FROM %s WHERE pid = ? ORDER BY id', $column, $table), [$from]) as $value) {
                // Unique on (pid, value): a second call must not fail.
                if (!\in_array((int) $value, $existing, true)) {
                    $this->connection->insert($table, ['pid' => $to, $column => (int) $value, 'tstamp' => $now]);
                }
            }
        }
    }
}
