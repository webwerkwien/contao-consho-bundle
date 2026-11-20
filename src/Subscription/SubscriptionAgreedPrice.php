<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The price a subscription has agreed to and not yet billed (Abo-10, P120).
 *
 * An accepted offer — by the customer, by a business customer's silence
 * (P122), or a decrease (P116) — counts from its effective day. The biller
 * asks for it under the subscription's lock, before it drafts the follow-up
 * order of the first period beginning on that day or later, and marks it
 * applied in the same transaction: the order line, the invoice and a charge
 * via the mandate carry the new price, and so does the subscription from then
 * on (`unitNet` and the conditions text).
 *
 * Only an offer whose mail went out counts (P135). Where several wait, the
 * latest effective one wins; the others are marked applied with it, as a
 * later offer always rests on the price of the earlier one.
 */
class SubscriptionAgreedPrice
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLineTerms $lineTerms,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The newest accepted, unapplied offer that is effective at a day.
     *
     * @param array<string, mixed> $subscription a row of tl_consho_subscription
     *
     * @return array{id: int, newUnitNet: string, effectiveAt: int}|null
     */
    public function agreed(array $subscription, int $at): array|null
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, newUnitNet, effectiveAt FROM tl_consho_subscription_price
              WHERE pid = ? AND state = 'accepted' AND appliedAt = 0 AND mailSentAt > 0 AND effectiveAt <= ?
              ORDER BY effectiveAt DESC, id DESC LIMIT 1",
            [(int) $subscription['id'], $at],
        );

        return false === $row ? null : ['id' => (int) $row['id'], 'newUnitNet' => (string) $row['newUnitNet'], 'effectiveAt' => (int) $row['effectiveAt']];
    }

    /**
     * What the period beginning at `$periodFrom` bills: the agreed net per
     * unit and the conditions text with the new amount — null where the
     * frozen price stands.
     *
     * The text is built as at the purchase (SubscriptionLineTerms, OrderFactory):
     * net with reverse charge, else gross at the rate of the newest bill.
     * A rule that no longer exists keeps the
     * old text; the log says so, the price changes all the same.
     *
     * @param array<string, mixed> $subscription a row of tl_consho_subscription, locked
     *
     * @return array{id: int, unitNet: string, terms: string}|null
     */
    public function due(array $subscription, int $periodFrom, string $language): array|null
    {
        $agreed = $this->agreed($subscription, $periodFrom);

        if (null === $agreed) {
            return null;
        }

        // As OrderFactory builds it at the purchase: net only with reverse
        // charge, gross otherwise — a business customer taxed at home included.
        $rate = (bool) $subscription['reverseCharge'] ? '0' : SubscriptionPriceOfferRun::taxRateOf($this->connection, $subscription);
        $amount = SubscriptionPriceOfferRun::periodAmount($agreed['newUnitNet'], (string) $subscription['quantity'], $rate);
        $terms = $this->lineTerms->of((int) $subscription['rule'], $amount, (string) $subscription['currency'], $language);

        if (null === $terms) {
            $this->logger->warning(\sprintf('Subscription %s takes on its new price with the conditions text of the purchase: its subscription rule no longer exists.', $subscription['subscriptionNumber']));
            $terms = (string) $subscription['terms'];
        }

        return ['id' => $agreed['id'], 'unitNet' => $agreed['newUnitNet'], 'terms' => $terms];
    }

    /**
     * The price taken on: onto the subscription, and every accepted offer up
     * to the applied one marked — inside the biller's transaction.
     *
     * @param array<string, mixed>                           $subscription
     * @param array{id: int, unitNet: string, terms: string} $due
     */
    public function apply(array $subscription, array $due, int $periodFrom, int $now): void
    {
        $this->connection->update('tl_consho_subscription', [
            'tstamp' => $now,
            'unitNet' => $due['unitNet'],
            'terms' => $due['terms'],
        ], ['id' => (int) $subscription['id']]);

        $this->connection->executeStatement(
            "UPDATE tl_consho_subscription_price SET appliedAt = ?, tstamp = ?
              WHERE pid = ? AND state = 'accepted' AND appliedAt = 0 AND mailSentAt > 0 AND effectiveAt <= ?",
            [$now, $now, (int) $subscription['id'], $periodFrom],
        );
    }
}
