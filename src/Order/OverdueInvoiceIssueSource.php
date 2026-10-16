<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Invoices past their payment term and still not paid (E-2, Z5).
 *
 * A purchase on account is a claim, not a payment: the order stays open
 * until the operator records the transfer. What was missing was the moment
 * the claim is late — without it an unpaid invoice waits silently until
 * someone happens to look. A note, not a reminder: dunning is its own
 * subject (spec E, E5), and Consho writes to nobody by itself.
 *
 * The end of the term is on the order (`paymentDueAt`, written with the
 * invoice by DocumentIssuer). SQL narrows to candidates — past the term, not
 * cancelled, less paid than the total — and the balance decides, as for the
 * status note: it knows withdrawals and refunds, a plain sum does not.
 *
 * Cached like OrderStatusIssueSource, for the same reason: every back end
 * page asks for findings.
 */
final class OverdueInvoiceIssueSource implements ConfigurationIssueSource
{
    /** Numbers named in the finding; the count says how many more. */
    private const NAMED = 5;

    private const CACHE_KEY = 'consho_overdue_invoices';

    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface|null $cache = null,
    ) {
    }

    public function issues(): array
    {
        return null === $this->cache
            ? $this->overdue(time())
            : $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->overdue(time());
            });
    }

    /**
     * @return list<ConfigurationIssue>
     */
    public function overdue(int $now): array
    {
        $candidates = $this->connection->fetchAllAssociative(
            'SELECT o.id, o.shop, o.orderNumber FROM tl_consho_order o
              WHERE o.paymentDueAt > 0 AND o.paymentDueAt < :now AND o.cancelledAt IS NULL
                AND o.grandTotal > (SELECT COALESCE(SUM(p.amount), 0) FROM tl_consho_payment p WHERE p.pid = o.id)
              ORDER BY o.shop, o.paymentDueAt, o.id',
            ['now' => $now],
        );

        $byShop = [];

        foreach ($candidates as $row) {
            $balance = $this->balances->of((int) $row['id']);

            if (null === $balance || !BigDecimal::of($balance->outstanding())->isPositive()) {
                continue;
            }

            $byShop[(int) $row['shop']][] = (string) $row['orderNumber'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $numbers) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::InvoiceOverdue, [
                'shop' => (string) $this->connection->fetchOne('SELECT title FROM tl_consho_shop WHERE id = ?', [$shopId]),
                'count' => (string) \count($numbers),
                'numbers' => implode(', ', \array_slice($numbers, 0, self::NAMED)).(\count($numbers) > self::NAMED ? ', …' : ''),
            ], $shopId);
        }

        return $issues;
    }
}
