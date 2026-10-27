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

        if ($ordinary && $endsAt > 0) {
            return new CancellationResult(CancellationOutcome::AlreadyEnding, subscriptionNumber: $subscriptionNumber, effectiveAt: $endsAt);
        }

        $date = $ordinary ? $this->term->ordinary($subscription, $now, $declaration->requestedAt) : null;
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
            'reason' => $ordinary || '' === trim($declaration->reason) ? null : trim($declaration->reason),
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
            ], $this->links->forMail($order) ?? '');
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The confirmation of cancellation %s could not be sent: %s', $result->number, $e->getMessage()), ['exception' => $e]);

            return false;
        }
    }
}
