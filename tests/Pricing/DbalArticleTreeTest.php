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
        // A variant without its own price inherits — if the main product changes, it changes along.
        self::assertEqualsCanonicalizing([1 => 1, 10 => 1, 11 => 1], $this->tree()->articlesOf([1]));
    }

    public function testAVariantBringsOnlyItselfWithItsMainProduct(): void
    {
        // A variant's price moves neither the main product nor its siblings.
        self::assertSame([10 => 1], $this->tree()->articlesOf([10]));
    }

    public function testAProductWithoutVariantsStandsForItself(): void
    {
        self::assertSame([2 => 2], $this->tree()->articlesOf([2]));
    }

    public function testAProductThatNoLongerExistsIsNotInTheTree(): void
    {
        // The projector adds it itself, so that its rows are deleted.
        self::assertSame([], $this->tree()->articlesOf([99]));
    }

    private function tree(): DbalArticleTree
    {
        return new DbalArticleTree($this->connection);
    }
}
