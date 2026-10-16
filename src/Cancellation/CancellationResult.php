<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

/**
 * What the result page shows (§ 312k Abs. 3: the declaration with the date
 * and time of submission, to be saved by the consumer).
 *
 * `effectiveAt`: the end by this cancellation; for AlreadyEnding the end the
 * subscription already has; null for an extraordinary one, which waits for
 * the operator.
 */
final readonly class CancellationResult
{
    public function __construct(
        public CancellationOutcome $outcome,
        public int|null $id = null,
        public string $number = '',
        public string $subscriptionNumber = '',
        public int|null $receivedAt = null,
        public int|null $effectiveAt = null,
        public bool $earliestApplied = false,
        public string $email = '',
        public bool $confirmed = false,
        public int|null $earliestAt = null,
    ) {
    }
}
