<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

/**
 * What "Kundenkonto anlegen" on the order page answers (K1). One answer for
 * a new account and an existing one (K1-5): the mail says which.
 */
enum AccountRequestOutcome: string
{
    case Requested = 'accountRequested';
    case TooMany = 'accountTooMany';
    case Unavailable = 'accountUnavailable';
}
