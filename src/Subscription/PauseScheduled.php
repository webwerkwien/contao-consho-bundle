<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * A pause announced (Abo pausieren): what the confirmation names.
 */
final class PauseScheduled
{
    public function __construct(
        public readonly int $pauseId,
        public readonly int $subscriptionId,
        public readonly string $subscriptionNumber,
        public readonly int $months,
        public readonly int $from,
        public readonly int $until,
    ) {
    }
}
