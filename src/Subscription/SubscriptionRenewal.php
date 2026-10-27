<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Cart\CartItemRow;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;

/**
 * "Lizenz verlängern" (Abo-7b; A11, P66–P70): a new
 * subscription at today's conditions that carries the old one's entitlement
 * and keys on — no new key to enter.
 *
 * The existing licence is extended, so no new key has to be entered, but
 * as a new subscription at the current conditions. The check comes with the
 * link already: nothing is offered that does not exist anyway.
 *
 * renewable() is the contract side (P66): only a subscription that ends, not
 * resting, nothing overdue, not renewed already. offer() is the shop side: the
 * same plan, still offered to this customer, with a price — priced by the
 * basket's own pricer, so the amount next to the link is the amount in the
 * basket. Both are asked again at the click, and the purchase asks the
 * contract side once more under the lock (SubscriptionRecorder).
 *
 * Not final: MySubscriptionsFlow's test stands a double in for it.
 */
class SubscriptionRenewal
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CartPricer $pricer,
        private readonly SubscriptionOffers $offers,
        private readonly SubscriptionLineTerms $lineTerms,
        private readonly SubscriptionDelinquency $delinquency,
    ) {
    }

    /**
     * When the subscription ends: a cancellation's or a reached end
     * (`endsAt`), else the end of a minimum term that does not renew; 0 while
     * it runs on.
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public static function endOf(array $row): int
    {
        $endsAt = (int) ($row['endsAt'] ?? 0);

        if ($endsAt > 0) {
            return $endsAt;
        }

        return RenewalKind::Ends->value === (string) $row['renewal'] ? (int) $row['minimumEndsAt'] : 0;
    }

    /**
     * Where the old licence really ends, and so where a renewal starts (P68:
     * "keine Doppelzahlung, keine Lücke"): not the contract's end itself, but
     * the end of the period that covers it — P55 bills a period that begins
     * before the end in full, and the entitlement runs to its end. A period
     * already billed beyond the end (an extraordinary cancellation in the
     * middle of it) counts as it stands; one not billed yet is the first
     * boundary at or after the end, from the start like every boundary (P17).
     * 0 for a subscription that does not end.
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public static function licenceEndOf(array $row, SubscriptionCalendar $calendar): int
    {
        $end = self::endOf($row);

        if ($end <= 0) {
            return 0;
        }

        $paidUntil = (int) ($row['periodUntil'] ?? 0);

        if ($paidUntil >= $end) {
            return $paidUntil;
        }

        $months = SubscriptionBiller::periodMonths($row);

        // A broken row (no interval, no term) ends where its contract does.
        // Boundaries moved by the months paused (P104).
        for ($k = 1; $months > 0 && $k <= 1200; ++$k) {
            $boundary = $calendar->boundary($row, $k * $months);

            if ($boundary >= $end) {
                return $boundary;
            }
        }

        return $end;
    }

    /**
     * The numbers of subscriptions, for the lines that renew them.
     *
     * @param list<int> $ids
     *
     * @return array<int, string> id => subscription number
     */
    public function numbersOf(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ([] === $ids) {
            return [];
        }

        /** @var array<int, string> */
        return $this->connection->fetchAllKeyValue(
            'SELECT id, subscriptionNumber FROM tl_consho_subscription WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
    }

    /**
     * The checkout's question for a renewal line (P70): still this member's,
     * in this shop, of this article — and renewable().
     */
    public function allowsPurchase(int $subscriptionId, int $memberId, int $shopId, int $articleId, int $now): bool
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);

        return false !== $row
            && $memberId > 0
            && (int) $row['member'] === $memberId
            && (int) $row['shop'] === $shopId
            && (int) $row['product'] === $articleId
            && $this->renewable($row, $now);
    }

    /**
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public function renewable(array $row, int $now): bool
    {
        // Not while paused (P106): its licence ends with the last paid period,
        // the renewal would start in the middle of the pause.
        if ((int) ($row['renewedBy'] ?? 0) > 0 || \in_array((string) $row['state'], ['suspended', 'paused'], true)) {
            return false;
        }

        if ('ended' !== (string) $row['state'] && 0 === self::endOf($row)) {
            return false;
        }

        // Its purchase cancelled — a rolled-back renewal, say: it never held
        // a licence to carry on (Abo-7b: the button
        // stood there beside the one of the subscription it had renewed).
        $cancelled = $this->connection->fetchOne('SELECT cancelledAt FROM tl_consho_order WHERE id = ?', [(int) $row['firstOrder']]);

        if (false !== $cancelled && null !== $cancelled) {
            return false;
        }

        // Resting: an ended subscription can still have a resting entitlement
        // (P64) — that is an unpaid invoice, whatever the state says.
        $resting = $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_entitlement WHERE subscription = ? AND suspendedAt IS NOT NULL LIMIT 1',
            [(int) $row['id']],
        );

        return false === $resting && !$this->delinquency->hasOverdue($row, $now);
    }

    /**
     * @param array<string, mixed> $row a row of tl_consho_subscription
     *
     * @return RenewalOffer|null null where the plan is not offered to this customer any more, or has no price
     */
    public function offer(array $row, RenewalContext $context, string $language, int $now): RenewalOffer|null
    {
        $articleId = (int) $row['product'];
        $ruleId = (int) $row['rule'];
        $quantity = (string) $row['quantity'];

        // A renewal in the currency the contract was made in; another start
        // point of the same shop prices in another one.
        if ('' === $context->cartUrl || (string) $row['currency'] !== $context->currency) {
            return null;
        }

        // Offered to the customer as they are TODAY — their groups, the way
        // the product page asks (Abo-7b: the frozen flag of the old
        // contract offered or hid it wrongly).
        if (!$this->offers->allows($articleId, $ruleId, $context->groups)) {
            return null;
        }

        $view = $this->pricer->price(
            [new CartItemRow(0, $articleId, $quantity, $now, $ruleId, (int) $row['id'])],
            $context->currency,
            $context->country,
            $context->country,
            $now,
            $context->groups,
        );
        $line = $view->lines[0] ?? null;

        if (null === $line) {
            return null;
        }

        $terms = $this->lineTerms->of($ruleId, $context->showsNet ? $line->lineNet : $line->lineGross, $context->currency, $language);

        return null === $terms ? null : new RenewalOffer((int) $row['id'], $articleId, $ruleId, $quantity, $terms);
    }
}
