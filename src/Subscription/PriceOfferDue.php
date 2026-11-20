<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * A price offer whose mail is due (Abo-10): what SubscriptionPriceOffers
 * hands to the run after its commit.
 *
 * @see \Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail::PRICE_OFFER_EVENTS for $event
 */
final class PriceOfferDue
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $subscriptionId,
        public readonly string $subscriptionNumber,
        public readonly string $event,
        public readonly string $oldUnitNet,
        public readonly string $newUnitNet,
        public readonly int $effectiveAt,
        public readonly int $deadlineAt,
    ) {
    }
}
