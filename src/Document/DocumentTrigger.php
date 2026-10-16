<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

/**
 * When a document comes into being.
 *
 * Configuration, not a hard-wired sequence:
 *
 *     prepayment      -> OnOrder
 *     card, PayPal    -> OnPayment
 *     cash on deliv.  -> OnShipment
 *     invoice         -> OnShipment
 *
 * This is what answers the question Isotope has had open since April 2026,
 * including the prepayment case a commenter raised there: with prepayment the
 * invoice number has to be on the document BEFORE payment.
 *
 * The mapping from payment method to trigger is part of the payment handler
 * interface (C6, PaymentHandlerInterface::documentTrigger()). Block C carries
 * out OnOrder; block D the other two.
 */
enum DocumentTrigger: string
{
    case OnOrder = 'onOrder';
    case OnPayment = 'onPayment';
    case OnShipment = 'onShipment';
}
