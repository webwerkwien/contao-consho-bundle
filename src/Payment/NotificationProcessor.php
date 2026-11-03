<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\ProviderEventBooker;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionChargeFallback;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionMandates;

/**
 * A provider's notification (spec 7.4) — the only way money gets into an
 * order without the operator typing it.
 *
 *   1. attempt by the token of the address; unknown → 404
 *   2. the handler checks authenticity; forged → 400, nothing written
 *   3. attempt locked FOR UPDATE — the lock between return and notification,
 *      the pattern of the number range, no Symfony Lock
 *   4. about another provider reference or in another currency → 400; a live
 *      attempt whose reference is not written yet → 503, send again;
 *      already succeeded → 200, nothing but the provider's events (spec 9:
 *      chargebacks, refunds made at the provider — ProviderEventBooker)
 *   5. paid → a row in tl_consho_payment, attempt succeeded
 *   6. after the commit: the consequences — invoice, status, mail (D-1,
 *      PaymentRecorded)
 *
 * ── A payment after a cancel is still booked (Z3) ────────────────────────
 *
 * A buyer cancels in the browser and the provider books anyway; an attempt
 * the customer switched away from (C-10) is paid after all. The state of the
 * attempt does not decide whether money that ARRIVED is recorded — only
 * `succeeded` does, because then it is recorded already.
 *
 * ── Twice, at the same moment ────────────────────────────────────────────
 *
 * The second request waits on the lock, reads `succeeded` and stops. Behind
 * the lock stands the unique key on tl_consho_payment.attempt; should both
 * ever get past it, the database refuses the second row and this answers 200
 * all the same — the money is recorded once, and that is what the provider
 * wants to hear.
 *
 * ── The amount the provider reports is booked, not the one expected ──────
 *
 * A different amount is logged; the payment state then reads partial or
 * overpaid, which is the truth and tells the operator where to look.
 *
 * ── Status codes ─────────────────────────────────────────────────────────
 *
 * 200 for everything the provider should stop sending, 400 for a message
 * that is not the provider's or not about this attempt, 404 for an address
 * that is no attempt, 500 where WE failed and 503 where the attempt is not
 * ready for it yet — providers resend on 5xx, which is exactly right then.
 *
 * ── The mandate (Abo-4b, P93) ────────────────────────────────────────────
 *
 * A paid notification may carry what the provider kept for follow-up
 * payments. It goes onto the order's subscriptions in the same transaction as
 * the money — only from a handler that can charge with it.
 *
 * ── A charge without the customer (Abo-4b, P94–P97) ──────────────────────
 *
 * The answer to a charge that was pending: paid sends "Rechnung zum Abo" in
 * its charged version instead of "Zahlung erhalten" (P97); failed puts the
 * follow-up order "on account" at once (P95).
 */
