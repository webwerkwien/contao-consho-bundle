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
     * Paid in full, and the shop gave part of it back — goodwill, a price
     * reduction, a refund made at the provider. Not Partial: that reads as
     * money the customer still owes, and the list, the dashboard and every
     * check on the open amount would chase a refund the shop chose to make.
     */
    case PartlyRefunded = 'partlyRefunded';

    /**
     * Nothing was owed and nothing came: a cancelled order nobody paid for
     * (D-1). Not Open — that would read as a bill still waiting — and not
     * Paid, which would claim money that never moved.
     */
    case NothingDue = 'nothingDue';
}
