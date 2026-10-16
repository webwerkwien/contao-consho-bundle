<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

enum WithdrawalOutcome: string
{
    case Recorded = 'recorded';

    /** The window is closed — expired, period 0, or cancelled before shipping. */
    case Closed = 'closed';

    /** A position that is not goods of this order, or more than is left. Only a tampered form gets here. */
    case InvalidItems = 'invalidItems';

    case UnknownOrder = 'unknownOrder';
}
