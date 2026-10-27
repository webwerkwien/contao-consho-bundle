<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityIssueSource;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Command\PriceRebuildCommand;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ProjectionWriterInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * consho:price:rebuild then also builds the visibility projection (B-10):
 * "priced" and the from-price come from the list prices.
 */
class PriceRebuildCommandTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    public function testTheVisibilityProjectionFollowsTheListPrices(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($connection);
        $this->createRootPageTable($connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
        // The row the price rebuild writes — its writer is a mock here.
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);

        $cache = new ArrayAdapter();
        $cache->get(VisibilityIssueSource::CACHE_KEY, static fn (): int => 1);

        $visibility = new VisibilityProjector($connection, new VisibilityRows(new VisibilityCriteria()), new ShopLanguages($connection), new ProjectionState($connection));
        $tester = new CommandTester(new PriceRebuildCommand($this->listPriceProjector(), $cache, null, null, $visibility));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility WHERE pid = 1 AND variant = 0'));
        self::assertFalse($cache->hasItem(VisibilityIssueSource::CACHE_KEY), 'Der Befund darf nicht eine Stunde weiter melden, was behoben ist.');
    }

    private function listPriceProjector(): ListPriceProjector
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([]);
        $repository->method('findCurrencies')->willReturn([]);

        return new ListPriceProjector(
            $repository,
            new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator(), $this->createMock(ArticleTaxClassesInterface::class)),
            $this->createMock(ProjectionWriterInterface::class),
            $this->createMock(ArticleTreeInterface::class),
            $this->createMock(MarketRepositoryInterface::class),
        );
    }
}
