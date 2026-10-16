<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * What just happened to an order (D-1) — the reason a status may change.
 */
enum OrderEvent
{
    /** The payment balance came out settled for the first time. */
    case PaymentSettled;

    /** The operator reported a delivery shipped. */
    case DeliveryReported;

    // D-3 (F10): a refund was booked — only "when everything is settled" can follow.
    case WithdrawalRefunded;
}
