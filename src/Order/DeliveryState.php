<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * Derived from delivery records, never maintained by hand.
 */
enum DeliveryState: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Complete = 'complete';
}
