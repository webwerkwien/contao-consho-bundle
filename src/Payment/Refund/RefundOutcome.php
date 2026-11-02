<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

enum RefundOutcome: string
{
    case Done = 'done';
    case Pending = 'pending';
    case Failed = 'failed';
}
