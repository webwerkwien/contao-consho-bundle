<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * What a status flag requires of the order before it may be set (spec D 5.2,
 * decision A1).
 *
 * A status must not claim what the payments and deliveries do not bear out.
 * Before D-1 an operator could set "Bezahlt" on an order nobody had paid, and
 * nothing looked at it again; C-11 left the question "what if the payment
 * state and the status disagree" open. For flagged statuses it can no longer
 * happen. Statuses without flags — "In Klärung" — stay free.
 */
enum StatusRequirement: string
{
    case PaymentSettled = 'paymentSettled';
    case DeliverySettled = 'deliverySettled';

    /**
     * Cancelling is only for an order nothing of which has left (A1a). After
     * that it is a withdrawal or a return (D-3), and a cancellation would
     * pretend the parcel never went.
     */
    case NothingShipped = 'nothingShipped';

    /**
     * The requirements the flags of a status place on an order, and which of
     * them the balance does not meet.
     *
     * @param OrderBalance $balance the balance the order WOULD have in that
     *                              status — a cancelling status owes nothing
     *
     * @return list<self> empty when the status may be set
     */
    public static function unmet(StatusFlags $flags, OrderBalance $balance): array
    {
        $unmet = [];

        if (($flags->isPaid || $flags->isCompleted) && !$balance->paymentSettled()) {
            $unmet[] = self::PaymentSettled;
        }

        if (($flags->isShipped || $flags->isCompleted) && !$balance->deliverySettled()) {
            $unmet[] = self::DeliverySettled;
        }

        if ($flags->isCancelled && $balance->anyShipped) {
            $unmet[] = self::NothingShipped;
        }

        return $unmet;
    }
}
