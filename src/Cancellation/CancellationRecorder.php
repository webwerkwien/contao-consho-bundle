<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;

/**
 * Records a cancellation and sends the confirmation of receipt (Abo-7; A7,
 * § 312k BGB, P54–P59, P62).
 *
 * ── The record first, the mail after ──────────────────────────────────────
 *
 * Like a withdrawal (WithdrawalRecorder): the row is committed before anything
 * is sent. A mail server that is down leaves `confirmationSentAt` NULL, the
 * back end shows the gap, and the cancellation stands.
 *
 * ── What it does to the subscription ─────────────────────────────────────
 *
 * An ordinary cancellation sets the subscription's end (`endsAt`, P56) to the
 * date CancellationTerm works out. The state is left alone: it carries the
 * payment state (`suspended`, Abo-5), which must go on being assessed until the
 * end. Everything else follows from `endsAt` already — billing stops before it
 * (SubscriptionBiller::isDue), no reminder once it is set
 * (SubscriptionLifecycle::reminderIsDue), and at it the subscription is
 * `ended` (SubscriptionLifecycle::transition, P49).
 *
 * An extraordinary cancellation changes nothing yet: it waits for the
 * operator (P57, CancellationDecision).
 *
 * A subscription with a known end takes a further ordinary cancellation only
 * where it ends earlier — then the end moves forward; otherwise the outcome
 * is AlreadyEnding.
 *
 * The operator's own cancellation (Abo-9, P128) is ordinary, ends exactly at
 * the date he chose and keeps his reason; the customer gets
 * OrderEventMail::cancellationByOperator() instead of a confirmation of
 * receipt.
 *
 * A price offer declined or let lapse (Abo-10, `priceChange`) ends exactly at
 * the offer's effective day, too. Declined, the customer gets the usual
 * confirmation; lapsed, the offer's own mail says it (recordWithin()).
 *
 * ── The number ───────────────────────────────────────────────────────────
 *
 * The subscription number and a count, "SHOP-00001-1-K1" (P59). Counted under
 * the subscription's lock, so two declarations at once get two numbers; the
 * unique key (pid, number) stands behind it.
 */
class CancellationRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLockInterface $lock,
        private readonly CancellationTerm $term,
        private readonly OrderEventMail $mail,
        private readonly OrderPageLink $links,
        private readonly SubscriptionContact $contact,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function record(CancellationDeclaration $declaration, int $now): CancellationResult
    {
        $level = $this->connection->getTransactionNestingLevel();
        $this->connection->beginTransaction();

        try {
            $result = $this->recordLocked($declaration, $now);
            $this->connection->commit();
        } catch (\Throwable $e) {
            while ($this->connection->getTransactionNestingLevel() > $level) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return $this->confirmRecorded($declaration, $result);
    }

    /**
     * The confirmation of a cancellation recorded with recordWithin(), after
     * the caller's commit — what record() does after its own.
     */
    public function confirmRecorded(CancellationDeclaration $declaration, CancellationResult $result): CancellationResult
    {
        if (CancellationOutcome::Recorded !== $result->outcome || null === $result->id) {
            return $result;
        }

        $confirmed = $this->confirm($declaration, $result);

        if ($confirmed) {
            $this->connection->update('tl_consho_cancellation', ['confirmationSentAt' => time()], ['id' => $result->id]);
        } else {
            $this->logger->error(\sprintf('Cancellation %s was recorded, but its confirmation of receipt did not go out. It has to be confirmed by hand.', $result->number));
        }

        return new CancellationResult(
            $result->outcome,
            $result->id,
            $result->number,
            $result->subscriptionNumber,
            $result->receivedAt,
            $result->effectiveAt,
            $result->earliestApplied,
            $result->email,
            $confirmed,
            $result->earliestAt,
        );
    }

    /**
     * The record alone, inside the caller's transaction and without a mail:
     * a price offer that lapsed (Abo-10, P122) ends the subscription in the
     * same commit as the offer, and its own mail tells the customer.
     */
    public function recordWithin(CancellationDeclaration $declaration, int $now): CancellationResult
    {
        if (0 === $this->connection->getTransactionNestingLevel()) {
            throw new \LogicException('recordWithin() needs the caller\'s transaction.');
        }

        return $this->recordLocked($declaration, $now);
    }

    private function recordLocked(CancellationDeclaration $declaration, int $now): CancellationResult
    {
        $subscription = $this->lock->lock($declaration->subscriptionId);

        if (null === $subscription) {
            return new CancellationResult(CancellationOutcome::UnknownSubscription);
        }

        $subscriptionNumber = (string) $subscription['subscriptionNumber'];
        $endsAt = (int) ($subscription['endsAt'] ?? 0);

        if ('ended' === (string) $subscription['state']) {
            return new CancellationResult(CancellationOutcome::Ended, subscriptionNumber: $subscriptionNumber, effectiveAt: $endsAt > 0 ? $endsAt : null);
        }

        $ordinary = CancellationKind::Ordinary === $declaration->kind;
        $operator = CancellationSource::Operator === $declaration->source;
        // A price offer declined, or let lapse by a consumer (Abo-10, P121,
        // P122): ordinary, at the offer's effective day.
        $priceChange = CancellationSource::PriceChange === $declaration->source;

        // A known end stands, unless the customer's own ordinary cancellation
        // ends the contract earlier: an end set by someone else — the
        // operator's, with his longer notice (Abo-9) — must not leave him
        // paying periods his cancellation would have spared. Then it is
        // recorded and moves the end forward, as an accepted extraordinary
        // one does (CancellationDecision). The same for a declined price: the
        // periods after the effective day would be at the price he declined.
        // Not once renewed: the successor starts at the known end (P68), an
        // earlier end would leave a gap. Not for the same day either — a date
        // read as midnight would move the end by hours and spare nothing.
        if ($ordinary && $endsAt > 0 && ($operator || (int) ($subscription['renewedBy'] ?? 0) > 0 || !$this->term->endsEarlier(
            $priceChange ? (int) $declaration->requestedAt : $this->term->ordinary($subscription, $now, $declaration->requestedAt)->effectiveAt,
            $endsAt,
        ))) {
            return new CancellationResult(CancellationOutcome::AlreadyEnding, subscriptionNumber: $subscriptionNumber, effectiveAt: $endsAt);
        }

        // The operator's date is the end itself (Abo-9, P128): his notice is
        // a matter of his terms, not of the customer's earliest end.
        if ($operator && (!$ordinary || null === $declaration->requestedAt || $declaration->requestedAt <= $now)) {
            throw new \InvalidArgumentException('The operator cancels ordinarily, at a date after now.');
        }

        // The effective day is the end itself; it may have come already where
        // the notice is nil and the answer period ran out on that very day.
        if ($priceChange && (!$ordinary || null === $declaration->requestedAt || $declaration->requestedAt <= 0)) {
            throw new \InvalidArgumentException('A price change cancels ordinarily, at the effective day of the offer.');
        }

        $date = match (true) {
            !$ordinary => null,
            $operator, $priceChange => new CancellationDate((int) $declaration->requestedAt, false, (int) $declaration->requestedAt),
            default => $this->term->ordinary($subscription, $now, $declaration->requestedAt),
        };
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation WHERE pid = ?', [$declaration->subscriptionId]);
        $number = \sprintf('%s-K%d', $subscriptionNumber, $count + 1);
        $email = (string) ($this->contact->latestOrder($declaration->subscriptionId)['email'] ?? '');

        $this->connection->insert('tl_consho_cancellation', [
            'pid' => $declaration->subscriptionId,
            'tstamp' => $now,
            'number' => $number,
            'receivedAt' => $now,
            'source' => $declaration->source->value,
            'kind' => $declaration->kind->value,
            'reason' => ($ordinary && !$operator) || '' === trim($declaration->reason) ? null : trim($declaration->reason),
            'name' => trim($declaration->name),
            'email' => $email,
            'requestedAt' => $declaration->requestedAt,
            'effectiveAt' => $date?->effectiveAt,
            'earliestAt' => $date?->earliestAt,
            'earliestApplied' => $date?->earliestApplied ? 1 : 0,
            'state' => $ordinary ? 'effective' : 'received',
        ]);

        $id = (int) $this->connection->lastInsertId();

        if (null !== $date) {
            $this->connection->update('tl_consho_subscription', ['endsAt' => $date->effectiveAt, 'tstamp' => $now], ['id' => $declaration->subscriptionId]);
        }

        return new CancellationResult(
            CancellationOutcome::Recorded,
            $id,
            $number,
            $subscriptionNumber,
            $now,
            $date?->effectiveAt,
            $date->earliestApplied ?? false,
            $email,
            false,
            $date?->earliestAt,
        );
    }

    private function confirm(CancellationDeclaration $declaration, CancellationResult $result): bool
    {
        try {
            $order = $this->contact->latestOrder($declaration->subscriptionId);

            if (null === $order) {
                return false;
            }

            $product = (string) $this->connection->fetchOne(
                'SELECT i.productName FROM tl_consho_order_item i JOIN tl_consho_subscription s ON s.orderItem = i.id WHERE s.id = ?',
                [$declaration->subscriptionId],
            );

            if (CancellationSource::Operator === $declaration->source) {
                return $this->mail->cancellationByOperator((int) $order['id'], [
                    'number' => (string) $result->number,
                    'subscription' => (string) $result->subscriptionNumber,
                    'product' => $product,
                    'effectiveAt' => (int) $result->effectiveAt,
                    'reason' => trim($declaration->reason),
                    'renewalHint' => $declaration->renewalHint,
                ], $this->links->forMail($order) ?? '');
            }

            return $this->mail->cancellationReceived((int) $order['id'], [
                'number' => $result->number,
                'subscription' => $result->subscriptionNumber,
                'product' => $product,
                'receivedAt' => (int) $result->receivedAt,
                'kind' => $declaration->kind->value,
                'reason' => trim($declaration->reason),
                'name' => trim($declaration->name),
                'email' => $result->email,
                'requestedAt' => $declaration->requestedAt,
                'effectiveAt' => $result->effectiveAt,
                'earliestApplied' => $result->earliestApplied,
                'earliestAt' => $result->earliestAt,
                'priceChange' => CancellationSource::PriceChange === $declaration->source,
            ], $this->links->forMail($order) ?? '');
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The confirmation of cancellation %s could not be sent: %s', $result->number, $e->getMessage()), ['exception' => $e]);

            return false;
        }
    }
}
