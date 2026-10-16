<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * What SubscriptionLifecycle::transition() did to a subscription (Abo-6).
 */
enum LifecycleChange: string
{
    // The subscription is over (P44, P48, P49; an open-ended rule from before
    // variant A billed otherwise than monthly).
    case Ended = 'ended';

    // A same-term renewal: the minimum term moved on (P45).
    case Renewed = 'renewed';

    // Open-ended from now on, billed monthly (P46).
    case OpenEnded = 'openEnded';
}
