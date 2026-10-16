<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Das SQLite-Testschema für die beiden Warenkorb-Tabellen, gebaut nach
 * contao/dca/tl_consho_cart.php und tl_consho_cart_item.php.
 *
 * `tokenHash` ist NULL-bar, der UNIQUE-Index läuft seit C-3b über
 * `(tokenHash, shop)`: Ein Cookie identifiziert den Besucher, nicht den
 * Warenkorb — je Shop trägt derselbe Token einen eigenen. Mitglieder-Warenkörbe
 * tragen keinen Hash, und SQLite wie MySQL lassen beliebig viele NULL in einem
 * Unique-Index zu — eine leere Zeichenkette dagegen nur einmal.
 *
 * Die Einfüger schreiben nur die per $overrides gesetzten Spalten, damit
 * ungeschriebene Spalten wirklich ihren Vorgabewert tragen.
 *
 * 🔴 **`order` heißt hier `order`**, in Anführungszeichen, und nicht mehr
 * `orderId` (seit C-7, 2026-09-23). Die Umbenennung war bis dahin harmlos,
 * weil nichts die Spalte je beschrieben hat — und genau deshalb hätte kein
 * Test gemerkt, dass Doctrine reservierte Spaltennamen **nicht** selbst quotet,
 * auch nicht in `insert()`/`update()` mit einem Array. Ein Testschema, das dem
 * Produktionsschema an der einen heiklen Stelle ausweicht, prüft sie nicht.
 */
trait CartSchema
{
    private Connection $cartConnection;

    private function createCartTables(): void
    {
        /*
         * From the DCA (WG-5): the hand-written copy had to be told about
         * every new column and was not told about `vouchers` (Prüfungsnotiz,
         * Fall 31/57). DcaSchema quotes `order` and builds the keys.
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
