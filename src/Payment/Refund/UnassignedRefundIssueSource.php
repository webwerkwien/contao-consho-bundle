<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Refund rows that name no payment (block Geldflüsse, J3, UnassignedRefunds),
 * one finding per shop with the orders they belong to. Resolved by
 * "Zahlung zuordnen" on the page "Geldflüsse".
 *
 * Cached like OverpaidOrderIssueSource: every back end page asks for
 * findings.
 */
final class UnassignedRefundIssueSource implements ConfigurationIssueSource
{
    /** Numbers named in the finding; the count says how many more. */
    private const NAMED = 5;

    private const CACHE_KEY = 'consho_unassigned_refunds';

    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly Connection $connection,
        private readonly UnassignedRefunds $refunds,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface|null $cache = null,
    ) {
    }

    public function issues(): array
    {
        return null === $this->cache
            ? $this->unassigned()
            : $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->unassigned();
            });
    }

    /**
     * @return list<ConfigurationIssue>
     */
    public function unassigned(): array
    {
        $byShop = [];

        foreach ($this->refunds->all() as $row) {
            $byShop[$row['shop']][$row['order']] = $row['number'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $numbers) {
            $numbers = array_values($numbers);
            $issues[] = new ConfigurationIssue(ConfigurationFinding::RefundWithoutPayment, [
                'shop' => (string) $this->connection->fetchOne('SELECT title FROM tl_consho_shop WHERE id = ?', [$shopId]),
                'count' => (string) \count($numbers),
                'numbers' => implode(', ', \array_slice($numbers, 0, self::NAMED)).(\count($numbers) > self::NAMED ? ', …' : ''),
            ], $shopId);
        }

        return $issues;
    }
}
