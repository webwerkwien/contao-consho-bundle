<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

interface OrderWriterInterface
{
    /**
     * @param array<string, mixed> $values
     *
     * @return int the new order's id
     */
    public function createOrder(array $values): int;

    /**
     * @param array<string, mixed> $values
     */
    public function createItem(array $values): void;
}
