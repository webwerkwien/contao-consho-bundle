<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

/**
 * § 312k Abs. 2 Nr. 1 a BGB: the consumer says which kind of cancellation.
 *
 * - Ordinary: to the earliest possible date or a later one, worked out from
 *   the frozen rule (P54); it takes effect by itself.
 * - Extraordinary: for a reason; recorded, but it takes effect only when the
 *   operator accepts it (P57).
 */
enum CancellationKind: string
{
    case Ordinary = 'ordinary';
    case Extraordinary = 'extraordinary';
}
