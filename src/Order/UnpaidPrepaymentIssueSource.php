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
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;

/**
 * Prepayment orders still unpaid after two weeks (spec G, E15; Michael,
 * 2026-10-08: "E-Punkte passen").
 *
 * An unpaid prepayment order holds its stock — and its keys — until the
 * operator cancels it; Consho cancels nothing by itself. Prepayment has no
 * payment term, so InvoiceOverdue never names it, and the dashboard's
 * "unbezahlt" counts it without saying for how long. This says it.
 *
 * Fourteen days, fixed: the placeholder of a payment method's term, and long
 * enough for a transfer that crosses a weekend twice. A note, not a warning —
 * waiting longer is the operator's choice.
 *
 * Candidates by SQL, the balance decides — as OverdueInvoiceIssueSource does,
 * and cached like it.
 */
final class UnpaidPrepaymentIssueSource implements ConfigurationIssueSource
{
    public const DAYS = 14;

    /** Numbers named in the finding; the count says how many more. */
    private const NAMED = 5;

    private const CACHE_KEY = 'consho_unpaid_prepayments';

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
            ? $this->unpaid(time())
            : $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->unpaid(time());
            });
    }

    /**
     * @return list<ConfigurationIssue>
     */
    public function unpaid(int $now): array
    {
        $candidates = $this->connection->fetchAllAssociative(
            'SELECT o.id, o.shop, o.orderNumber FROM tl_consho_order o
              INNER JOIN tl_consho_payment_method m ON m.id = o.paymentMethod
              WHERE m.type = :type AND o.createdAt < :before AND o.cancelledAt IS NULL
                AND o.grandTotal > (SELECT COALESCE(SUM(p.amount), 0) FROM tl_consho_payment p WHERE p.pid = o.id)
              ORDER BY o.shop, o.createdAt, o.id',
            ['type' => PrepaymentHandler::getType(), 'before' => $now - self::DAYS * 86400],
        );

        $byShop = [];

        foreach ($candidates as $row) {
            $balance = $this->balances->of((int) $row['id']);

            if (null === $balance || $balance->cancelled || !BigDecimal::of($balance->outstanding())->isPositive()) {
                continue;
            }

            $byShop[(int) $row['shop']][] = (string) $row['orderNumber'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $numbers) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::PrepaymentUnpaid, [
                'shop' => (string) $this->connection->fetchOne('SELECT title FROM tl_consho_shop WHERE id = ?', [$shopId]),
                'days' => (string) self::DAYS,
                'count' => (string) \count($numbers),
                'numbers' => implode(', ', \array_slice($numbers, 0, self::NAMED)).(\count($numbers) > self::NAMED ? ', …' : ''),
            ], $shopId);
        }

        return $issues;
    }
}
