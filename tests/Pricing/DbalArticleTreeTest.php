<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTree;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

class DbalArticleTreeTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->insertProduct(['id' => 1, 'pid' => 0]);
        $this->insertProduct(['id' => 10, 'pid' => 1]);
        $this->insertProduct(['id' => 11, 'pid' => 1]);
        $this->insertProduct(['id' => 2, 'pid' => 0]);
    }

    public function testAMainProductBringsItsVariants(): void
    {
        // Eine Variante ohne eigenen Preis erbt — ändert sich das Hauptprodukt, ändert sie sich mit.
        self::assertEqualsCanonicalizing([1 => 1, 10 => 1, 11 => 1], $this->tree()->articlesOf([1]));
    }

    public function testAVariantBringsOnlyItselfWithItsMainProduct(): void
    {
        // Der Preis einer Variante bewegt weder das Hauptprodukt noch die Geschwister.
        self::assertSame([10 => 1], $this->tree()->articlesOf([10]));
    }

    public function testAProductWithoutVariantsStandsForItself(): void
    {
        self::assertSame([2 => 2], $this->tree()->articlesOf([2]));
    }

    public function testAProductThatNoLongerExistsIsNotInTheTree(): void
    {
        // Der Projektor ergänzt es selbst, damit seine Zeilen gelöscht werden.
        self::assertSame([], $this->tree()->articlesOf([99]));
    }

    private function tree(): DbalArticleTree
    {
        return new DbalArticleTree($this->connection);
    }
}
