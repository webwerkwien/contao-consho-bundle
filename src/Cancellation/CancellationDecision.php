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
 * The operator's decision on an extraordinary cancellation (Abo-7, P57).
 *
 * The back-end mask has stored the decision fields by the time this runs
 * (CancellationListener): `state` accepted with `effectiveAt`, or rejected
 * with `rejectReason`. This applies it once — `decidedAt` marks it done —
 * and tells the customer:
 *
 *  - accepted: the subscription ends at that date, or earlier where it
 *    already has an earlier end (an ordinary cancellation next to it);
 *  - rejected: nothing changes; the reason goes to the customer verbatim.
 *
 * An ended subscription only gets the decision recorded. Under the
 * subscription's lock, in a transaction of its own; the mail after the commit.
 */
class CancellationDecision
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLockInterface $lock,
        private readonly OrderEventMail $mail,
        private readonly OrderPageLink $links,
        private readonly SubscriptionContact $contact,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return bool|null null where there was nothing to decide; otherwise whether the customer's mail left
     */
    public function apply(int $cancellationId, int $now): bool|null
    {
        $level = $this->connection->getTransactionNestingLevel();
        $this->connection->beginTransaction();

        try {
            $decided = $this->applyLocked($cancellationId, $now);
            $this->connection->commit();
        } catch (\Throwable $e) {
            while ($this->connection->getTransactionNestingLevel() > $level) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        if (null === $decided) {
            return null;
        }

        $mailed = $this->tell($decided);

        if ($mailed) {
            $this->markSent($cancellationId, $now);
        } else {
            $this->logger->error(\sprintf('The decision on cancellation %s was recorded, but the customer\'s mail did not go out. Please tell the customer by hand.', $decided['number']));
        }

        return $mailed;
    }

    /**
     * "Entscheidung erneut senden" (E10; Michael, 2026-10-08: "E-Punkte
     * passen"): the decision mail once more, for a decision whose mail did
     * not leave (`decisionSentAt` NULL, finding DecisionNotSent). Changes
     * nothing but `decisionSentAt`; the decision itself was applied once.
     *
     * @return bool|null null where there is nothing to resend; otherwise whether the mail left
     */
    public function resend(int $cancellationId, int $now): bool|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT c.*, s.subscriptionNumber FROM tl_consho_cancellation c JOIN tl_consho_subscription s ON s.id = c.pid WHERE c.id = ?',
            [$cancellationId],
        );

        if (false === $row || null === $row['decidedAt'] || null !== $row['decisionSentAt']) {
            return null;
        }

        $accepted = 'accepted' === (string) $row['state'];

        $mailed = $this->tell([
            'subscriptionId' => (int) $row['pid'],
            'number' => (string) $row['number'],
            'subscription' => (string) $row['subscriptionNumber'],
            'accepted' => $accepted,
            'effectiveAt' => $accepted ? (int) $row['effectiveAt'] : null,
            'reason' => $accepted ? '' : trim((string) ($row['rejectReason'] ?? '')),
        ]);

        if ($mailed) {
            $this->markSent($cancellationId, $now);
        }

        return $mailed;
    }

    private function markSent(int $cancellationId, int $now): void
    {
        $this->connection->update('tl_consho_cancellation', ['decisionSentAt' => $now, 'tstamp' => $now], ['id' => $cancellationId]);
    }

    /**
     * Whether a cancellation still waits for the operator.
     *
     * @param array<string, mixed> $row
     */
    public static function isPending(array $row): bool
    {
        return 'extraordinary' === (string) ($row['kind'] ?? '') && null === ($row['decidedAt'] ?? null);
    }

    /**
     * @return array{subscriptionId: int, number: string, subscription: string, accepted: bool, effectiveAt: int|null, reason: string}|null
     */
    private function applyLocked(int $cancellationId, int $now): array|null
    {
        $pid = $this->connection->fetchOne('SELECT pid FROM tl_consho_cancellation WHERE id = ?', [$cancellationId]);

        if (false === $pid) {
            return null;
        }

        $subscription = $this->lock->lock((int) $pid);
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_cancellation WHERE id = ?', [$cancellationId]);

        if (null === $subscription || false === $row || !self::isPending($row)) {
            return null;
        }

        $accepted = 'accepted' === (string) $row['state'];
        $effectiveAt = null === $row['effectiveAt'] ? 0 : (int) $row['effectiveAt'];
        $reason = trim((string) ($row['rejectReason'] ?? ''));

        if ($accepted ? $effectiveAt < 1 : ('rejected' !== (string) $row['state'] || '' === $reason)) {
            return null;
        }

        $this->connection->update('tl_consho_cancellation', ['decidedAt' => $now, 'tstamp' => $now], ['id' => $cancellationId]);

        $endsAt = (int) ($subscription['endsAt'] ?? 0);

        $ended = 'ended' === (string) $subscription['state'];

        if ($accepted && !$ended && (0 === $endsAt || $effectiveAt < $endsAt)) {
            $this->connection->update('tl_consho_subscription', ['endsAt' => $effectiveAt, 'tstamp' => $now], ['id' => (int) $pid]);
        }

        // The end the contract really has (review Abo-7, L3): an earlier one it
        // already had, or the one it ended at. Written back to the
        // cancellation too, so that mail, result page and back end agree
        // (second review, L-2).
        $realEnd = $endsAt > 0 && ($ended || $endsAt < $effectiveAt) ? $endsAt : $effectiveAt;

        if ($accepted && $realEnd !== $effectiveAt) {
            $this->connection->update('tl_consho_cancellation', ['effectiveAt' => $realEnd], ['id' => $cancellationId]);
        }

        return [
            'subscriptionId' => (int) $pid,
            'number' => (string) $row['number'],
            'subscription' => (string) $subscription['subscriptionNumber'],
            'accepted' => $accepted,
            'effectiveAt' => $accepted ? $realEnd : null,
            'reason' => $accepted ? '' : $reason,
        ];
    }

    /**
     * @param array{subscriptionId: int, number: string, subscription: string, accepted: bool, effectiveAt: int|null, reason: string} $decided
     */
    private function tell(array $decided): bool
    {
        try {
            $order = $this->contact->latestOrder($decided['subscriptionId']);

            if (null === $order) {
                return false;
            }

            return $this->mail->cancellationDecided(
                (int) $order['id'],
                $decided['number'],
                $decided['subscription'],
                $decided['accepted'],
                $decided['effectiveAt'],
                $decided['reason'],
                $this->links->forMail($order) ?? '',
            );
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The decision on cancellation %s could not be mailed: %s', $decided['number'], $e->getMessage()), ['exception' => $e]);

            return false;
        }
    }
}
