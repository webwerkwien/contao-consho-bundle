<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;

/**
 * Reports the products whose listing figure may change with a subscription
 * rule or a product type (Abo-2b).
 *
 * Since Abo-2b the list price projection holds the first offer open to
 * everyone (ArticlePriceResolver::listPrice()): a rule given customer groups,
 * or switched to "same term", stops being that offer; a deleted rule drops
 * out of SaleModes; a product type turned physical sells once only. None of it
 * touches the product, so the product's own trigger never fires.
 *
 * Only reports, like TaxRateRefreshTriggerListener, and for the same trade-off:
 * rules and types change rarely, a stale tile is the worse failure. A rule
 * reports every product sold as a subscription rather than parsing the
 * serialized selection — a few too many are recalculated, none is missed.
 */
class SubscriptionRuleRefreshTriggerListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogRefreshQueue $queue,
    ) {
    }

    #[AsCallback(table: 'tl_consho_subscription_rule', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_subscription_rule', target: 'config.ondelete')]
    public function onRule(DataContainer $dc, int|string|null $undoId = null): void
    {
        $this->reportSubscriptionProducts();
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_subscription_rule', target: 'config.onundo')]
    public function onRuleUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->reportSubscriptionProducts();
    }

    /**
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_subscription_rule', target: 'config.onrestore_version')]
    public function onRuleRestore(string $table, int $id, int $version, array $data): void
    {
        $this->reportSubscriptionProducts();
    }

    /**
     * Deleted, too (Fable review Abo-2b, K4): without its type SaleModes
     * falls back to "physical", sold once only — ondelete runs while the
     * products still point at it.
     */
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.ondelete')]
    public function onType(DataContainer $dc, int|string|null $undoId = null): void
    {
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_product WHERE productType = ? ORDER BY id', [(int) $dc->id]) as $productId) {
            $this->queue->add((int) $productId);
        }
    }

    private function reportSubscriptionProducts(): void
    {
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_product WHERE pid = 0 AND saleSubscription = 1 ORDER BY id') as $productId) {
            $this->queue->add((int) $productId);
        }
    }
}
