<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * What the order page tells a visitor who asked for a new link (D-6, T4).
 *
 * No "not found": Requested covers the match and the miss alike.
 */
enum OrderLinkOutcome: string
{
    case Requested = 'requested';
    case TooMany = 'tooMany';
    case Missing = 'missing';
}
