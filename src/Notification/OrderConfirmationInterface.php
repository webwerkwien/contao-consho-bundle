<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

interface OrderConfirmationInterface
{
    /**
     * Sends the confirmation to the customer and, unless told otherwise, the
     * copy to the operator. Never throws.
     *
     * @param int    $rootId       the start point the order belongs to
     * @param string $viewUrl      the order page, token included
     * @param bool   $copyOperator false for a resend
     *
     * @return bool whether the customer's confirmation left
     */
    public function send(int $orderId, int $rootId, string $viewUrl, int $now, bool $copyOperator = true): bool;
}
