<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * What happens at the end of a subscription's minimum term (Abo, A3).
 *
 * - Ends: the subscription runs out; a reminder offers a new purchase.
 * - SameTerm: it renews for another term unless cancelled in time — offered
 *   to business customers only, § 309 Nr. 9 BGB does not bind them (§ 310).
 * - Indefinite: it goes on for an indefinite time and can be cancelled at any
 *   moment with at most one month's notice (§ 309 Nr. 9 b BGB).
 *
 * Block Abo, sections 4.2 and 5.
 */
enum RenewalKind: string
{
    case Ends = 'ends';
    case SameTerm = 'sameTerm';
    case Indefinite = 'indefinite';

    public function renews(): bool
    {
        return self::Ends !== $this;
    }
}
