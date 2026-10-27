<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

class DbalArticleTaxClassesTest extends TestCase
{
    use CatalogSchema;

    public function testAVariantIsTaxedWithItsOwnClassNotTheMainProducts(): void
    {
        // taxClass is required on every row, variants included — and OrderFactory
        // taxes the line item with the article's class. The display must not
        // calculate anything else, even if the variant inherits the price rows.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($connection);
        $this->insertProduct(['id' => 1, 'pid' => 0, 'taxClass' => 2]);
        $this->insertProduct(['id' => 10, 'pid' => 1, 'taxClass' => 3]);

        $classes = new DbalArticleTaxClasses($connection);

        self::assertSame(2, $classes->taxClassOf(1));
        self::assertSame(3, $classes->taxClassOf(10));
        self::assertSame(0, $classes->taxClassOf(99));
    }
}
