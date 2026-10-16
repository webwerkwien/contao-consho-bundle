<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

/**
 * Why a withdrawal window is what it is — the order page says different
 * things for each (WithdrawalWindow).
 */
enum WindowState: string
{
    /** Open, until a date or — goods still unshipped — without one yet. */
    case Open = 'open';

    /** The shop has no withdrawal function (period 0). */
    case Off = 'off';

    /** Cancelled before anything shipped: nothing to withdraw from (E7). */
    case Cancelled = 'cancelled';
}
