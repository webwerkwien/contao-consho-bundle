<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class CartSchemaTest extends TestCase
{
    use CartSchema;

    protected function setUp(): void
    {
        $this->cartConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCartTables();
    }

    public function testEinTokenHashKommtJeShopNurEinmalVor(): void
    {
        $this->insertCart(['tokenHash' => str_repeat('a', 64), 'shop' => 3]);

        // The same hash twice in the same shop would be the same cart twice.
        // The shop is set explicitly here: without it both rows would carry
        // the default 0 and the test would check (hash, 0) against
        // (hash, 0) — that is, not what its name says.
        $this->expectExceptionMessageMatches('/UNIQUE|unique/');

        $this->insertCart(['tokenHash' => str_repeat('a', 64), 'shop' => 3]);
    }

    public function testDerselbeTokenHashDarfInZweiShopsStehen(): void
    {
        // The relaxed side of the key: a cookie identifies the
        // visitor, not the cart (C-3b). Under the old index
        // `(tokenHash)` the second row threw.
        $this->insertCart(['tokenHash' => str_repeat('a', 64), 'shop' => 3]);
        $this->insertCart(['tokenHash' => str_repeat('a', 64), 'shop' => 4]);

        self::assertSame(2, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
    }

    public function testMehrereWarenkoerbeOhneTokenHash(): void
    {
        // Member carts carry no hash. A UNIQUE index over an
        // empty string would allow only a single one — hence NULL.
        $this->insertCart(['member' => 1]);
        $this->insertCart(['member' => 2]);

        self::assertSame(2, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
    }

    public function testDieMengeIstDezimal(): void
    {
        $cartId = $this->insertCart(['tokenHash' => str_repeat('b', 64)]);
        $this->insertCartItem(['pid' => $cartId, 'product' => 7, 'quantity' => '2.5000']);

        // NUMERIC affinity in SQLite stores '2.5000' as REAL 2.5 and returns '2.5'
        // on reading (lossless, without padding the decimals) — an exact
        // string comparison against '2.5000' would therefore always fail here,
        // regardless of the schema. What matters is that the value stays decimal
        // (is not rounded to 2 or 3), not the trailing zeros.
        self::assertSame(2.5, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
    }

    public function testDerselbeArtikelKommtNurEinmalImWarenkorbVor(): void
    {
        // This is the load-bearing decision of the table: the same article
        // twice adds up the quantity instead of creating a second row. Without
        // the index this would be a rule in PHP that concurrency
        // overrides.
        $cartId = $this->insertCart(['tokenHash' => str_repeat('c', 64)]);
        $this->insertCartItem(['pid' => $cartId, 'product' => 7, 'quantity' => '1.0000']);

        $this->expectExceptionMessageMatches('/UNIQUE|unique/');

        $this->insertCartItem(['pid' => $cartId, 'product' => 7, 'quantity' => '1.0000']);
    }

    public function testDerselbeArtikelInZweiWarenkoerbenIstErlaubt(): void
    {
        $first = $this->insertCart(['tokenHash' => str_repeat('d', 64)]);
        $second = $this->insertCart(['tokenHash' => str_repeat('e', 64)]);

        $this->insertCartItem(['pid' => $first, 'product' => 7, 'quantity' => '1.0000']);
        $this->insertCartItem(['pid' => $second, 'product' => 7, 'quantity' => '1.0000']);

        self::assertSame(2, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
    }
}