class NotificationProcessor
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentAttemptRepositoryInterface $attempts,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $handlers,
        private readonly PaymentRecorded $recorded,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // Optional for the tests built by hand; the container wires them.
        private readonly SubscriptionMandates|null $mandates = null,
        private readonly SubscriptionChargeFallback|null $fallback = null,
        private readonly ProviderEventBooker|null $events = null,
    ) {
    }

    public function process(string $token, Request $request, int $now): int
    {
        $attempt = $this->attempts->findByHash(hash('sha256', $token));

        if (null === $attempt || !$this->handlers->has($attempt->methodType)) {
            return Response::HTTP_NOT_FOUND;
        }

        $method = $this->methods->byId($attempt->methodId);
        $handler = $this->handlers->get($attempt->methodType);

        try {
            $result = $handler->handleNotification($request, $attempt, $method->config ?? []);
        } catch (NotificationRejected $e) {
            $this->logger->warning(\sprintf('Payment notification for attempt %d rejected: %s', $attempt->id, $e->getMessage()));

            return Response::HTTP_BAD_REQUEST;
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Payment notification for attempt %d failed: %s', $attempt->id, $e->getMessage()), ['exception' => $e]);

            return Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        if (NotificationOutcome::Ignored === $result->outcome) {
            return Response::HTTP_OK;
        }

        $booked = false;
        $failed = false;
        $charged = false;

        $this->connection->beginTransaction();

        try {
            $locked = $this->attempts->lock($attempt->id);

            if (null === $locked) {
                $this->connection->commit();

                return Response::HTTP_OK;
            }

            /*
             * About THIS attempt, in its currency (A-6). The handler proved
             * who wrote the message; whether it is about what start() set up
             * is checked here, for every module alike, against the locked
             * row. `for` is never empty (NotificationResult), so an attempt
             * without a reference takes no notification.
             *
             * Except while it is still live: a provider may answer a charge
             * before charge() has returned and the reference is written. That
             * one is asked to send again (503) instead of being told to stop.
             */
            $reference = $locked->providerReference;

            if ('' === $reference && $attempt->charge && AttemptState::Open !== $locked->state) {
                /*
                 * A charge whose outcome was unknown: charge() threw, so the
                 * reference never came back (SubscriptionCharger), and none
                 * will. The handler vouches that the message is about this
                 * attempt (RecurringPaymentHandlerInterface); its reference is
                 * taken now and checked against from here on.
                 */
                $reference = $result->for;
            } elseif ('' === $reference && (AttemptState::Open === $locked->state || AttemptState::Pending === $locked->state)) {
                $this->connection->commit();
                $this->logger->info(\sprintf('Payment notification for attempt %d arrived before its provider reference; asked to resend.', $attempt->id));

                return Response::HTTP_SERVICE_UNAVAILABLE;
            }

            $rejected = match (true) {
                !hash_equals($reference, $result->for) => 'it is about another provider reference',
                NotificationOutcome::Paid === $result->outcome && strtoupper($locked->currency) !== $result->currency => \sprintf('%s received, %s expected', $result->currency, $locked->currency),
                default => null,
            };

            if (null !== $rejected) {
                $this->connection->commit();
                $this->logger->warning(\sprintf('Payment notification for attempt %d rejected: %s.', $attempt->id, $rejected));

                return Response::HTTP_BAD_REQUEST;
            }

            if (AttemptState::Succeeded === $locked->state) {
                // The money is recorded; what the provider adds about it
                // later is not (spec 9): a chargeback, a refund made there.
                $this->events?->book($locked->orderId, $locked->id, $result->events, $now);
                $this->connection->commit();

                return Response::HTTP_OK;
            }

            if (NotificationOutcome::Paid === $result->outcome) {
                /*
                 * A charge only while its attempt is live (Abo-4b).
                 * Money for a charge the order has since fallen back from
                 * ("on account" after 7 days, P96) is booked all the same (Z3),
                 * but the customer has the invoice and its mail already —
                 * "abgebucht über Auf Rechnung" would be wrong. That one gets
                 * "Zahlung erhalten".
                 */
                $charged = $attempt->charge && (AttemptState::Open === $locked->state || AttemptState::Pending === $locked->state);
                $this->attempts->recordPayment($locked, $result->amount, $result->reference, $method->title ?? $locked->methodType, $now);
                // The reference of the attempt stays the one notifications are
                // checked against; the payment's own is on the payment row.
                $this->attempts->setState($locked->id, AttemptState::Succeeded, $now, $reference);
                $booked = true;

                // A first message may already carry a chargeback.
                $this->events?->book($locked->orderId, $locked->id, $result->events, $now);

                if ('' !== $result->mandate && $handler instanceof RecurringPaymentHandlerInterface) {
                    $this->mandates?->record($locked->orderId, $locked->methodId, $result->mandate, $now);
                }

                if (!BigDecimal::of($result->amount)->isEqualTo($locked->amount)) {
                    $this->logger->warning(\sprintf('Payment for attempt %d: %s received, %s expected.', $locked->id, $result->amount, $locked->amount));
                }
            } elseif (AttemptState::Open === $locked->state || AttemptState::Pending === $locked->state) {
                $this->attempts->setState($locked->id, AttemptState::Failed, $now, $reference);
                $failed = true;
            }

            $this->connection->commit();
        } catch (UniqueConstraintViolationException) {
            $this->connection->rollBack();

            return Response::HTTP_OK;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->logger->error(\sprintf('Recording the payment of attempt %d failed: %s', $attempt->id, $e->getMessage()), ['exception' => $e]);

            return Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        /*
         * The consequences (D-1): invoice, status, mail. After the commit and
         * never at the provider's expense — the money IS recorded, and a 500
         * now would make the provider resend a payment that is already in.
         * The next notification would find the attempt succeeded and stop, so
         * a failure here is logged, not retried: the operator sees the order
         * paid without its invoice and can issue it.
         */
        if ($booked) {
            try {
                $this->recorded->handle($attempt->orderId, true, $now, $charged);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The consequences of the payment of attempt %d failed: %s', $attempt->id, $e->getMessage()), ['exception' => $e]);
            }
        }

        // P95, after the commit like every consequence; the cron tries again
        // after 7 days what fails here (P96).
        if ($failed && $attempt->charge && null !== $this->fallback) {
            try {
                $this->fallback->toInvoice($attempt->orderId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Follow-up order of attempt %d could not be put "on account": %s', $attempt->id, $e->getMessage()), ['exception' => $e]);
            }
        }

        return Response::HTTP_OK;
    }
}
