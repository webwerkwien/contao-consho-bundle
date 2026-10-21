<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * Why a cancelling status would leave the invoice standing (dev#1).
 */
enum CancellationInvoiceRefusal: string
{
    /** An invoice correction already credits part of the invoice. */
    case Corrected = 'corrected';

    /** The shop has no number range for cancellations. */
    case NumberRangeMissing = 'numberRangeMissing';
}
