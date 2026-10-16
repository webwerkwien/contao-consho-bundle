<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

enum PaymentStartOutcome: string
{
    case Completed = 'completed';
    case Redirect = 'redirect';
    case Failed = 'failed';
}
