<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

final class PaymentChangeOptions
{
    /**
     * @param list<PaymentChangeOption> $options empty when blocked
     */
    public function __construct(
        public readonly PaymentChangeBlock|null $block,
        public readonly array $options = [],
    ) {
    }

    /**
     * Whether the order page offers a change at all: not blocked, and at
     * least one OTHER method that can serve the order.
     */
    public function offersChange(): bool
    {
        if (null !== $this->block) {
            return false;
        }

        foreach ($this->options as $option) {
            if ($option->available) {
                return true;
            }
        }

        return false;
    }

    public function find(int $methodId): PaymentChangeOption|null
    {
        foreach ($this->options as $option) {
            if ($option->method->id === $methodId) {
                return $option;
            }
        }

        return null;
    }
}
