<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

enum CancellationOutcome: string
{
    case Recorded = 'recorded';

    /** An ordinary cancellation of a subscription that already has an end — it ends then anyway. */
    case AlreadyEnding = 'alreadyEnding';

    /** The subscription has ended; there is nothing left to cancel. */
    case Ended = 'ended';

    case UnknownSubscription = 'unknownSubscription';
}
