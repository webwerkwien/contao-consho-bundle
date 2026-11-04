<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

enum NotificationOutcome: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Ignored = 'ignored';
    // Under way at the provider, neither paid nor failed yet: nothing is
    // booked, and an attempt the provider still works on is not cancelled.
    case Pending = 'pending';
}
