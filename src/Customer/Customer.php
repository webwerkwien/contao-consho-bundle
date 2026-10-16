<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

/**
 * One line of the customer list (D-4, K2).
 */
final class Customer
{
    /**
     * @param string $key "m:<member id>" or "g:<address in lower case>" — stable per shop, for the detail link
     */
    public function __construct(
        public readonly string $key,
        public readonly int $shopId,
        public readonly string $shopTitle,
        public readonly int|null $memberId,
        public readonly string $name,
        public readonly string $email,
        public readonly int $orders,
        public readonly int|null $lastOrderAt,
        public readonly bool $alsoGuest,
    ) {
    }
}
