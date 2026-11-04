<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Money that has to go back: a paid order that was cancelled, or one paid
 * more than it claims (block Geldflüsse, 3.4, OverpaidOrders).
 *
 * Paying it back stays the operator's business; Consho reminds and offers
 * the way ("Erstatten" at the payment). A warning, which only paying it back
 * resolves — the customer is owed the money.
 *
 * Cached like OverdueInvoiceIssueSource: every back end page asks for
 * findings.
 */
final class OverpaidOrderIssueSource implements ConfigurationIssueSource
{
    /** Numbers named in the finding; the count says how many more. */
    private const NAMED = 5;

    private const CACHE_KEY = 'consho_overpaid_orders';

    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly Connection $connection,
        private readonly OverpaidOrders $orders,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface|null $cache = null,
    ) {
    }

    public function issues(): array
    {
        return null === $this->cache
            ? $this->overpaid()
            : $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->overpaid();
            });
    }

    /**
     * @return list<ConfigurationIssue>
     */
    public function overpaid(): array
    {
        $byShop = [];

        foreach ($this->orders->all() as $order) {
            $byShop[$order['shop']][] = $order['number'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $numbers) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::OrderOverpaid, [
                'shop' => (string) $this->connection->fetchOne('SELECT title FROM tl_consho_shop WHERE id = ?', [$shopId]),
                'count' => (string) \count($numbers),
                'numbers' => implode(', ', \array_slice($numbers, 0, self::NAMED)).(\count($numbers) > self::NAMED ? ', …' : ''),
            ], $shopId);
        }

        return $issues;
    }
}
