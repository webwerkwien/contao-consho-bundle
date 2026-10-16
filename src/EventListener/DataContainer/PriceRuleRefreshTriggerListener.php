<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;
use Webwerkwien\ContaoConshoBundle\Pricing\CatalogPriceRules;

/**
 * A saved, toggled, deleted or restored catalogue price rule (block H, H-3)
 * changes prices without touching a product — so the product's own trigger
 * never fires.
 *
 * Reports every main product, like SubscriptionRuleRefreshTriggerListener:
 * the rule's conditions BEFORE the change are gone by now, and a product it no
 * longer matches has to lose the reduction. Rules change rarely; a stale price
 * is the worse failure. The refresh itself only counts — and invalidates —
 * what actually changed.
 *
 * The product pages read prices themselves and are tagged CATALOG, which the
 * refresh queue does not invalidate (it handles the listings): so here.
 */
class PriceRuleRefreshTriggerListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogRefreshQueue $queue,
        private readonly CatalogPriceRules $rules,
        private readonly CatalogCacheTags|null $catalog = null,
    ) {
    }

    #[AsCallback(table: 'tl_consho_price_rule', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_price_rule', target: 'config.ondelete')]
    public function onRule(DataContainer $dc, int|string|null $undoId = null): void
    {
        $this->report();
    }

    /**
     * The list's toggle (published) saves without onsubmit.
     */
    #[AsCallback(table: 'tl_consho_price_rule', target: 'fields.published.save')]
    public function onToggle(mixed $value, DataContainer $dc): mixed
    {
        $this->report();

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_price_rule', target: 'config.onundo')]
    public function onUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->report();
    }

    /**
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_price_rule', target: 'config.onrestore_version')]
    public function onRestore(string $table, int $id, int $version, array $data): void
    {
        $this->report();
    }

    private function report(): void
    {
        // Read again at the refresh, not what this request may have cached.
        $this->rules->reset();

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_product WHERE pid = 0 ORDER BY id') as $productId) {
            $this->queue->add((int) $productId);
        }

        $this->catalog?->invalidate([CatalogCacheTags::CATALOG]);
    }
}
