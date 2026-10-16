<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

/**
 * The two ways in (W1): the order page through its token, and the withdrawal
 * form for a buyer without the mail.
 */
enum WithdrawalSource: string
{
    case OrderPage = 'orderPage';
    case Form = 'form';
}
