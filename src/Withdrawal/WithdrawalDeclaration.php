<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

/**
 * What a customer declared, before it is recorded (D-2, spec 6.1 step 2).
 *
 * The scope follows from what was filled in: positions (order page) make it
 * `items`, a free-text part (form, E6) makes it `part`, neither makes it the
 * whole order (W3: whole order as the default).
 */
final class WithdrawalDeclaration
{
    /**
     * @param array<int, string> $items order item id => quantity, from the order page
     */
    public function __construct(
        public readonly int $orderId,
        public readonly WithdrawalSource $source,
        public readonly string $name,
        public readonly array $items = [],
        public readonly string $part = '',
        public readonly string $message = '',
    ) {
    }

    public function scope(): string
    {
        return match (true) {
            [] !== $this->items => 'items',
            '' !== trim($this->part) => 'part',
            default => 'all',
        };
    }
}
