<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;

/**
 * The automatic status change after a payment or a delivery (spec D 5.3).
 *
 * Asked by the event services inside their transaction, after lockOrder():
 * the balance it reads is the one the event just produced.
 *
 * Which status: "when settled" first, then the one for the event that just
 * happened. The first configured one whose preconditions hold wins. Nothing
 * changes out of a status that holds the order (isCancelled, isCompleted,
 * locksChanges) — the operator put it there on purpose.
 */
class OrderStatusAdvancer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DbalOrderStatusRepository $statuses,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly OrderStatusWriterInterface $writer,
    ) {
    }

    /**
     * @return StatusFlags|null the status set, null where nothing changed
     */
    public function advance(int $orderId, OrderEvent $event, int $now): StatusFlags|null
    {
        $order = $this->connection->fetchAssociative('SELECT shop, orderStatus FROM tl_consho_order WHERE id = ?', [$orderId]);

        if (false === $order) {
            return null;
        }

        $current = $this->statuses->flags((int) $order['orderStatus']);

        if (null !== $current && $current->holds()) {
            return null;
        }

        $shop = $this->connection->fetchAssociative(
            'SELECT statusAfterPayment, statusAfterDelivery, statusWhenSettled FROM tl_consho_shop WHERE id = ?',
            [(int) $order['shop']],
        );

        if (false === $shop) {
            return null;
        }

        /*
         * Each candidate carries the condition of the FIELD it came from, not
         * one looked up by its id: with the same status in two fields, a
         * lookup by id found the first and always asked "settled" — after a
         * payment nothing happened (D-1).
         *
         * The fields carry their own condition beyond the flags: a status
         * "when settled" without isCompleted would otherwise be set after the
         * first payment, because its flags require nothing.
         */
        $candidates = [[(int) $shop['statusWhenSettled'], static fn (OrderBalance $b): bool => $b->settled()]];
        $candidates[] = match ($event) {
            OrderEvent::PaymentSettled => [(int) $shop['statusAfterPayment'], static fn (OrderBalance $b): bool => $b->paymentSettled()],
            OrderEvent::DeliveryReported => [(int) $shop['statusAfterDelivery'], static fn (OrderBalance $b): bool => $b->deliverySettled()],

            // No field of its own (F10): after a refund only "settled" fits.
            OrderEvent::WithdrawalRefunded => [0, static fn (OrderBalance $b): bool => false],
        };

        foreach ($candidates as [$candidate, $fits]) {
            $target = $this->statuses->flags($candidate);

            // Another shop's status is no option, even if an id says so.
            if (null === $target || $target->shop !== (int) $order['shop'] || $target->id === $current?->id) {
                continue;
            }

            $balance = $this->balances->of($orderId, $target->id);

            if (null === $balance || [] !== StatusRequirement::unmet($target, $balance) || !$fits($balance)) {
                continue;
            }

            $this->writer->write($orderId, $target, $now);

            return $target;
        }

        return null;
    }
}
