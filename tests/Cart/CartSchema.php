<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The SQLite test schema for the two cart tables, built after
 * contao/dca/tl_consho_cart.php and tl_consho_cart_item.php.
 *
 * `tokenHash` is nullable, the UNIQUE index has covered `(tokenHash, shop)`
 * since C-3b: a cookie identifies the visitor, not the
 * cart — per shop the same token carries a cart of its own. Member carts
 * carry no hash, and SQLite as well as MySQL allow any number of NULLs in a
 * unique index — an empty string, by contrast, only once.
 *
 * The inserters write only the columns set via $overrides, so that
 * unwritten columns really carry their default value.
 *
 * 🔴 **`order` is called `order` here**, quoted, and no longer
 * `orderId` (since C-7). Until then the renaming was harmless,
 * because nothing ever wrote the column — and precisely for that reason no
 * test would have noticed that Doctrine does **not** quote reserved column names
 * itself, not even in `insert()`/`update()` with an array. A test schema that
 * dodges the production schema at the one tricky spot does not test it.
 */
trait CartSchema
{
    private Connection $cartConnection;

    private function createCartTables(): void
    {
        /*
         * From the DCA (WG-5): the hand-written copy had to be told about
         * every new column and was not told about `vouchers` — hand-written
         * test schemas go stale with the next column. DcaSchema quotes `order`
         * and builds the keys.
         */
        DcaSchema::create($this->cartConnection, 'tl_consho_cart');
        // Builds the unique key pid,product,subscriptionRule,renews as well:
        // since Abo-2 another model is a row of its own (P9).
        DcaSchema::create($this->cartConnection, 'tl_consho_cart_item');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertCart(array $overrides = []): int
    {
        $this->cartConnection->insert('tl_consho_cart', $overrides);

        return (int) $this->cartConnection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertCartItem(array $overrides = []): int
    {
        $this->cartConnection->insert('tl_consho_cart_item', $overrides);

        return (int) $this->cartConnection->lastInsertId();
    }
}
