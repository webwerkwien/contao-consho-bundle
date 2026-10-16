<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

interface OrderFactoryInterface
{
    /**
     * @param list<OrderLineDraft> $lines
     *
     * @return int the new order's id
     *
     * @throws ProductGoneFromCatalogue
     */
    public function create(OrderDraft $draft, array $lines, int $timestamp): int;
}
