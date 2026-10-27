<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PriceRuleRefreshTriggerListener;
use Webwerkwien\ContaoConshoBundle\Pricing\CatalogPriceRules;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\ResolvedPrice;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block H (H-3): a saved price rule reports all products, invalidates the
 * product pages and has the rules read anew.
 */
class PriceRuleRefreshTriggerListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_price_rule', 'tl_consho_product_property', 'tl_consho_product_type'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 1]);
    }

    public function testMeldetDieProdukteUndEntwertetDieSeiten(): void
    {
        $queue = new CatalogRefreshQueue();
        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::CATALOG]);

        (new PriceRuleRefreshTriggerListener($this->connection, $queue, new CatalogPriceRules($this->connection), new CatalogCacheTags($this->connection, $tags)))
            ->onRule($this->createStub(DataContainer::class));

        self::assertSame([1, 2], $queue->take());
    }

    public function testDieRegelnWerdenNeuGelesen(): void
    {
        $rules = new CatalogPriceRules($this->connection);
        $price = new ResolvedPrice('10.0000', 'EUR', PriceSource::List, '1');

        // Asked once, without a rule: remembered for the request.
        self::assertSame('10.0000', $rules->apply(1, 1, $price, [], 1000)->net);

        $this->connection->insert('tl_consho_price_rule', ['tstamp' => 1, 'title' => 'Aktion', 'published' => 1, 'percent' => '20']);
        (new PriceRuleRefreshTriggerListener($this->connection, new CatalogRefreshQueue(), $rules))->onToggle('1', $this->createStub(DataContainer::class));

        self::assertSame('8.0000', $rules->apply(1, 1, $price, [], 1000)->net);
    }
}
