<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

/**
 * How a withdrawal came in.
 *
 * The two ways through the shop (W1): the order page through its token, and
 * the withdrawal form for a buyer without the mail. And the ways a declaration
 * reaches the operator outside the shop — a withdrawal needs no particular
 * form, any clear statement will do — recorded by hand in the back end.
 */
enum WithdrawalSource: string
{
    case OrderPage = 'orderPage';
    case Form = 'form';
    case Email = 'email';
    case Letter = 'letter';
    case Oral = 'oral';
    case Other = 'other';

    /**
     * @return list<self>
     */
    public static function byHand(): array
    {
        return [self::Email, self::Letter, self::Oral, self::Other];
    }
}
