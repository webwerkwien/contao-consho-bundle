<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\RefundingPaymentHandlerInterface;

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
     * @param int|null $against one payment only (the operation "Erstatten")
     *
     * @return array<int, string> payment id → refundable amount, positive
     */
    public function refundable(int $orderId, int|null $against = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.amount, a.method AS methodId,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM tl_consho_payment r WHERE r.refundOf = p.id) AS refunded
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

            $left = BigDecimal::of((string) $row['amount'])->plus((string) $row['refunded']);

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
     * @param string   $amount  positive: what goes back
     * @param bool     $payOut  false books the whole amount as one `manual` row
     * @param int|null $against one payment only
     *
     * @return list<int> the rows booked, the provider rows first
     */
    public function book(int $orderId, string $amount, string $reference, int $now, bool $payOut = true, int|null $against = null): array
    {
        $left = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (!$left->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('A refund is positive, got "%s".', $amount));
        }

        $booked = [];

        foreach ($payOut ? $this->refundable($orderId, $against) : [] as $paymentId => $refundable) {
            if (!$left->isPositive()) {
                break;
            }

            $part = BigDecimal::min($left, BigDecimal::of($refundable));
            $booked[] = $this->insert($orderId, $part, $reference, $now, $paymentId, 'requested');
            $left = $left->minus($part);
        }

        if ($left->isPositive()) {
            $booked[] = $this->insert($orderId, $left, $reference, $now, 0, 'manual');
        }

        return $booked;
    }

    /**
     * Books and pays out in one go, under the order's lock — the operation
     * "Erstatten" on a payment.
     *
     * @return list<int> the rows booked
     */
    public function refund(int $orderId, string $amount, string $reference, int $now, int|null $against = null): array
    {
        $this->connection->beginTransaction();

        try {
            // Checked again under the lock: two operators refunding the same
            // payment both passed the form's check. The second must not fall
            // into book()'s "rest by hand", which is meant for withdrawals —
            // it would book a refund nobody owes. The sum is read after the
            // lock, or it is the stale one the form already had.
            if (null === $this->orders->lockOrder($orderId)) {
                $this->connection->commit();

                return [];
            }

            $refundable = array_reduce($this->refundable($orderId, $against), static fn (BigDecimal $sum, string $left): BigDecimal => $sum->plus($left), BigDecimal::zero());

            if ($refundable->isLessThan(BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp))) {
                $this->connection->commit();

                return [];
            }

            $booked = $this->book($orderId, $amount, $reference, $now, true, $against);
            $this->connection->commit();
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
        $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = ?, payoutReference = ?, payoutCheckedAt = ?, tstamp = ? WHERE id = ? AND payoutState = 'requested'",
            [$result->outcome->value, '' !== $result->reference ? $result->reference : $refund->payoutReference, $now, $now, $rowId],
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

        if (0 < $stored || RefundOutcome::Failed === $result->outcome) {
            return;
        }

        $this->logger->warning(\sprintf('Refund %d was settled by hand while the provider paid it back — check for a double payment.', $rowId));
        $this->connection->executeStatement(
            "UPDATE tl_consho_payment SET payoutState = ?, payoutReference = ?, payoutCheckedAt = ?, tstamp = ? WHERE id = ? AND payoutState = 'manual'",
            $values,
        );
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
