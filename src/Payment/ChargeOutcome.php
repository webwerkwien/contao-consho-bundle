<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

enum ChargeOutcome: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
}
