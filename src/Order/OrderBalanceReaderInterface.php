<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

interface OrderBalanceReaderInterface
{
    /**
     * The balance of an order, or null where the order does not exist.
     *
     * @param int|null $asStatus the balance the order would have in this status
     *                           instead of its own — a cancelling status owes
     *                           nothing, which is what a status change has to
     *                           be checked against
     */
    public function of(int $orderId, int|null $asStatus = null): OrderBalance|null;
}
