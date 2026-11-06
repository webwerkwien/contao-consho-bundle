<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\ChargebackRow;
use Webwerkwien\ContaoConshoBundle\Payment\CorrectionRow;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEvent;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEventKind;
use Webwerkwien\ContaoConshoBundle\Payment\RefundingPaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\RefundListingPaymentHandlerInterface;

/**
 * Books a refund and pays it out through the providers that can (block
 * Zahlung Mollie, R1–R4).
 *
 * ── Booked first, paid out after (R1) ────────────────────────────────────
 *
 * book() runs inside the caller's transaction, under the order's lock, and
 * only writes rows: one negative row per payment it pays back, state
 * `requested`, and one `manual` row for what no provider can take. payOut()
 * is called after the commit and asks the providers. A provider's payout
 * cannot be rolled back, a row can be marked failed — the other way round a
 * rolled-back booking would leave money paid out that the books never saw.
 *
 * ── Which payments (R3) ──────────────────────────────────────────────────
 *
 * The order's received payments whose method's handler can refund, newest
 * first, each up to what is not yet paid back on it (its amount plus its
 * negative rows). Rows the operator typed have no attempt and no provider.
 *
 * ── Never twice ──────────────────────────────────────────────────────────
 *
 * A row is claimed before the provider is asked: `requested` without a
 * reference turns `sending` in one UPDATE, and only the process that changed
 * the row calls refund(). A process that dies while sending leaves the row
 * `sending` — whether the provider got the request is unknown, so it is not
 * sent again by itself; the dashboard names it and the operator decides.
 */
class RefundPayout
{
    /**
     * How long a booked row may wait for the payOut() that follows its
     * commit before the cron sends it.
     */
    private const UNSENT_AFTER = 600;

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $handlers,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * What the order's providers could still pay back, per payment, newest
     * first — the payments book() would use.
     *
     * Less every row that took money back from the payment (`refundOf`), and
     * less the refunds made at the provider that are still open (Geldflüsse
     * D3): that money is gone already, only not yet recorded or assigned.
     * Once it is, its row counts instead — never both.
     *
     * @param int|null $against one payment only (the operation "Erstatten")
     *
     * @return array<int, string> payment id → refundable amount, positive
     */
    public function refundable(int $orderId, int|null $against = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.amount, a.method AS methodId,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM tl_consho_payment r WHERE r.refundOf = p.id) AS refunded,
                    (SELECT COALESCE(SUM(e.amount), 0) FROM tl_consho_provider_event e
                      WHERE e.payment = p.id AND e.kind = \'refund\' AND e.state = \'open\') AS outside
               FROM tl_consho_payment p
               JOIN tl_consho_payment_attempt a ON a.id = p.attempt
              WHERE p.pid = ? AND p.amount > 0 AND p.reference <> \'\'
              ORDER BY p.receivedAt DESC, p.id DESC',
            [$orderId],
        );

        $refundable = [];

        foreach ($rows as $row) {
            if (null !== $against && (int) $row['id'] !== $against) {
                continue;
            }

            if (null === $this->handlerFor((int) $row['methodId'])) {
                continue;
            }

            $left = BigDecimal::of((string) $row['amount'])->plus((string) $row['refunded'])->minus((string) $row['outside']);

            if ($left->isPositive()) {
                $refundable[(int) $row['id']] = (string) $left->toScale(4, RoundingMode::HalfUp);
            }
        }

