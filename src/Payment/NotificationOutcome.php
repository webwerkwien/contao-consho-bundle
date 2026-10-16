<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

enum NotificationOutcome: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
