<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartAgeLimit;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Die Altersfreigabe eines ganzen Warenkorbs (C19, C-4).
 */
class CartAgeLimitTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
    }

    public function testEinLeererKorbHatKeineAltersfreigabe(): void
    {
        self::assertSame(0, $this->limit([]));
    }

    public function testOhneAltersfreigabeBleibtEsNull(): void
    {
        $this->product(1);

        self::assertSame(0, $this->limit([1]));
    }

    public function testDieHoechsteUeberAlleZeilenGilt(): void
    {
        // Nicht die des ersten Artikels und nicht die Summe: Ein Korb mit
        // Kräutersalz und Schwedenbitter ist ab 18.
        $this->product(1, minimumAge: 0);
        $this->product(2, minimumAge: 16);
        $this->product(3, minimumAge: 18);

        self::assertSame(18, $this->limit([1, 2, 3]));
        self::assertSame(16, $this->limit([1, 2]));
    }

    public function testEineVarianteKannDieFreigabeDesHauptproduktsAnheben(): void
    {
        // Dieselbe Regel wie ProductDetailAssembler: der höhere von beiden.
        // Eine 40-%-Abfüllung ist nicht deshalb weniger beschränkt, weil die
        // Reihe als Ganzes ab 16 verkauft wird.
        $this->product(1, minimumAge: 16);
        $this->variant(2, 1, minimumAge: 18);

        self::assertSame(18, $this->limit([2]));
    }

    public function testEineVarianteKannSieNichtSenken(): void
    {
        $this->product(1, minimumAge: 18);
        $this->variant(2, 1, minimumAge: 0);

        self::assertSame(18, $this->limit([2]));
    }

    public function testEinArtikelDenEsNichtMehrGibtZaehltNicht(): void
    {
        // Der Korb überlebt einen gelöschten Artikel (CartTitles lässt ihn
        // ebenso weg). Ein Fehler wäre hier eine leere Seite für einen
        // Hinweis, der nichts sperrt.
        $this->product(1, minimumAge: 16);

        self::assertSame(16, $this->limit([1, 999]));
    }

    /**
     * @param list<int> $articleIds
     */
    private function limit(array $articleIds): int
    {
        return (new CartAgeLimit($this->connection))->of($articleIds);
    }

    private function product(int $id, int $minimumAge = 0): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'title' => 'Produkt '.$id, 'minimumAge' => $minimumAge]);
    }

    private function variant(int $id, int $of, int $minimumAge = 0): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $of, 'title' => 'Variante '.$id, 'minimumAge' => $minimumAge]);
    }
}
