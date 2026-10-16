<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Webwerkwien\ContaoConshoBundle\Checkout\LegalText;

interface OrderLegalWriterInterface
{
    /**
     * Freezes one legal text onto an order (C-17).
     *
     * Write only. There is no read-back here on purpose: the back end reads
     * the rows through its own DCA, and a method that could load them would
     * invite code to compare a frozen text against the current element —
     * which is the comparison that makes a frozen text worthless.
     */
    public function freeze(int $orderId, LegalText $text, int $capturedAt): void;
}
