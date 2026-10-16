<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

interface OrderStatusWriterInterface
{
    /**
     * Sets the status of an order the way the back end would — with a
     * version in tl_version — and records a cancellation on the order.
     */
    public function write(int $orderId, StatusFlags $status, int $now): void;
}
