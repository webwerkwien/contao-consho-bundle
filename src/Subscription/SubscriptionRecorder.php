<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;

/**
 * One subscription per subscription line, at the purchase (Abo-3; A9, A10).
 *
 * Runs inside OrderPlacer's transaction, right after the order is written,
 * and reads the order's FROZEN lines — not the basket. What the line says is
 * what was bought: price, quantity, tax class, the terms the customer read.
 * The rule's values are copied from the rule as it stands in this very
 * transaction, the one the checkout has just priced with (A9: later changes
 * to the rule do not reach a running subscription).
 *
 * Periods (P16–P18): the subscription starts at the purchase, every boundary
 * is start + n months (SubscriptionCalendar). With one payment for the whole
 * term (interval 0) the first period IS the term.
 *
 * "Lizenz verlängern" (Abo-7b, P68–P70): a line that renews a subscription
 * starts where the old one's paid licence ends (SubscriptionRenewal::
 * licenceEndOf()), where that is still ahead — no double payment,
 * no gap —, names it as predecessor, and claims it in one conditional
 * UPDATE: renewed by nobody yet, the buyer's, the same article and shop, not
 * `suspended`, ending. Two purchases racing for one licence cannot both find
 * that row; the loser's order fails (SubscriptionRenewalRefused). Overdue
 * orders and a resting entitlement are asked by the checkout, not here
 * (claim()).
 */
final class SubscriptionRecorder implements SubscriptionRecorderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionRules $rules,
        private readonly SubscriptionCalendar $calendar,
    ) {
    }

    public function record(int $orderId): array
    {
        $lines = $this->connection->fetchAllAssociative(
            "SELECT id, product, quantity, unitPrice, taxClass, priceSource, subscriptionRule, subscriptionTerms, kind, renews
               FROM tl_consho_order_item
              WHERE pid = ? AND lineType = 'product' AND subscriptionRule > 0
              ORDER BY sorting, id",
            [$orderId],
        );

        if ([] === $lines) {
            return [];
        }

        $order = $this->connection->fetchAssociative(
            'SELECT orderNumber, shop, member, currency, businessCustomer, createdAt FROM tl_consho_order WHERE id = ?',
            [$orderId],
        );

        if (false === $order) {
            throw new \LogicException(\sprintf('Order %d has lines but no head.', $orderId));
        }

        if ((int) $order['member'] <= 0) {
            throw SubscriptionWithoutAccount::forOrder($orderId);
        }

        $rules = $this->rules->byIds(array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['subscriptionRule'],
            $lines,
        ))));

        $start = (int) $order['createdAt'];
        $ids = [];

        foreach ($lines as $line) {
            $ruleId = (int) $line['subscriptionRule'];
            $rule = $rules[$ruleId] ?? throw SubscriptionRuleGoneAtPurchase::withId($ruleId);

            $renews = (int) $line['renews'];
            $lineStart = $renews > 0 ? max($start, $this->endOfPredecessor($renews)) : $start;

            $termMonths = $rule->termMonths();
            $minimumEnd = $this->calendar->addMonths($lineStart, $termMonths);
            $periodEnd = $rule->billingInterval > 0 ? $this->calendar->addMonths($lineStart, $rule->billingInterval) : $minimumEnd;

            $this->connection->insert('tl_consho_subscription', [
                'tstamp' => $start,
                // P19 = B: the first order's number and the line's place among
                // the order's subscription lines — a one-time line does not count.
                'subscriptionNumber' => $order['orderNumber'].'-'.(\count($ids) + 1),
                'shop' => (int) $order['shop'],
                'member' => (int) $order['member'],
                'product' => (int) $line['product'],
                'firstOrder' => $orderId,
                'orderItem' => (int) $line['id'],

                'rule' => $rule->id,
                'ruleTitle' => $rule->title,
                'termLength' => $rule->termLength,
                'termUnit' => $rule->termUnit,
                'billingInterval' => $rule->billingInterval,
                'renewal' => $rule->renewal->value,
                'noticeDays' => $rule->noticeDays,
                'reminderDays' => $rule->reminderDays,
                'graceDays' => $rule->graceDays,
                'pauseMonths' => $rule->pauseMonths,

                'quantity' => (string) $line['quantity'],
                'unitNet' => (string) $line['unitPrice'],
                'taxClass' => (int) $line['taxClass'],
                'currency' => (string) $order['currency'],
                'reverseCharge' => PriceSource::ReverseCharge->value === $line['priceSource'] ? 1 : 0,
                'businessCustomer' => (int) (bool) $order['businessCustomer'],
                'kind' => (string) $line['kind'],
                'terms' => (string) $line['subscriptionTerms'],

                'phase' => 'minimum',
                'state' => 'active',
                'startedAt' => $lineStart,
                'minimumEndsAt' => $minimumEnd,
                'periodFrom' => $lineStart,
                'periodUntil' => $periodEnd,
                'nextBillingAt' => $periodEnd,
                'predecessor' => $renews,
            ]);

            $id = (int) $this->connection->lastInsertId();

            if ($renews > 0) {
                $this->claim($renews, $id, (int) $order['member'], (int) $line['product'], (int) $order['shop'], $start);
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Where the renewed subscription's licence ends (P68): the end of the
     * period covering its contract's end (SubscriptionRenewal::licenceEndOf());
     * 0 — "now" — for one that is gone or runs on, which claim() refuses
     * anyway.
     */
    private function endOfPredecessor(int $subscriptionId): int
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);

        return false === $row ? 0 : SubscriptionRenewal::licenceEndOf($row, $this->calendar);
    }

    /**
     * The renewed subscription, claimed once (P69, P70) — the conditions of
     * SubscriptionRenewal::renewable() that live on the row. Overdue orders
     * and a resting entitlement are the checkout's question before the
     * button; whatever falls overdue after this keeps counting for the
     * successor (SubscriptionDelinquency).
     */
    private function claim(int $predecessor, int $successor, int $memberId, int $productId, int $shopId, int $now): void
    {
        $claimed = $this->connection->executeStatement(
            "UPDATE tl_consho_subscription SET renewedBy = ?, tstamp = ?
              WHERE id = ? AND renewedBy = 0 AND member = ? AND product = ? AND shop = ?
                AND state NOT IN ('suspended', 'paused')
                AND (state = 'ended' OR endsAt > 0 OR renewal = ?)",
            [$successor, $now, $predecessor, $memberId, $productId, $shopId, RenewalKind::Ends->value],
        );

        if (1 !== (int) $claimed) {
            throw SubscriptionRenewalRefused::forSubscription($predecessor);
        }
    }
}
