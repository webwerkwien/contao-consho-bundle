<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * Derived from payment records, never maintained by hand.
 *
 * Nothing writes this to the database. It is recomputed from the rows, so it
 * cannot drift out of step with them — which is exactly what a maintained
 * payment status does the first time someone books a refund.
 */
enum PaymentState: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    case Refunded = 'refunded';

    /**
     * Nothing was owed and nothing came: a cancelled order nobody paid for
     * (D-1). Not Open — that would read as a bill still waiting — and not
     * Paid, which would claim money that never moved.
     */
    case NothingDue = 'nothingDue';
}
