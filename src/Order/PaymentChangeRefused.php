<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

final class PaymentChangeRefused extends \RuntimeException
{
    private function __construct(public readonly PaymentChangeBlock $block, string $message)
    {
        parent::__construct($message);
    }

    public static function because(PaymentChangeBlock $block, int $orderId): self
    {
        return new self($block, \sprintf('The payment method of order %d cannot be changed: %s.', $orderId, $block->value));
    }
}
