<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

/**
 * When an ordinary cancellation ends the contract (Abo-7, P54).
 *
 * `earliestApplied`: the customer asked for an earlier date than possible;
 * the confirmation has to say that it ends later.
 *
 * `earliestAt`: the earliest possible end. Where the contract ends later (a
 * later date asked for), periods that begin before it are still billed in
 * full (P55) — the result page and the mail say so (review Abo-7, S1).
 */
final readonly class CancellationDate
{
    public function __construct(
        public int $effectiveAt,
        public bool $earliestApplied,
        public int $earliestAt,
    ) {
    }
}
