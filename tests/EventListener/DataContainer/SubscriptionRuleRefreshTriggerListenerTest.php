<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SubscriptionRuleRefreshTriggerListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-2b: Die Kachel zeigt das erste Angebot für alle. Eine Aboregel oder
 * eine Produktart kann das ändern, ohne das Produkt anzufassen — gemeldet
 * werden die Produkte, gerechnet wird am Ende des Requests.
 */
class SubscriptionRuleRefreshTriggerListenerTest extends TestCase
{
    private Connection $connection;

    private CatalogRefreshQueue $queue;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');

        // 1 Abo-Produkt mit Variante 10, 2 nur Einmalkauf (Typ 4), 3 Abo-Produkt (Typ 4).
        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'saleSubscription' => 1, 'productType' => 3]);
        $this->connection->insert('tl_consho_product', ['id' => 10, 'pid' => 1, 'productType' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 0, 'saleSubscription' => 0, 'productType' => 4]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 0, 'saleSubscription' => 1, 'productType' => 4]);

        $this->queue = new CatalogRefreshQueue();
    }

    public function testEineRegelMeldetJedesAboProdukt(): void
    {
        $this->listener()->onRule($this->dc(5));

        self::assertSame([1, 3], $this->queue->take(), 'die Variante rechnet ListPriceProjector::refresh() mit');
    }

    public function testLoeschenUndWiederherstellen(): void
    {
        $this->listener()->onRule($this->dc(5), 1);
        self::assertSame([1, 3], $this->queue->take());

        $this->listener()->onRuleUndo('tl_consho_subscription_rule', ['id' => 5], $this->dc(0));
        self::assertSame([1, 3], $this->queue->take());

        $this->listener()->onRuleRestore('tl_consho_subscription_rule', 5, 2, []);
        self::assertSame([1, 3], $this->queue->take());
    }

    public function testEineProduktartMeldetIhreProdukte(): void
    {
        $this->listener()->onType($this->dc(4));
        self::assertSame([2, 3], $this->queue->take());

        $this->listener()->onType($this->dc(4), 1);
        self::assertSame([2, 3], $this->queue->take(), 'auch beim Löschen');
    }

    private function listener(): SubscriptionRuleRefreshTriggerListener
    {
        return new SubscriptionRuleRefreshTriggerListener($this->connection, $this->queue);
    }

    private function dc(int $id): DC_Table
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $dc->id = $id;

        return $dc;
    }
}
