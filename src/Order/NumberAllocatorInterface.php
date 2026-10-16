<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The seam that lets a caller be tested against the range it draws from.
 *
 * NumberRangeAllocator is final — deliberately, because its correctness rests
 * on a row lock inside a transaction and a subclass could quietly take that
 * apart. A final class cannot be mocked, so anything that has to prove WHICH
 * range it asked for needs this interface to assert against.
 *
 * That is not a hypothetical requirement here: an invoice and a delivery note
 * must never share a sequence, and the only way to show that in a test is to
 * watch the call.
 */
interface NumberAllocatorInterface
{
    /**
     * Allocates the next number of a range and advances it.
     *
     * @param string $type the range type, e.g. "order", "invoice", "deliveryNote"
     *
     * @throws NumberRangeNotConfigured if the shop has no range of that type
     */
    public function next(int $shopId, string $type): string;
}
