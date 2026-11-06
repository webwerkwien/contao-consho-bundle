<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The earlier name of OrderCredit, kept so code that builds it by name keeps
 * working. New code asks for OrderCredit.
 */
class WithdrawalCredit extends OrderCredit
{
}
