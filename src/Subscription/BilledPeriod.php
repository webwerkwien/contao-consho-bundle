<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * One period billed (Abo-4): the follow-up order and the period it covers.
 */
final class BilledPeriod
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $subscriptionNumber,
        public readonly int $orderId,
        public readonly int $periodFrom,
        public readonly int $periodUntil,
        /** Billed with the method of its mandate, to be charged now (Abo-4b, P94). */
        public readonly bool $charged = false,
    ) {
    }
}
