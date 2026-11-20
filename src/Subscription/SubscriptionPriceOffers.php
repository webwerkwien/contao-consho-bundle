<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDeclaration;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationKind;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationOutcome;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource;

/**
 * The price offers of the subscriptions (Abo-10): made, and later answered,
 * under the subscription's lock, each in a transaction of its own like the
 * biller. The mails are the caller's, after the commit (SubscriptionPriceOfferRun).
 *
 * prepare(): when the lead time before the next effective day is reached
 * (P114, P127) and today's price differs from the frozen one (P115, P136),
 * one row for that day (P124): an increase `open`, a decrease `accepted`
 * right away (P116). Not while another offer still waits for its day — a
 * price that changes again goes into the next round (P124), and the frozen
 * price is not the one to compare with while an agreed one is still to come.
 *
 * An offer counts only once its mail is out (P135): withdraw() takes back one
 * whose mail did not leave, and the next run makes it anew.
 *
 * Then: void() where the subscription ends first (P124), remind() seven days
 * before the deadline (P118), lapse() at the deadline without an answer
 * (P122).
 */
class SubscriptionPriceOffers
{
    /** After this long an offer without its mail mark counts as abandoned. */
    public const STALE_SECONDS = 3600;

    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLockInterface $lock,
        private readonly SubscriptionPriceCheck $check,
        private readonly CancellationRecorder $recorder,
    ) {
    }

    public function prepare(int $subscriptionId, int $now): PriceOfferDue|null
    {
        return $this->locked(function () use ($subscriptionId, $now): PriceOfferDue|null {
            $row = $this->lock->lock($subscriptionId);

            /*
             * An offer whose mail was never marked — the run died between the
             * commit and the mail, or marking it failed — was never made
             * (P135). Left alone it would block every later offer. Only one
             * older than an hour: a second run may be sending it right now.
             */
            $this->connection->executeStatement(
                'DELETE FROM tl_consho_subscription_price WHERE pid = ? AND mailSentAt = 0 AND createdAt < ?',
                [$subscriptionId, $now - self::STALE_SECONDS],
            );

            if (null === $row || !SubscriptionPriceCheck::candidate($row) || $this->waiting($subscriptionId)) {
                return null;
            }

            $effectiveAt = $this->check->effectiveDay($row, $this->check->leadDays($row), $now);

            if (null === $effectiveAt || false !== $this->connection->fetchOne('SELECT id FROM tl_consho_subscription_price WHERE pid = ? AND effectiveAt = ?', [$subscriptionId, $effectiveAt])) {
                return null;
            }

            $old = (string) $row['unitNet'];
            $new = $this->check->currentUnitNet($row, $now);

            if (null === $new || !SubscriptionPriceCheck::changed($old, $new, (string) $row['currency'])) {
                return null;
            }

            $decrease = BigDecimal::of($new)->isLessThan($old);
            $deadlineAt = $this->check->deadline($row, $effectiveAt);

            $this->connection->insert('tl_consho_subscription_price', [
                'pid' => $subscriptionId,
                'tstamp' => $now,
                'createdAt' => $now,
                'effectiveAt' => $effectiveAt,
                'deadlineAt' => $deadlineAt,
                'oldUnitNet' => $old,
                'newUnitNet' => $new,
                'currency' => (string) $row['currency'],
                'quantity' => (string) $row['quantity'],
                'decrease' => $decrease ? 1 : 0,
                'state' => $decrease ? 'accepted' : 'open',
            ]);

            return new PriceOfferDue(
                (int) $this->connection->lastInsertId(),
                $subscriptionId,
                (string) $row['subscriptionNumber'],
                $decrease ? 'decrease' : 'offer',
                $old,
                $new,
                $effectiveAt,
                $deadlineAt,
            );
        });
    }

    /**
     * The mail is out. No row to mark means another run took it as abandoned
     * (STALE_SECONDS) and made the offer anew — the customer then has two
     * mails; the caller logs it.
     */
    public function sent(int $offerId, int $now): void
    {
        if (0 === (int) $this->connection->update('tl_consho_subscription_price', ['mailSentAt' => $now, 'tstamp' => $now], ['id' => $offerId, 'mailSentAt' => 0])) {
            throw new \RuntimeException(\sprintf('Price offer %d no longer waits for its mail mark; another run made it anew.', $offerId));
        }
    }

    /**
     * P135: an offer whose mail did not leave was never made.
     */
    public function withdraw(int $offerId): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_subscription_price WHERE id = ? AND mailSentAt = 0', [$offerId]);
    }

    /**
     * P124: an offer not settled yet whose subscription ends before its
     * effective day — cancelled, revoked, ended, renewed — is void.
     *
     * @return string|null the subscription number, where the offer became void
     *                     — of a deleted subscription its former id
     */
    public function void(int $offerId, int $now): string|null
    {
        return $this->locked(function () use ($offerId, $now): string|null {
            [$offer, $row] = $this->lockOffer($offerId);

            if (null === $offer || !self::unsettled($offer)) {
                return null;
            }

            if (null !== $row && !self::ends($row, (int) $offer['effectiveAt'])) {
                return null;
            }

            $this->connection->update('tl_consho_subscription_price', ['state' => 'void', 'tstamp' => $now], ['id' => $offerId]);

            return null === $row ? \sprintf('ID %d', (int) $offer['pid']) : (string) $row['subscriptionNumber'];
        });
    }

    /**
     * P118: seven days before the deadline, an offer still unanswered is
     * reminded of — once per deadline. A reminder sent before the current
     * deadline's reminder day was one for an earlier deadline (a pause moved
     * it): it does not count. A pause withdrawn moves the deadline back, and
     * the reminder sent for it counts again. A reminder whose mail did not
     * leave is tried again (unremind()).
     */
    public function remind(int $offerId, int $now): PriceOfferDue|null
    {
        return $this->locked(function () use ($offerId, $now): PriceOfferDue|null {
            [$offer, $row] = $this->lockOffer($offerId);

            if (null === $offer || 'open' !== $offer['state'] || (int) $offer['mailSentAt'] < 1) {
                return null;
            }

            $deadlineAt = (int) $offer['deadlineAt'];
            $reminderFrom = $this->check->reminderFrom($deadlineAt);

            if ($now >= $deadlineAt || $now < $reminderFrom || (int) $offer['reminderSentAt'] >= $reminderFrom) {
                return null;
            }

            if (null === $row || self::ends($row, (int) $offer['effectiveAt'])) {
                return null;
            }

            $this->connection->update('tl_consho_subscription_price', ['reminderSentAt' => $now, 'tstamp' => $now], ['id' => $offerId]);

            return self::due($offer, $row, 'reminder');
        });
    }

    public function unremind(int $offerId): void
    {
        $this->connection->update('tl_consho_subscription_price', ['reminderSentAt' => 0], ['id' => $offerId]);
    }

    /**
     * P122: no answer by the deadline. A business customer's silence is
     * consent; a consumer's subscription ends at the effective day — a
     * cancellation from `priceChange`, in this same commit. Not to be
     * switched off.
     */
    public function lapse(int $offerId, int $now): PriceOfferDue|null
    {
        return $this->locked(function () use ($offerId, $now): PriceOfferDue|null {
            [$offer, $row] = $this->lockOffer($offerId);

            if (null === $offer || 'open' !== $offer['state'] || (int) $offer['mailSentAt'] < 1 || $now < (int) $offer['deadlineAt']) {
                return null;
            }

            if (null === $row || self::ends($row, (int) $offer['effectiveAt'])) {
                return null;
            }

            $answer = ['answeredAt' => $now, 'answeredBy' => 'silence', 'tstamp' => $now];

            if ((bool) $row['businessCustomer']) {
                $this->connection->update('tl_consho_subscription_price', ['state' => 'accepted'] + $answer, ['id' => $offerId]);

                return self::due($offer, $row, 'lapsedBusiness');
            }

            $result = $this->recorder->recordWithin(new CancellationDeclaration(
                (int) $offer['pid'],
                CancellationSource::PriceChange,
                CancellationKind::Ordinary,
                '',
                (int) $offer['effectiveAt'],
            ), $now);

            if (CancellationOutcome::Recorded !== $result->outcome) {
                // Ending already, on the day or before: nothing left to decide.
                $this->connection->update('tl_consho_subscription_price', ['state' => 'void', 'tstamp' => $now], ['id' => $offerId]);

                return null;
            }

            $this->connection->update('tl_consho_subscription_price', ['state' => 'lapsed', 'cancellation' => (int) $result->id] + $answer, ['id' => $offerId]);

            return self::due($offer, $row, 'lapsedConsumer');
        });
    }

    /**
     * P119: the customer's answer under "Meine Abos", until the deadline —
     * final. Agreed, the new price applies from the effective day (P120);
     * declined, the subscription ends there: an ordinary cancellation from
     * `priceChange` in this same commit (P121).
     *
     * @param string $name the account's name, for the cancellation
     */
    public function answer(int $offerId, int $subscriptionId, bool $accept, string $name, int $now): PriceOfferAnswered|null
    {
        return $this->locked(function () use ($offerId, $subscriptionId, $accept, $name, $now): PriceOfferAnswered|null {
            [$offer, $row] = $this->lockOffer($offerId);

            if (null === $offer || null === $row || (int) $offer['pid'] !== $subscriptionId || !self::answerable($offer, $now) || self::ends($row, (int) $offer['effectiveAt'])) {
                return null;
            }

            $answer = ['answeredAt' => $now, 'answeredBy' => 'account', 'tstamp' => $now];

            if ($accept) {
                $this->connection->update('tl_consho_subscription_price', ['state' => 'accepted'] + $answer, ['id' => $offerId]);

                return new PriceOfferAnswered(self::due($offer, $row, 'accepted'), true);
            }

            $declaration = new CancellationDeclaration($subscriptionId, CancellationSource::PriceChange, CancellationKind::Ordinary, $name, (int) $offer['effectiveAt']);
            $result = $this->recorder->recordWithin($declaration, $now);

            if (CancellationOutcome::Recorded !== $result->outcome) {
                return null;
            }

            $this->connection->update('tl_consho_subscription_price', ['state' => 'declined', 'cancellation' => (int) $result->id] + $answer, ['id' => $offerId]);

            return new PriceOfferAnswered(self::due($offer, $row, 'declined'), false, $declaration, $result);
        });
    }

    /**
     * Whether the customer can still answer: out, open, before the deadline.
     *
     * @param array<string, mixed> $offer a row of tl_consho_subscription_price
     */
    public static function answerable(array $offer, int $now): bool
    {
        return 'open' === $offer['state'] && (int) $offer['mailSentAt'] > 0 && $now < (int) $offer['deadlineAt'];
    }

    /**
     * The mail after an answer or a lapse went out — for a consumer's lapse
     * it is the cancellation's confirmation, too.
     */
    public function answerSent(int $offerId, int $now): void
    {
        $this->connection->update('tl_consho_subscription_price', ['answerMailSentAt' => $now, 'tstamp' => $now], ['id' => $offerId]);
        $cancellation = (int) $this->connection->fetchOne('SELECT cancellation FROM tl_consho_subscription_price WHERE id = ?', [$offerId]);

        if ($cancellation > 0) {
            $this->connection->update('tl_consho_cancellation', ['confirmationSentAt' => $now], ['id' => $cancellation]);
        }
    }

    /**
     * Open, or agreed and not yet billed.
     *
     * @param array<string, mixed> $offer
     */
    private static function unsettled(array $offer): bool
    {
        return 'open' === $offer['state'] || ('accepted' === $offer['state'] && (int) $offer['appliedAt'] < 1);
    }

    /**
     * Whether the subscription ends before the offer could apply.
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    private static function ends(array $row, int $effectiveAt): bool
    {
        $endsAt = (int) ($row['endsAt'] ?? 0);

        return 'ended' === (string) $row['state']
            || (int) ($row['renewedBy'] ?? 0) > 0
            || ($endsAt > 0 && $endsAt <= $effectiveAt);
    }

    /**
     * The subscription's lock first, then the offer — an answer takes the
     * same lock, so what is read here is what stands.
     *
     * @return array{array<string, mixed>|null, array<string, mixed>|null} offer and subscription
     */
    private function lockOffer(int $offerId): array
    {
        $pid = $this->connection->fetchOne('SELECT pid FROM tl_consho_subscription_price WHERE id = ?', [$offerId]);

        if (false === $pid) {
            return [null, null];
        }

        $row = $this->lock->lock((int) $pid);
        $offer = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription_price WHERE id = ?', [$offerId]);

        return [false === $offer ? null : $offer, $row];
    }

    /**
     * @param array<string, mixed> $offer
     * @param array<string, mixed> $row
     */
    private static function due(array $offer, array $row, string $event): PriceOfferDue
    {
        return new PriceOfferDue(
            (int) $offer['id'],
            (int) $offer['pid'],
            (string) $row['subscriptionNumber'],
            $event,
            (string) $offer['oldUnitNet'],
            (string) $offer['newUnitNet'],
            (int) $offer['effectiveAt'],
            (int) $offer['deadlineAt'],
        );
    }

    /**
     * An offer not settled yet: open, or agreed and not yet billed — until
     * then the frozen price is not the one to compare with.
     */
    private function waiting(int $subscriptionId): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT id FROM tl_consho_subscription_price WHERE pid = ? AND (state = 'open' OR (state = 'accepted' AND appliedAt = 0)) LIMIT 1",
            [$subscriptionId],
        );
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function locked(\Closure $work): mixed
    {
        $level = $this->connection->getTransactionNestingLevel();
        $this->connection->beginTransaction();

        try {
            $result = $work();
            $this->connection->commit();
        } catch (\Throwable $e) {
            while ($this->connection->getTransactionNestingLevel() > $level) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return $result;
    }
}
