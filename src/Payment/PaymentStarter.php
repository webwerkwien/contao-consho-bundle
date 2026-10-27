<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Order\WithdrawalCredit;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionMandates;

/**
 * Where a payment begins (spec 7.3 step 4, 7.4; C-11, Z1).
 *
 * One place for the three callers — after the purchase, after a change of
 * payment method, and "try again" on the order page. Each of them holds a
 * committed order and nothing else; this opens the attempt, makes its token,
 * builds the return and notify URLs with it, and asks the handler.
 *
 * ── Every method gets an attempt ─────────────────────────────────────────
 *
 * Prepayment too (Z1): one rule instead of an exception,
 * and the order keeps a record of how it was meant to be paid. Its start()
 * is `completed`, which leaves the attempt `pending` — waiting for the
 * transfer the operator will record.
 *
 * ── The token exists here and nowhere else ───────────────────────────────
 *
 * Stored hashed (the repository never sees the plaintext), put into the two
 * URLs and handed to the handler inside them. That is why the attempt is
 * opened HERE and not at the purchase (F5, C-7).
 *
 * ── Never throws ─────────────────────────────────────────────────────────
 *
 * The order is committed; a provider that is down is no reason to show the
 * buyer a 500 instead of it. A handler that throws counts as `failed`, is
 * logged, and the order page offers "try again".
 */
