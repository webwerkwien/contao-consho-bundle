<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * The state of a payment attempt — the same values as
 * tl_consho_payment_attempt.state (spec 5.1, table from C-7).
 *
 * handleReturn() answers with it for display only and never returns Open:
 * a browser coming back has at least seen the provider. Money is recorded
 * from notifications alone (spec 6.2).
 */
enum AttemptState: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
