<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * Why the payment method of an order cannot be changed (7.5, C-10).
 *
 * The first two are about the ORDER and hide the whole form; the last two are
 * about one request and only arrive when a POST went past the form.
 */
enum PaymentChangeBlock: string
{
    /**
     * Money has moved (7.5: "solange nichts bezahlt ist"). Switching then
     * would leave a payment against a method the order no longer names — a
     * refund, which is a business process and not a form.
     */
    case NotOpen = 'notOpen';

    /**
     * The operator's status says the order is settled: it locks changes, or
     * it counts as paid or cancelled (Michael, 2026-09-25, E3). The operator
     * holds the order still; the customer must not rebuild it underneath.
     */
    case StatusLocks = 'statusLocks';

    /**
     * The payment method was changed once already (Michael, 2026-09-25,
     * acceptance: „da so Dokumente generiert werden, empfehle ich, dass der
     * Gast das pro Bestellung nur einmal machen kann"). Every change writes a
     * cancellation and an invoice; the spec left it unlimited, and a customer
     * clicking back and forth filled two number ranges for nothing.
     */
    case AlreadyChanged = 'alreadyChanged';

    /** The chosen method cannot serve this order, or is the current one. */
    case MethodUnavailable = 'methodUnavailable';

    /**
     * A withdrawal was accepted (D-3, Fable review B1). A method's fee or
     * discount is worked out on the whole basket; after part of it was
     * withdrawn, a change would rebuild the payment lines on goods the
     * customer no longer buys. The operator settles it — or the customer pays
     * the rest with the method chosen.
     */
    case AfterWithdrawal = 'afterWithdrawal';

    /**
     * An order of a subscription past its payment term (Fable review Abo-5,
     * S2). A change would give it a new term or none — and so lift the rest
     * of the subscription without a payment (P39) or put it off. Before the
     * term the change stays open, as Michael decided for follow-up orders.
     */
    case Overdue = 'overdue';
}