class PaymentStarter
{
    /**
     * How long an open attempt may wait for its provider before "try again"
     * is offered (C-11). 30 minutes: roughly where hosted payment pages run
     * out — a guide, not any one provider's figure.
     */
    public const ATTEMPT_LIFETIME = 1800;

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $handlers,
        private readonly PaymentAttemptRepositoryInterface $attempts,
        private readonly PaymentStateCalculator $paymentStates,
        private readonly UrlGeneratorInterface $router,
        private readonly WithdrawalCredit $credits,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // The subscription lines of the order (Abo-4b, P93). Optional for the
        // tests built by hand; the container wires it.
        private readonly SubscriptionMandates|null $mandates = null,
    ) {
    }

    /**
     * @return PaymentStart|null null when there is nothing to start: no such
     *                           order, or nothing left to pay
     */
    public function start(int $orderId, int $now): PaymentStart|null
    {
        /*
         * Decided and opened under the order's lock (C-11).
         * Two tabs clicking "try again" at once both passed canRetry(), both
         * opened an attempt and both went to the provider — two attempts, so
         * the unique key on the payment could not help, and a real provider
         * would have taken the money twice. The second request now waits
         * here, finds the first one's attempt open and starts nothing.
         *
         * The method is read under the lock as well, so a "try again" racing
         * a change of method in another tab starts the NEW method's attempt,
         * and the change's own start() then finds it open and starts none —
         * one attempt either way (C-11).
         *
         * Only the decision and the INSERT are inside; the handler is asked
         * after the commit — a provider call cannot be rolled back, and the
         * lock is not held across it.
         */
        $token = bin2hex(random_bytes(32));
        $this->connection->beginTransaction();

        try {
            $order = $this->orders->lockOrder($orderId);
            $decision = null === $order ? null : $this->decide($orderId, $order, $now);

            if (!\is_array($decision)) {
                $this->connection->commit();

                return $decision;
            }

            [$method, $amount, $lapsed] = $decision;

            if (null !== $lapsed) {
                $this->attempts->setState($lapsed->id, AttemptState::Cancelled, $now);
            }

            $currency = $order['currency'] ?? '';
            $attemptId = $this->attempts->open($orderId, $method, $amount, $currency, hash('sha256', $token), $now);

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->logger->error(\sprintf('Order %d: opening a payment attempt failed: %s', $orderId, $e->getMessage()), ['exception' => $e]);

            return PaymentStart::failed($e->getMessage());
        }

        /*
         * The lapsed attempt's provider is told after the commit, like a
         * change of method does (7.5): its session should expire rather than
         * stay payable beside the new one. Should money still arrive for it,
         * the notification books it (Z3) and the order reads overpaid.
         */
        if (null !== $lapsed) {
            try {
                $this->handlers->get($lapsed->methodType)->cancel($lapsed, $method->config);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Cancelling lapsed payment attempt %d with its provider failed: %s', $lapsed->id, $e->getMessage()), ['exception' => $e]);
            }
        }

        $attempt = new PaymentAttempt(
            $attemptId,
            $orderId,
            $method->id,
            $method->type,
            AttemptState::Open,
            $amount,
            $currency,
            '',
            $this->router->generate('consho_payment_return', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->router->generate('consho_payment_notify', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        /*
         * P93: a handler that can charge follow-up orders asks its provider to
         * keep the method — with these lines. Reading them must not cost the
         * payment: without them the order is paid all the same, and its
         * follow-ups go "on account".
         */
        $lines = [];

        try {
            $lines = $this->mandates?->linesOf($orderId) ?? [];
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Order %s: the subscription lines for the payment could not be read: %s', $order['orderNumber'] ?? $orderId, $e->getMessage()), ['exception' => $e]);
        }

        $view = new OrderView(
            $orderId,
            $order['orderNumber'] ?? '',
            (int) $order['shop'],
            $currency,
            $order['grandTotal'],
            $order['email'] ?? '',
            $order['language'] ?? '',
            $lines,
            // WG-5: what the attempt is for — the rest after vouchers and payments.
            $amount,
        );

        try {
            $start = $this->handlers->get($method->type)->start($attempt, $view, $method->config);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Order %s: starting the payment failed: %s', $view->orderNumber, $e->getMessage()), ['exception' => $e]);

            $start = PaymentStart::failed($e->getMessage());
        }

        match ($start->outcome) {
            // Done here: waiting for the money (prepayment's transfer).
            PaymentStartOutcome::Completed => $this->attempts->setState($attemptId, AttemptState::Pending, $now),
            // Off to the provider: open until it answers.
            PaymentStartOutcome::Redirect => null,
            PaymentStartOutcome::Failed => $this->attempts->setState($attemptId, AttemptState::Failed, $now),
        };

        return $start;
    }

    /**
     * "Try again" (7.4, Z7): the newest attempt of the order's current method
     * failed, was cancelled or has lapsed, nothing is paid, and the status
     * does not hold the order. Not while an attempt is open and young, or
     * pending — a payment may be on its way, and a second one would take the
     * money twice.
     */
    public function canRetry(int $orderId, int $now): bool
    {
        $order = $this->orders->order($orderId);
        $latest = $this->attempts->latestFor($orderId);

        if (null === $order || null === $latest || $latest->methodId !== (int) $order['paymentMethod']) {
            return false;
        }

        if (AttemptState::Failed !== $latest->state && AttemptState::Cancelled !== $latest->state && !self::lapsed($latest, $now)) {
            return false;
        }

        if ((int) ($order['orderStatus'] ?? 0) > 0 && $this->orders->statusHolds((int) $order['orderStatus'])) {
            return false;
        }

        return PaymentState::Open === $this->paymentStates->calculate($this->credits->claimOf($order), $this->orders->paymentAmounts($orderId));
    }

    /**
     * What to open, under the lock: the method and the amount — or the
     * answer to give instead (null: nothing to start).
     *
     * @param array<string, string> $order
     *
     * @return array{PaymentMethod, string, PaymentAttempt|null}|PaymentStart|null the third element: a lapsed attempt to cancel
     */
    private function decide(int $orderId, array $order, int $now): array|PaymentStart|null
    {
        /*
         * Less what accepted withdrawals credit (D-3): an
         * unpaid order whose goods were partly withdrawn owes the rest, not
         * the grand total — 70 €, not 100 €.
         */
        $outstanding = $this->outstanding($this->credits->claimOf($order), $this->orders->paymentAmounts($orderId));

        if (!$outstanding->isPositive()) {
            return null;
        }

        // A payment may be on its way: the other tab's, or the first click's.
        $latest = $this->attempts->latestFor($orderId);
        $lapsed = null;

        if (null !== $latest && $latest->methodId === (int) $order['paymentMethod'] && \in_array($latest->state, [AttemptState::Open, AttemptState::Pending], true)) {
            if (!self::lapsed($latest, $now)) {
                return null;
            }

            $lapsed = $latest;
        }

        $method = $this->methods->byId((int) $order['paymentMethod']);

        if (null === $method || !$this->handlers->has($method->type)) {
            // A method deleted, or the test provider switched off, since the
            // order was placed. Nothing to open an attempt WITH.
            $this->logger->error(\sprintf('Order %s: payment method %d cannot start a payment.', $order['orderNumber'] ?? $orderId, (int) $order['paymentMethod']));

            return PaymentStart::failed('Payment method unavailable.');
        }

        return [$method, (string) $outstanding->toScale(4, RoundingMode::HalfUp), $lapsed];
    }

    /**
     * An OPEN attempt older than ATTEMPT_LIFETIME: the buyer left the
     * provider's page, and nothing will come of it. Pending never lapses — that is a
     * transfer on its way, and a new attempt would not make it arrive
     * sooner. Without a known start time (0) nothing lapses.
     */
    public static function lapsed(PaymentAttempt $attempt, int $now): bool
    {
        return AttemptState::Open === $attempt->state && $attempt->createdAt > 0 && $now - $attempt->createdAt >= self::ATTEMPT_LIFETIME;
    }

    /**
     * @param list<string> $amounts
     */
    private function outstanding(string $grandTotal, array $amounts): BigDecimal
    {
        $outstanding = BigDecimal::of('' === $grandTotal ? '0' : $grandTotal);

        foreach ($amounts as $amount) {
            $outstanding = $outstanding->minus($amount);
        }

        return $outstanding;
    }
}