        return $refundable;
    }

    /**
     * The titles of the payment methods refundable() would pay back through,
     * newest payment first, each once — for the option at a withdrawal.
     *
     * @return list<string>
     */
    public function methodTitles(int $orderId): array
    {
        $titles = [];

        foreach (array_keys($this->refundable($orderId)) as $paymentId) {
            $methodId = (int) $this->connection->fetchOne('SELECT a.method FROM tl_consho_payment p JOIN tl_consho_payment_attempt a ON a.id = p.attempt WHERE p.id = ?', [$paymentId]);
            $titles[] = (string) $this->methods->byId($methodId)?->title;
        }

        return array_values(array_unique(array_filter($titles, static fn (string $title): bool => '' !== $title)));
    }

    /**
     * Books the refund. Call inside a transaction that holds the order's lock.
     *
     * One refund, up to three ways, taken in this order (Geldflüsse T3):
     * the refunds already made at the provider that the operator chose, then
     * the provider, then by hand.
     *
     * @param string    $amount  positive: what goes back
     * @param bool      $payOut  false books what the chosen foreign refunds do
     *                           not cover `manual`, spread over the payments as
     *                           byHand() takes them
     * @param int|null  $against one payment only
     * @param list<int> $foreign open refunds at the provider (tl_consho_provider_event)
     *                           this refund consists of: each becomes a row `done`
     *                           with the provider's ID, the event `assigned`
     *
     * @throws RefundStateChangedException where a chosen foreign refund is no
     *                                     longer open or does not fit
     *
     * @return list<int> the rows booked: foreign, provider, by hand
     */
    public function book(int $orderId, string $amount, string $reference, int $now, bool $payOut = true, int|null $against = null, array $foreign = [], string $by = ''): array
    {
        $left = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (!$left->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('A refund is positive, got "%s".', $amount));
        }

        $booked = [];

        foreach (array_unique($foreign) as $eventId) {
            [$rowId, $part] = $this->bookForeign($orderId, $eventId, $against, $left, $reference, $now, $by);
            $booked[] = $rowId;
            $left = $left->minus($part);
        }

        foreach ($payOut && $left->isPositive() ? $this->refundable($orderId, $against) : [] as $paymentId => $refundable) {
            if (!$left->isPositive()) {
                break;
            }

            $part = BigDecimal::min($left, BigDecimal::of($refundable));
            $booked[] = $this->insert($orderId, $part, $reference, $now, $paymentId, 'requested');
            $left = $left->minus($part);
        }

        // Geldflüsse T2: by hand still pays back a payment. Tied to it, the
        // row lowers what refundable() offers — otherwise "Erstatten" offered
        // money already paid back (D1), and a refund made at the provider
        // found no row to be assigned to (D2).
        foreach ($left->isPositive() ? $this->byHand($orderId, $against) : [] as $paymentId => $remaining) {
            if (!$left->isPositive()) {
                break;
            }

            $part = BigDecimal::min($left, $remaining);
            $booked[] = $this->insert($orderId, $part, $reference, $now, $paymentId, 'manual');
            $left = $left->minus($part);
        }

        // More than all payments: a refund of nothing in particular.
        if ($left->isPositive()) {
            $booked[] = $this->insert($orderId, $left, $reference, $now, 0, 'manual');
        }

        return $booked;
    }

    /**
     * One chosen foreign refund as a row of this refund; call under the
     * order's lock. Read again here: between the form and the booking it may
     * have been recorded, assigned, or turned out to be an own refund.
     *
     * @return array{int, BigDecimal} the row and its amount
     */
    private function bookForeign(int $orderId, int $eventId, int|null $against, BigDecimal $left, string $reference, int $now, string $by): array
    {
        $event = $this->connection->fetchAssociative(
            "SELECT * FROM tl_consho_provider_event WHERE id = ? AND pid = ? AND kind = 'refund' AND state = 'open' AND payment > 0",
            [$eventId, $orderId],
        );

        if (false === $event || (null !== $against && (int) $event['payment'] !== $against)) {
            throw new RefundStateChangedException(\sprintf('The refund at the provider %d is no longer open for order %d.', $eventId, $orderId));
        }

        $amount = BigDecimal::of((string) $event['amount'])->toScale(4, RoundingMode::HalfUp);
        $own = $this->connection->fetchOne(
            "SELECT id FROM tl_consho_payment WHERE pid = ? AND method = 'refund' AND payoutReference = ?",
            [$orderId, (string) $event['reference']],
        );
        $paid = $this->connection->fetchOne(
            'SELECT p.amount + (SELECT COALESCE(SUM(r.amount), 0) FROM tl_consho_payment r WHERE r.refundOf = p.id)
               FROM tl_consho_payment p WHERE p.id = ? AND p.pid = ?',
            [(int) $event['payment'], $orderId],
        );

        // An own refund reported early, more than the refund, or more than is
        // left of its payment: nothing of it may go another way.
        if (false !== $own || $amount->isGreaterThan($left) || false === $paid || BigDecimal::of((string) $paid)->isLessThan($amount)) {
            throw new RefundStateChangedException(\sprintf('The refund at the provider %d does not fit the refund of order %d.', $eventId, $orderId));
        }

        $rowId = $this->insert($orderId, $amount, $reference, $now, (int) $event['payment'], 'done');
        $this->connection->update('tl_consho_payment', ['payoutReference' => (string) $event['reference'], 'payoutCheckedAt' => $now], ['id' => $rowId]);
        $this->resolve($eventId, 'assigned', $rowId, $now, $by);

        return [$rowId, $amount];
    }

    /**
     * What is left of each payment for a refund by hand, in the order it is
     * taken (Geldflüsse T2): first the payments no provider can pay back —
     * prepayment, typed by the operator — then the providers' payments, each
     * newest first. That keeps the providers' share free for a refund through
     * them, and takes it away where the money is back already.
     *
     * Not voucher payments: their money goes back onto the voucher.
     *
     * @return array<int, BigDecimal> payment id → what is left, positive
     */
    private function byHand(int $orderId, int|null $against): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id, p.amount, p.reference, a.method AS methodId,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM tl_consho_payment r WHERE r.refundOf = p.id) AS refunded,
                    (SELECT COALESCE(SUM(e.amount), 0) FROM tl_consho_provider_event e
                      WHERE e.payment = p.id AND e.kind = 'refund' AND e.state = 'open') AS outside
               FROM tl_consho_payment p
               LEFT JOIN tl_consho_payment_attempt a ON a.id = p.attempt
              WHERE p.pid = ? AND p.amount > 0 AND p.refundOf = 0 AND p.reference NOT LIKE 'voucher:%'
              ORDER BY p.receivedAt DESC, p.id DESC",
            [$orderId],
        );

        $plain = [];
        $provider = [];

        foreach ($rows as $row) {
            if (null !== $against && (int) $row['id'] !== $against) {
                continue;
            }

            $left = BigDecimal::of((string) $row['amount'])->plus((string) $row['refunded'])->minus((string) $row['outside']);

            if (!$left->isPositive()) {
                continue;
            }

            $byProvider = null !== $row['methodId'] && '' !== (string) $row['reference'] && null !== $this->handlerFor((int) $row['methodId']);

            if ($byProvider) {
                $provider[(int) $row['id']] = $left;
            } else {
                $plain[(int) $row['id']] = $left;
            }
        }

        return $plain + $provider;
    }

    /**
     * What can still go back from one payment, over every way (Geldflüsse
     * T4): what byHand() sees left of it plus its refunds still open at the
     * provider, which a refund can consist of. Null where nothing is left, or
     * where the row is no payment of its own — a refund, a chargeback, a
     * correction, a voucher payment (its money goes back onto the voucher).
     */
    public function remainder(int $orderId, int $paymentId): string|null
    {
        $left = $this->byHand($orderId, $paymentId)[$paymentId] ?? null;

        if (null === $left) {
            // Nothing by hand — perhaps all of it is out at the provider.
            $isPayment = $this->connection->fetchOne(
                "SELECT 1 FROM tl_consho_payment WHERE id = ? AND pid = ? AND amount > 0 AND refundOf = 0 AND reference NOT LIKE 'voucher:%'",
                [$paymentId, $orderId],
            );

            if (false === $isPayment) {
                return null;
            }

            $left = BigDecimal::zero();
        }

        $total = $left->plus($this->outside($orderId, $paymentId));

        return $total->isPositive() ? (string) $total->toScale(4, RoundingMode::HalfUp) : null;
    }

    /**
     * How a refund splits over its ways, as book() will take them (Geldflüsse
     * T3/T4) — for the confirmation, and checked again under the lock.
     *
     * @param list<int> $foreign the open refunds at the provider it consists of
     *
     * @return array{foreign: string, provider: string, hand: string}|null null
     *                                                                     where a chosen foreign refund is not open, or where one payment has
     *                                                                     not that much left
     */
    public function ways(int $orderId, string $amount, int|null $against, array $foreign, bool $payOut): array|null
    {
        $amount = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);
        $foreign = array_values(array_unique($foreign));
        $chosen = [] === $foreign ? [] : $this->connection->fetchAllAssociative(
            "SELECT payment, amount FROM tl_consho_provider_event WHERE id IN (?) AND pid = ? AND kind = 'refund' AND state = 'open' AND payment > 0",
            [$foreign, $orderId],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER],
        );

        if (\count($chosen) !== \count($foreign)) {
            return null;
        }

        $foreignSum = BigDecimal::zero();

        foreach ($chosen as $event) {
            if (null !== $against && (int) $event['payment'] !== $against) {
                return null;
            }

            $foreignSum = $foreignSum->plus((string) $event['amount']);
        }

        $rest = $amount->minus($foreignSum);

        if (!$amount->isPositive() || $rest->isNegative()) {
            return null;
        }

        // Booking the chosen foreign refunds moves their money from
        // `outside` to `refunded`: what is left of each payment stays.
        $provider = BigDecimal::zero();

        if ($payOut) {
            $refundable = array_reduce($this->refundable($orderId, $against), static fn (BigDecimal $sum, string $left): BigDecimal => $sum->plus($left), BigDecimal::zero());
            $provider = BigDecimal::min($rest, $refundable);
        }

        $hand = $rest->minus($provider);

        if (null !== $against) {
            $byHand = $this->byHand($orderId, $against)[$against] ?? BigDecimal::zero();

            if ($hand->isGreaterThan($byHand->minus($provider))) {
                return null;
            }
        }

        return [
            'foreign' => (string) $foreignSum->toScale(4),
            'provider' => (string) $provider->toScale(4),
            'hand' => (string) $hand->toScale(4),
        ];
    }

    /**
     * Books and pays out in one go, under the order's lock — the operation
     * "Erstatten" on a payment.
     *
     * @param list<int>                                                  $foreign  open refunds at the provider this refund consists of, see book()
     * @param array{foreign: string, provider: string, hand: string}|null $expected the split the operator confirmed (ways()); null allows
     *                                                                             nothing by hand
     *
     * @return list<int> the rows booked; none where the payment or a chosen
     *                   foreign refund changed meanwhile
     */
    public function refund(int $orderId, string $amount, string $reference, int $now, int|null $against = null, array $foreign = [], string $by = '', bool $payOut = true, array|null $expected = null): array
    {
        $this->connection->beginTransaction();

        try {
            // Checked again under the lock: two operators refunding the same
            // payment both passed the form's check. The second must not end
            // up with a split the operator never confirmed — a share by hand
            // nobody owes, or the provider's share moved by hand. The sums
            // are read after the lock, or they are the stale ones the form had.
            if (null === $this->orders->lockOrder($orderId)) {
                $this->connection->commit();

                return [];
            }

            $ways = $this->ways($orderId, $amount, $against, $foreign, $payOut);

            if (null === $ways || (null === $expected ? !BigDecimal::of($ways['hand'])->isZero() : !self::sameWays($ways, $expected))) {
                $this->connection->commit();

                return [];
            }

            $booked = $this->book($orderId, $amount, $reference, $now, $payOut, $against, $foreign, $by);
            $this->connection->commit();
        } catch (RefundStateChangedException) {
            $this->connection->rollBack();

            return [];
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        $this->payOut($booked, $now);

        return $booked;
    }

    /**
     * @param array{foreign: string, provider: string, hand: string} $a
     * @param array{foreign: string, provider: string, hand: string} $b
     */
    private static function sameWays(array $a, array $b): bool
    {
        foreach (['foreign', 'provider', 'hand'] as $way) {
            if (!BigDecimal::of($a[$way])->isEqualTo($b[$way])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The refunds of one payment still open at the provider.
     */
    private function outside(int $orderId, int $paymentId): BigDecimal
    {
        return BigDecimal::of((string) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) FROM tl_consho_provider_event WHERE pid = ? AND payment = ? AND kind = 'refund' AND state = 'open'",
            [$orderId, $paymentId],
        ));
    }

    /**
     * What a correction may still take from a payment (Geldflüsse E8): its
     * amount less every row tied to it and less the refunds still open at the
     * provider — the same remainder a refund sees. Null where the row is no
     * locked payment of its own: an unlocked one is edited instead, a voucher
     * payment carries the voucher's tax and is never typed. A refund against
     * it locks a payment as well (LockedRecords), so then it is corrected too.
     */
    public function correctable(int $paymentId): string|null
    {
        $row = $this->connection->fetchAssociative(
            "SELECT p.amount, p.method, p.reference, p.refundOf, p.lockedAt,
                    (SELECT COUNT(*) FROM tl_consho_payment r WHERE r.refundOf = p.id) AS refunds,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM tl_consho_payment r WHERE r.refundOf = p.id) AS refunded,
                    (SELECT COALESCE(SUM(e.amount), 0) FROM tl_consho_provider_event e
                      WHERE e.payment = p.id AND e.kind = 'refund' AND e.state = 'open') AS outside
               FROM tl_consho_payment p
              WHERE p.id = ?",
            [$paymentId],
        );

        if (false === $row || (null === $row['lockedAt'] && 0 === (int) $row['refunds']) || (int) $row['refundOf'] > 0 || !BigDecimal::of((string) $row['amount'])->isPositive()
            || ChargebackRow::is((string) $row['method']) || CorrectionRow::is((string) $row['method']) || str_starts_with((string) $row['reference'], 'voucher:')) {
            return null;
        }

        $left = BigDecimal::of((string) $row['amount'])->plus((string) $row['refunded'])->minus((string) $row['outside']);

        return $left->isPositive() ? (string) $left->toScale(4, RoundingMode::HalfUp) : null;
    }

    /**
     * Books a correction of a locked payment (Geldflüsse E8) under the
     * order's lock: a negative row tied to the payment, locked at once. No
     * money moves — no payout state, no mail, no document.
     *
     * @param string $amount positive: what the payment was booked too high
     *
     * @return int|null the row, or null where less is left than asked for
     */
    public function correct(int $orderId, int $paymentId, string $amount, string $reason, int $now): int|null
    {
        $amount = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (!$amount->isPositive() || '' === trim($reason)) {
            throw new \InvalidArgumentException('A correction needs a positive amount and a reason.');
        }

        $this->connection->beginTransaction();

        try {
            $left = null === $this->orders->lockOrder($orderId)
                || $orderId !== (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_payment WHERE id = ?', [$paymentId])
                ? null : $this->correctable($paymentId);

            if (null === $left || $amount->isGreaterThan($left)) {
                $this->connection->commit();

                return null;
            }

            $this->connection->insert('tl_consho_payment', [
                'pid' => $orderId,
                'tstamp' => $now,
                'receivedAt' => $now,
                'amount' => (string) $amount->negated(),
                'method' => CorrectionRow::METHOD,
                'reference' => mb_substr(trim($reason), 0, 255),
                'lockedAt' => $now,
                'refundOf' => $paymentId,
            ]);
            $rowId = (int) $this->connection->lastInsertId();
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return $rowId;
    }

    /**
     * The payments a refund row without its payment can be tied to
     * (Geldflüsse J3): those of its order of its own whose rest still covers
     * the row — what byHand() sees left, the row not yet counted anywhere.
     *
     * @return array<int, string> payment id → what is left of it now
     */
    public function assignablePayments(int $rowId): array
    {
        $row = $this->connection->fetchAssociative('SELECT pid, amount, refundOf, reference, method FROM tl_consho_payment WHERE id = ?', [$rowId]);

        if (false === $row || !UnassignedRefunds::is($row)) {
            return [];
        }

        $needed = BigDecimal::of((string) $row['amount'])->abs();
        $payments = [];

        foreach ($this->byHand((int) $row['pid'], null) as $paymentId => $left) {
            if ($left->isGreaterThanOrEqualTo($needed)) {
                $payments[$paymentId] = (string) $left->toScale(4, RoundingMode::HalfUp);
            }
        }

        ksort($payments);

        return $payments;
    }

    /**
     * Ties a refund row without its payment to one (Geldflüsse J3), under the
     * order's lock and only where assignablePayments() still offers it. Moves
     * no money and changes no amount: the row now counts against that payment.
     */
    public function assignToPayment(int $orderId, int $rowId, int $paymentId): bool
    {
        $this->connection->beginTransaction();

        try {
            $ok = null !== $this->orders->lockOrder($orderId)
                && $orderId === (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_payment WHERE id = ?', [$rowId])
                && isset($this->assignablePayments($rowId)[$paymentId]);

            if ($ok) {
                $this->connection->update('tl_consho_payment', ['refundOf' => $paymentId], ['id' => $rowId]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return $ok;
    }

    /**
     * Asks the providers for the rows still `requested` without a reference.
     * Call after the commit of book(). Never throws: what fails is logged and
     * stays on the row.
     *
     * @param list<int> $rowIds
     */
    public function payOut(array $rowIds, int $now): void
    {
        foreach ($rowIds as $rowId) {
            try {
                $this->send($rowId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Refund %d could not be paid out: %s', $rowId, $e->getMessage()), ['exception' => $e]);
            }
        }
    }

    /**
     * The hourly cron (R4): asks about every refund the provider answered
     * `pending`.
     *
     * Before that it sends the rows a process booked and never got to send —
     * one that died between the commit and payOut(). `requested` without a
     * reference was never claimed, so the provider was never asked: sending
     * it is safe, and the claim keeps a slow first process from sending too.
     *
     * @return int how many were asked about
     */
    public function checkPending(int $now): int
    {
        $this->payOut(array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_consho_payment WHERE payoutState = 'requested' AND payoutReference = '' AND tstamp < ? ORDER BY id",
            [$now - self::UNSENT_AFTER],
        )), $now);

        $rowIds = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_consho_payment WHERE payoutState = 'requested' AND payoutReference <> '' ORDER BY id",
        ));

        foreach ($rowIds as $rowId) {
            try {
                $this->ask($rowId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Refund %d could not be checked: %s', $rowId, $e->getMessage()), ['exception' => $e]);
            }
        }

        return \count($rowIds);
    }

    /**
     * A failed refund once more — only a failed one: a row `sending` may have
     * reached the provider.
     */
    public function retry(int $rowId, int $now): bool
    {
        $changed = $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = 'requested', payoutReference = '', tstamp = ? WHERE id = ? AND payoutState = 'failed'",
            [$now, $rowId],
        );

        if (0 === $changed) {
            return false;
        }

        $this->payOut([$rowId], $now);

        return true;
    }

    /**
     * The operator settles it by hand: a failed refund, one whose request
     * may or may not have reached the provider, or one still waiting — sent
     * and pending, or not sent yet. Sending and the cron change only a row in
     * the state they expect, so a row marked here is never sent. Only a
     * request already under way still stores the provider's answer — the
     * truth about the money wins over the mark.
     */
    public function markManual(int $rowId, int $now): bool
    {
        return 0 < $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = 'manual', tstamp = ? WHERE id = ? AND payoutState IN ('failed', 'sending', 'requested')",
            [$now, $rowId],
        );
    }

    /**
     * The refunds the provider holds for the payment a refund row pays back,
     * less those already tied to a refund row of the order — for "Von Hand
     * erledigt" (spec 9, N3). Only those with money on its way and the row's
     * amount: matching is by ID, but the operator chooses among refunds that
     * can be this one.
     *
     * @return list<ProviderEvent>|null null where the provider cannot list
     *                                  them or could not be asked
     */
    public function providerRefunds(int $rowId): array|null
    {
        $context = $this->context($rowId);

        if (null === $context || !$context[0] instanceof RefundListingPaymentHandlerInterface) {
            return null;
        }

        [$handler, $refund, $config] = $context;

        try {
            $listed = $handler->refunds($refund, $config);
        } catch (\Throwable $e) {
            $this->logger->warning(\sprintf('The refunds of order %s could not be listed: %s', $refund->orderNumber, $e->getMessage()));

            return null;
        }

        $known = array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT r.payoutReference FROM tl_consho_payment r JOIN tl_consho_payment o ON o.pid = r.pid WHERE o.id = ? AND r.method = 'refund' AND r.payoutReference <> ''",
            [$rowId],
        ));

        return array_values(array_filter($listed, static fn (ProviderEvent $event): bool => ProviderEventKind::Refund === $event->kind
            && \in_array($event->refundState, ['processing', 'refunded'], true)
            && BigDecimal::of($event->amount)->isEqualTo($refund->amount)
            && strtoupper($refund->currency) === $event->currency
            && !\in_array($event->reference, $known, true)));
    }

    /**
     * "Von Hand erledigt" with the provider's refund chosen: the row is done,
     * with the provider's ID — the key a later notification about it is
     * matched by. Like markManual() only for a row not handed to the provider
     * yet, or whose request failed or hangs; never for one the provider
     * already answered with a reference of its own.
     */
    public function assign(int $rowId, string $providerReference, int $now, string $by = ''): bool
    {
        $providerReference = trim($providerReference);

        if ('' === $providerReference) {
            return false;
        }

        $this->connection->beginTransaction();

        try {
            $orderId = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_payment WHERE id = ?', [$rowId]);

            if ($orderId < 1 || null === $this->orders->lockOrder($orderId)) {
                $this->connection->commit();

                return false;
            }

            $assigned = $this->assignUnderLock($orderId, $rowId, $providerReference, $now, $by);
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return $assigned;
    }

    /**
     * Refunds made at the provider and not yet recorded or assigned (spec
     * 9.6 B). refundable() still counts their money: paying out a withdrawal
     * or "Erstatten" through the provider would send the same refund twice
     * when it is the one already made there — so the forms point to them.
     *
     * @param int|null $payment one payment only
     *
     * @return list<array{id: int, reference: string, amount: string, currency: string}>
     */
    public function openForeign(int $orderId, int|null $payment = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, reference, amount, currency FROM tl_consho_provider_event
              WHERE pid = ? AND kind = 'refund' AND state = 'open'".(null !== $payment ? ' AND payment = ?' : '').'
              ORDER BY occurredAt, id',
            null !== $payment ? [$orderId, $payment] : [$orderId],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'amount' => (string) BigDecimal::of((string) $row['amount'])->toScale(4),
            'currency' => (string) $row['currency'],
        ], $rows);
    }

    /**
     * The refund rows a foreign refund can be assigned to (P4): the order's,
     * not tied to a provider's refund yet, and of the same amount — matching
     * stays by ID, the amount only narrows what is offered.
     *
     * @return list<array<string, mixed>>
     */
    public function assignableRows(int $eventId): array
    {
        $event = $this->connection->fetchAssociative("SELECT pid, amount FROM tl_consho_provider_event WHERE id = ? AND kind = 'refund' AND state = 'open'", [$eventId]);

        if (false === $event) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM tl_consho_payment
              WHERE pid = ? AND method = 'refund' AND payoutReference = ''
                AND payoutState IN ('manual', 'failed', 'sending', 'requested')
              ORDER BY receivedAt, id",
            [(int) $event['pid']],
        );

        return array_values(array_filter($rows, static fn (array $row): bool => BigDecimal::of((string) $row['amount'])->negated()->isEqualTo((string) $event['amount'])));
    }

    /**
     * "In Consho erfassen" (N3): a refund made at the provider becomes a
     * refund row — done, against the payment it paid back, with the
     * provider's ID. Money only: no credit note, no tax correction, the same
     * as "Erstatten"; those come with a withdrawal or a cancellation.
     *
     * @return string|null null when recorded, otherwise why not:
     *                     `stateChanged` (resolved meanwhile), `withdrawalOpen`
     *                     (a withdrawal waits for its refund), `complaintOpen`
     *                     (a complaint's rescission or reduction does) or `exceeds`
     *                     (more than is left of the payment)
     */
    public function recordForeign(int $eventId, int $now, string $by = ''): string|null
    {
        $this->connection->beginTransaction();

        try {
            $event = $this->openRefund($eventId);

            if (null === $event) {
                $this->connection->commit();

                return 'stateChanged';
            }

            // An own refund the provider reported before its answer reached
            // the row: the money is booked there already. First, so that an
            // open withdrawal of the order does not hold it up.
            $own = $this->connection->fetchOne(
                "SELECT id FROM tl_consho_payment WHERE pid = ? AND method = 'refund' AND payoutReference = ?",
                [(int) $event['pid'], (string) $event['reference']],
            );

            if (false !== $own) {
                $this->resolve($eventId, 'assigned', (int) $own, $now, $by);
                $this->connection->commit();

                return 'stateChanged';
            }

            // Geldflüsse H2: recorded here, the money is gone from the payment
            // and a withdrawal waiting for its refund could never pay it back
            // — the withdrawal books it. Read under the lock: a withdrawal
            // accepted meanwhile counts.
            $withdrawal = $this->connection->fetchOne(
                "SELECT id FROM tl_consho_withdrawal WHERE pid = ? AND state IN ('received', 'accepted') AND refundedAt IS NULL",
                [(int) $event['pid']],
            );

            if (false !== $withdrawal) {
                $this->connection->commit();

                return 'withdrawalOpen';
            }

            // RK-4: the same for a decided rescission or price reduction.
            $complaint = $this->connection->fetchOne(
                "SELECT id FROM tl_consho_complaint WHERE pid = ? AND decidedAt IS NOT NULL AND remedy IN ('rescission', 'reduction') AND state <> 'rejected' AND refundedAt IS NULL",
                [(int) $event['pid']],
            );

            if (false !== $complaint) {
                $this->connection->commit();

                return 'complaintOpen';
            }

            $left = $this->connection->fetchOne(
                'SELECT p.amount + (SELECT COALESCE(SUM(r.amount), 0) FROM tl_consho_payment r WHERE r.refundOf = p.id)
                   FROM tl_consho_payment p WHERE p.id = ? AND p.pid = ?',
                [(int) $event['payment'], (int) $event['pid']],
            );

            if (false === $left || BigDecimal::of((string) $left)->isLessThan((string) $event['amount'])) {
                $this->connection->commit();

                return 'exceeds';
            }

            $rowId = $this->insert((int) $event['pid'], BigDecimal::of((string) $event['amount']), (string) $event['reference'], $now, (int) $event['payment'], 'done');
            $this->connection->update('tl_consho_payment', ['payoutReference' => (string) $event['reference'], 'payoutCheckedAt' => $now], ['id' => $rowId]);
            $this->resolve($eventId, 'recorded', $rowId, $now, $by);
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return null;
    }

    /**
     * "Einer Zeile zuordnen" (N3, P4): a refund made at the provider is the
     * one an existing refund row stands for — paid back by hand in the
     * dashboard. The row is done, with the provider's ID.
     *
     * @return string|null null when assigned, otherwise why not:
     *                     `stateChanged` or `rowNotAssignable`
     */
    public function assignForeign(int $eventId, int $rowId, int $now, string $by = ''): string|null
    {
        $this->connection->beginTransaction();

        try {
            $event = $this->openRefund($eventId);

            if (null === $event) {
                $this->connection->commit();

                return 'stateChanged';
            }

            $eligible = array_map(static fn (array $row): int => (int) $row['id'], $this->assignableRows($eventId));

            if (!\in_array($rowId, $eligible, true) || !$this->assignUnderLock((int) $event['pid'], $rowId, (string) $event['reference'], $now, $by)) {
                $this->connection->commit();

                return 'rowNotAssignable';
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return null;
    }

    /**
     * An open foreign refund, with its order locked; call in a transaction.
     *
     * @return array<string, mixed>|null
     */
    private function openRefund(int $eventId): array|null
    {
        $orderId = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_provider_event WHERE id = ?', [$eventId]);

        if ($orderId < 1 || null === $this->orders->lockOrder($orderId)) {
            return null;
        }

        // Read after the lock: two operators resolving the same refund.
        $event = $this->connection->fetchAssociative("SELECT * FROM tl_consho_provider_event WHERE id = ? AND kind = 'refund' AND state = 'open'", [$eventId]);

        return false === $event ? null : $event;
    }

    /**
     * Ties a refund row to the provider's refund; call under the order lock.
     * An open event with that ID is resolved with it.
     */
    private function assignUnderLock(int $orderId, int $rowId, string $providerReference, int $now, string $by): bool
    {
        $taken = $this->connection->fetchOne(
            "SELECT id FROM tl_consho_payment WHERE pid = ? AND method = 'refund' AND payoutReference = ?",
            [$orderId, $providerReference],
        );

        if (false !== $taken) {
            return false;
        }

        $changed = $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = 'done', payoutReference = ?, payoutCheckedAt = ?, tstamp = ?
              WHERE id = ? AND pid = ? AND method = 'refund' AND payoutReference = ''
                AND payoutState IN ('manual', 'failed', 'sending', 'requested')",
            [mb_substr($providerReference, 0, 255), $now, $now, $rowId, $orderId],
        );

        if (0 === $changed) {
            return false;
        }

        $eventId = $this->connection->fetchOne("SELECT id FROM tl_consho_provider_event WHERE reference = ? AND kind = 'refund' AND state = 'open'", [$providerReference]);

        if (false !== $eventId) {
            $this->resolve((int) $eventId, 'assigned', $rowId, $now, $by);
        }

        return true;
    }

    private function resolve(int $eventId, string $state, int $rowId, int $now, string $by): void
    {
        $this->connection->update('tl_consho_provider_event', [
            'state' => $state,
            'resolvedRow' => $rowId,
            'resolvedAt' => $now,
            'resolvedBy' => mb_substr($by, 0, 64),
            'tstamp' => $now,
        ], ['id' => $eventId]);
    }

    private function send(int $rowId, int $now): void
    {
        // The claim (see the class comment): only the process that turns the
        // row asks the provider.
        $claimed = $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = 'sending', tstamp = ? WHERE id = ? AND payoutState = 'requested' AND payoutReference = ''",
            [$now, $rowId],
        );

        if (0 === $claimed) {
            return;
        }

        $context = $this->context($rowId);

        if (null === $context) {
            $this->logger->error(\sprintf('Refund %d has no payment method that can refund any more.', $rowId));
            $this->store($rowId, RefundResult::failed('No payment method that can refund.'), $now);

            return;
        }

        [$handler, $refund, $config] = $context;

        try {
            $result = $handler->refund($refund, $config);
        } catch (\Throwable $e) {
            // No answer is not a refusal: the request may have reached the
            // provider and the money gone back (a timeout after sending).
            // `failed` would offer "pay out again" and pay twice. The row
            // stays `sending`; after an hour the start page names it, and the
            // operator checks the provider's account before settling it.
            $this->logger->error(\sprintf('Refund %d for order %s got no answer from the provider: %s', $rowId, $refund->orderNumber, $e->getMessage()), ['exception' => $e]);

            return;
        }

        if (RefundOutcome::Failed === $result->outcome) {
            $this->logger->error(\sprintf('Refund %d for order %s was refused: %s', $rowId, $refund->orderNumber, $result->reason));
        }

        $this->store($rowId, $result, $now);
    }

    private function ask(int $rowId, int $now): void
    {
        $context = $this->context($rowId);

        if (null === $context) {
            return;
        }

        [$handler, $refund, $config] = $context;
        $result = $handler->refundState($refund, $config);

        if (RefundOutcome::Pending === $result->outcome) {
            $this->connection->update('tl_consho_payment', ['payoutCheckedAt' => $now], ['id' => $rowId]);

            return;
        }

        if (RefundOutcome::Failed === $result->outcome) {
            $this->logger->error(\sprintf('Refund %d for order %s failed at the provider: %s', $rowId, $refund->orderNumber, $result->reason));
        }

        // Still `requested`: a second cron run at the same time must not
        // overwrite what the first stored.
        $reference = '' !== $result->reference ? $result->reference : $refund->payoutReference;
        $stored = $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = ?, payoutReference = ?, payoutCheckedAt = ?, tstamp = ? WHERE id = ? AND payoutState = 'requested'",
            [$result->outcome->value, $reference, $now, $now, $rowId],
        );

        if (0 < $stored && RefundOutcome::Failed !== $result->outcome) {
            $this->claimEvent($rowId, $reference, $now);
        }
    }

    /**
     * The provider may report an own refund before its answer reaches the
     * row — by a notification of a module that does not mark its own
     * refunds. That event is this row's: resolved, or it would ask to be
     * recorded a second time.
     */
    private function claimEvent(int $rowId, string $reference, int $now): void
    {
        if ('' === $reference) {
            return;
        }

        $this->connection->executeStatement(
            "UPDATE tl_consho_provider_event SET state = 'assigned', resolvedRow = ?, resolvedAt = ?, tstamp = ?
              WHERE reference = ? AND kind = 'refund' AND state = 'open'
                AND pid = (SELECT pid FROM tl_consho_payment WHERE id = ?)",
            [$rowId, $now, $now, mb_substr($reference, 0, 255), $rowId],
        );
    }

    /**
     * Stores the answer to the row this process claimed. Marked by hand
     * meanwhile: money that moved still wins (`done`, `pending`) — but a
     * refusal does not overwrite the mark, or the row would ask to be sent
     * again after the operator paid by hand.
     */
    private function store(int $rowId, RefundResult $result, int $now): void
    {
        $values = [
            match ($result->outcome) {
                RefundOutcome::Done => 'done',
                RefundOutcome::Pending => 'requested',
                RefundOutcome::Failed => 'failed',
            },
            mb_substr($result->reference, 0, 255),
            $now,
            $now,
            $rowId,
        ];

        $stored = $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = ?, payoutReference = ?, payoutCheckedAt = ?, tstamp = ? WHERE id = ? AND payoutState = 'sending'",
            $values,
        );

        if (0 < $stored && RefundOutcome::Failed !== $result->outcome) {
            $this->claimEvent($rowId, $result->reference, $now);
        }

        if (0 < $stored || RefundOutcome::Failed === $result->outcome) {
            return;
        }

        // Assigned meanwhile to a refund made in the provider's dashboard
        // (assign()): that one stays — but if the provider paid this request
        // too, the customer got the money twice.
        $assigned = $this->connection->fetchOne("SELECT payoutReference FROM tl_consho_payment WHERE id = ? AND payoutState = 'done'", [$rowId]);

        if (false !== $assigned && (string) $assigned !== $result->reference) {
            $this->logger->warning(\sprintf('Refund %d was assigned to %s while the provider paid it back as %s — check for a double payment.', $rowId, (string) $assigned, $result->reference));

            return;
        }

        $this->logger->warning(\sprintf('Refund %d was settled by hand while the provider paid it back — check for a double payment.', $rowId));
        $marked = $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = ?, payoutReference = ?, payoutCheckedAt = ?, tstamp = ? WHERE id = ? AND payoutState = 'manual'",
            $values,
        );

        if (0 < $marked) {
            $this->claimEvent($rowId, $result->reference, $now);
        }
    }

    /**
     * @return array{RefundingPaymentHandlerInterface, PaymentRefund, array<string, mixed>}|null
     */
    private function context(int $rowId): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT r.id, r.amount, r.payoutReference, p.reference, a.method AS methodId, o.orderNumber, o.currency, o.language
               FROM tl_consho_payment r
               JOIN tl_consho_payment p ON p.id = r.refundOf
               JOIN tl_consho_payment_attempt a ON a.id = p.attempt
               JOIN tl_consho_order o ON o.id = r.pid
              WHERE r.id = ?',
            [$rowId],
        );

        if (false === $row) {
            return null;
        }

        $method = $this->methods->byId((int) $row['methodId']);
        $handler = $this->handlerFor((int) $row['methodId']);

        if (null === $method || null === $handler) {
            return null;
        }

        return [$handler, new PaymentRefund(
            (int) $row['id'],
            (string) $row['orderNumber'],
            (string) $row['currency'],
            (string) BigDecimal::of((string) $row['amount'])->negated()->toScale(4, RoundingMode::HalfUp),
            (string) $row['reference'],
            (string) $row['payoutReference'],
            (string) $row['language'],
        ), $method->config];
    }

    private function handlerFor(int $methodId): RefundingPaymentHandlerInterface|null
    {
        $method = $this->methods->byId($methodId);

        if (null === $method || !$this->handlers->has($method->type)) {
            return null;
        }

        $handler = $this->handlers->get($method->type);

        return $handler instanceof RefundingPaymentHandlerInterface ? $handler : null;
    }

    private function insert(int $orderId, BigDecimal $amount, string $reference, int $now, int $refundOf, string $state): int
    {
        $this->connection->insert('tl_consho_payment', [
            'pid' => $orderId,
            'tstamp' => $now,
            'receivedAt' => $now,
            'amount' => (string) $amount->negated()->toScale(4, RoundingMode::HalfUp),
            'method' => 'refund',
            'reference' => mb_substr($reference, 0, 255),
            'lockedAt' => $now,
            'refundOf' => $refundOf,
            'payoutState' => $state,
        ]);

        return (int) $this->connection->lastInsertId();
    }
}
