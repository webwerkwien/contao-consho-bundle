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
 * Two findings about order statuses (D-7).
 *
 * ── A status its balance no longer bears (M3) ─────────────────────────────
 *
 * StatusRequirement is checked when a status is SET — by the operator and by
 * the automatic change. A later booking can tip the balance under it: a
 * refund under "Bezahlt", an accepted withdrawal under "Abgeschlossen". And
 * orders from before D-1 were never checked at all (c5: WEB-00044,
 * "Abgeschlossen", 25.00 paid on 20.00). A note, not a change: Michael,
 * 2026-09-28, M3 „wie empfohlen" — Consho does not take a status back by
 * itself; which one fits is the operator's call.
 *
 * Every backend page asks for findings, so the balance is not read for every
 * flagged order. SQL first narrows them to CANDIDATES — orders whose plain
 * numbers already differ: payments not equal to the total, an accepted
 * withdrawal, a cancellation, a product line not fully reported as shipped,
 * any shipment under a cancelling status. An ordinary finished order is none
 * of these. Only the candidates go through OrderBalanceReader, which decides.
 *
 * ── A cancelling status as an automatic target (M4) ───────────────────────
 *
 * Spec D section 10 asked for "a target status whose flag's precondition can
 * never hold", with "after payment → a status with isShipped" as the example.
 * Measured in D-7: that one CAN hold — cash on delivery is shipped before it
 * is paid. What never holds is a CANCELLING target after payment or after
 * delivery (OrderStatusAdvancer, StatusRequirement): after a delivery
 * something has shipped, which a cancellation forbids; PaymentSettled fires
 * only with a claim above zero, so money has come in, and a cancelled order
 * with money in is overpaid. The change is skipped silently — so a warning.
 *
 * NOT "when settled" (Fable review D-7, S1, reproduced with the real
 * advancer): a withdrawal of everything before shipping, fully refunded,
 * leaves claim 0, paid 0, nothing shipped — the cancelling status is set, and
 * rightly so. OrderStatusAdvancerTest holds that path.
 *
 * ── Cost ──────────────────────────────────────────────────────────────────
 *
 * Contao asks for system messages on EVERY back end page (the counter in the
 * header, BackendHeaderListener), and M3 reads every order in a flagged
 * status (Fable review D-7, S2). Its result is cached for a few minutes; a
 * note may lag that long behind a booking.
 */
final class OrderStatusIssueSource implements ConfigurationIssueSource
{
    /** Numbers named in the finding; the count says how many more. */
    private const NAMED = 5;

    private const FIELDS = ['statusAfterPayment', 'statusAfterDelivery'];

    private const CACHE_KEY = 'consho_status_contradictions';

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
        $contradictions = null === $this->cache
            ? $this->contradictions()
            : $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->contradictions();
            });

        return [...$this->cancellingTargets(), ...$contradictions];
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function cancellingTargets(): array
    {
        $issues = [];

        foreach (self::FIELDS as $field) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT s.id, s.title, st.title AS status FROM tl_consho_shop s
                 JOIN tl_consho_order_status st ON st.id = s.$field AND st.pid = s.id
                 WHERE st.isCancelled = 1
                 ORDER BY s.id",
            );

            foreach ($rows as $row) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::AutomaticStatusCancels, [
                    'shop' => (string) $row['title'],
                    // default.php, which the start page has loaded (as RefundRangeMissing).
                    'field' => (string) ($GLOBALS['TL_LANG']['CONSHO']['statusFields'][$field] ?? $field),
                    'status' => (string) $row['status'],
                ], (int) $row['id']);
            }
        }

        return $issues;
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function contradictions(): array
    {
        $candidates = $this->connection->fetchAllAssociative(
            "SELECT o.id AS orderId, o.shop AS orderShop, o.orderNumber, st.id, st.pid, st.title,
                    st.isPaid, st.isShipped, st.isCompleted, st.isCancelled, st.locksChanges
             FROM tl_consho_order o
             JOIN tl_consho_order_status st ON st.id = o.orderStatus
             WHERE (st.isPaid = 1 OR st.isShipped = 1 OR st.isCompleted = 1 OR st.isCancelled = 1)
               AND (
                    ((st.isPaid = 1 OR st.isCompleted = 1) AND (
                        o.cancelledAt IS NOT NULL
                        OR st.isCancelled = 1
                        OR o.grandTotal <> (SELECT COALESCE(SUM(p.amount), 0) FROM tl_consho_payment p WHERE p.pid = o.id)
                        OR EXISTS (SELECT 1 FROM tl_consho_withdrawal w WHERE w.pid = o.id AND w.state = 'accepted')
                    ))
                    OR ((st.isShipped = 1 OR st.isCompleted = 1) AND EXISTS (
                        SELECT 1 FROM tl_consho_order_item i
                        WHERE i.pid = o.id AND i.lineType NOT IN ('shipping', 'paymentFee', 'paymentDiscount', 'discount')
                          AND i.quantity > (
                              SELECT COALESCE(SUM(di.quantity), 0) FROM tl_consho_delivery_item di
                              JOIN tl_consho_delivery d ON d.id = di.pid
                              WHERE di.orderItem = i.id AND d.reportedAt IS NOT NULL
                          )
                    ))
                    OR (st.isCancelled = 1 AND EXISTS (
                        SELECT 1 FROM tl_consho_delivery d WHERE d.pid = o.id AND d.reportedAt IS NOT NULL
                    ))
               )
             ORDER BY o.shop, o.id",
        );

        $byShop = [];

        foreach ($candidates as $row) {
            $balance = $this->balances->of((int) $row['orderId']);

            if (null === $balance) {
                continue;
            }

            $flags = StatusFlags::fromRow($row);

            if ([] !== StatusRequirement::unmet($flags, $balance)) {
                // The order's shop, not the status's: a status of another
                // shop is itself an error, and the note belongs where the
                // order is (Fable review D-7, K2).
                $byShop[(int) $row['orderShop']][] = (string) $row['orderNumber'];
            }
        }

        $issues = [];

        foreach ($byShop as $shopId => $numbers) {
            $named = \array_slice($numbers, 0, self::NAMED);

            $issues[] = new ConfigurationIssue(ConfigurationFinding::StatusContradictsBalance, [
                'shop' => (string) $this->connection->fetchOne('SELECT title FROM tl_consho_shop WHERE id = ?', [$shopId]),
                'count' => (string) \count($numbers),
                'numbers' => implode(', ', $named).(\count($numbers) > self::NAMED ? ', …' : ''),
            ], $shopId);
        }

        return $issues;
    }
}
