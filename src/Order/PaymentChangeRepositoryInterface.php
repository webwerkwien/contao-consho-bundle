<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;

/**
 * What a change of payment method reads and writes (7.5).
 */
interface PaymentChangeRepositoryInterface
{
    /**
     * @return array<string, string>|null
     */
    public function order(int $orderId): array|null;

    /**
     * The order row, locked for the rest of the transaction.
     *
     * @return array<string, string>|null
     */
    public function lockOrder(int $orderId): array|null;

    /**
     * @return list<array<string, string>>
     */
    public function items(int $orderId): array;

    /**
     * @return list<string> the amounts received, negative for refunds
     */
    public function paymentAmounts(int $orderId): array;

    /**
     * Whether this status holds the order still: it locks changes, or counts
     * as paid or cancelled.
     */
    public function statusHolds(int $statusId): bool;

    /**
     * @return list<int>
     */
    public function memberGroups(int $memberId): array;

    /**
     * @return list<PaymentAttempt> the attempts still open or pending
     */
    public function openAttempts(int $orderId): array;

    public function cancelAttempt(int $attemptId, int $now): void;

    /**
     * Removes the order's payment lines and writes these in their place.
     *
     * @param list<array<string, string|int>> $lines
     */
    public function replacePaymentLines(int $orderId, array $lines, int $now): void;

    /**
     * @param array<string, string|int> $fields
     */
    public function updateOrder(int $orderId, array $fields): void;

    /**
     * Whether a delivery of the order is reported — after it, a method that
     * issues on shipment has to issue at once.
     */
    public function hasReportedDelivery(int $orderId): bool;

    /**
     * Whether the order is the purchase or a follow-up of a subscription —
     * then its payment term decides whether the subscription rests (Abo-5).
     */
    public function belongsToSubscription(int $orderId): bool;

    /**
     * Whether the order carries entitlements of no subscription — digital
     * goods that rest when it is overdue (Nachlese E5a, PurchaseDelinquency).
     */
    public function hasOneTimeEntitlements(int $orderId): bool;
}
