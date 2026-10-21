<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Document\DeferredArchive;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPurchaseCancellation;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;

/**
 * What a cancellation undoes, in one place (Nachlese K6, spec Wertgutscheine).
 *
 * An order is cancelled in two ways — automatically through a status change
 * (ContaoOrderStatusWriter) and by hand in the back end (OrderStatusListener).
 * Each kept its own list of rollbacks, and every block that added one (Abo-7b,
 * G, H, Wertgutscheine) had to remember both. The next one would not have.
 *
 * Once per order: `cancelledAt` is set only where it is empty, and only then
 * does anything run — a status saved twice gives nothing back twice. One
 * transaction with the rollbacks (Abo-7b, second review S-3): a rollback that
 * fails takes `cancelledAt` with it, or the guard would never let it run
 * again. Nested in a caller's transaction, it is a savepoint.
 *
 * The cancellation invoice (dev#1) is issued in the same transaction — a
 * cancellation that rolls back must not leave a cancellation document — and
 * archived only after the commit, as every other document (review Abo-4, S2).
 * Nested in a caller's transaction the commit is only a savepoint; then
 * DeferredArchive files it when the caller's transaction is decided.
 *
 * The collaborators are optional for tests built by hand; the container wires
 * them all.
 */
class OrderCancellation
{
    public function __construct(
        private readonly Connection $connection,
        // A cancelled purchase ends its subscription, a renewal gives the licence back (P72).
        private readonly SubscriptionPurchaseCancellation|null $subscriptions = null,
        // Block G (G9): the goods back into stock.
        private readonly StockReturn|null $stock = null,
        // Block H (H-5): the cart rule's uses back.
        private readonly CartDiscounts|null $discounts = null,
        // Block Wertgutscheine (WG-6, D2): vouchers paid back, bought ones blocked.
        private readonly VoucherReturn|null $vouchers = null,
        // dev#1: the open invoice is cancelled with the order.
        private readonly CancellationInvoice|null $invoice = null,
        private readonly DeferredArchive|null $archive = null,
    ) {
    }

    /**
     * @param int $userId the back end user who cancelled, 0 for an automatic change (stock movement, Abnahme G-8)
     *
     * @return bool whether this call cancelled the order — false where it already was
     */
    public function record(int $orderId, int $now, int $userId = 0): bool
    {
        $documentId = null;

        $cancelled = $this->connection->transactional(function () use ($orderId, $now, $userId, &$documentId): bool {
            $cancelled = (int) $this->connection->executeStatement(
                'UPDATE tl_consho_order SET cancelledAt = ? WHERE id = ? AND cancelledAt IS NULL',
                [$now, $orderId],
            );

            if (0 === $cancelled) {
                return false;
            }

            $this->subscriptions?->afterCancellation($orderId, $now);
            $this->stock?->afterCancellation($orderId, $now, $userId);
            $this->discounts?->afterCancellation($orderId, $now);
            $this->vouchers?->afterCancellation($orderId, $now);
            $documentId = $this->invoice?->issue($orderId);

            return true;
        });

        if (null !== $documentId) {
            // Now, or — inside Contao's "Mehrere bearbeiten" or an automatic
            // change — once the caller's transaction is decided.
            $this->archive?->after($documentId);
        }

        return $cancelled;
    }
}
