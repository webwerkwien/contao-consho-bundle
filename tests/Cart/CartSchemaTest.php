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

        // Zweimal derselbe Hash im selben Shop wäre zweimal derselbe
        // Warenkorb. Der Shop ist hier ausdrücklich gesetzt: Ohne ihn trügen
        // beide Zeilen die Vorgabe 0 und der Test prüfte (hash, 0) gegen
        // (hash, 0) — also nicht, was sein Name sagt (Abschlussreview C-3b).
        $this->expectExceptionMessageMatches('/UNIQUE|unique/');

        $this->insertCart(['tokenHash' => str_repeat('a', 64), 'shop' => 3]);
    }

    public function testDerselbeTokenHashDarfInZweiShopsStehen(): void
    {
        // Die gelockerte Seite des Schlüssels: Ein Cookie identifiziert den
        // Besucher, nicht den Warenkorb (C-3b). Unter dem alten Index
        // `(tokenHash)` warf die zweite Zeile.
        $this->insertCart(['tokenHash' => str_repeat('a', 64), 'shop' => 3]);
        $this->insertCart(['tokenHash' => str_repeat('a', 64), 'shop' => 4]);

        self::assertSame(2, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
    }

    public function testMehrereWarenkoerbeOhneTokenHash(): void
    {
        // Mitglieder-Warenkörbe tragen keinen Hash. Ein UNIQUE-Index über eine
        // leere Zeichenkette ließe nur einen einzigen zu — deshalb NULL.
        $this->insertCart(['member' => 1]);
        $this->insertCart(['member' => 2]);

        self::assertSame(2, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
    }

    public function testDieMengeIstDezimal(): void
    {
        $cartId = $this->insertCart(['tokenHash' => str_repeat('b', 64)]);
        $this->insertCartItem(['pid' => $cartId, 'product' => 7, 'quantity' => '2.5000']);

        // NUMERIC-Affinität in SQLite speichert '2.5000' als REAL 2.5 und gibt beim
        // Lesen '2.5' zurück (verlustfrei ohne Nachkommastellen-Auffüllung) — ein
        // exakter String-Vergleich auf '2.5000' würde daher hier immer scheitern,
        // unabhängig vom Schema. Entscheidend ist, dass der Wert dezimal bleibt
        // (nicht auf 2 oder 3 gerundet wird), nicht die Nachkommanullen.
        self::assertSame(2.5, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
    }

    public function testDerselbeArtikelKommtNurEinmalImWarenkorbVor(): void
    {
        // Das ist die tragende Entscheidung der Tabelle: zweimal derselbe
        // Artikel addiert die Menge, statt eine zweite Zeile anzulegen. Ohne
        // den Index wäre das eine Regel im PHP, die eine Nebenläufigkeit
        // aushebelt.
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
