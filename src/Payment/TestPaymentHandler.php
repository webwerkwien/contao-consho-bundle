<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;

/**
 * The test provider (spec 6.2, C1): proves the online flow without a real
 * one. Offered only with CONSHO_TEST_PAYMENT=1 (PaymentHandlers).
 *
 * It behaves like a provider on the outside and knows nothing on the inside:
 *
 *   - start() sends the buyer to a page of its own (TestPaymentController)
 *     with everything the page needs in a SIGNED session parameter — amount,
 *     order number, the return and notify URLs. The page reads no table, as a
 *     provider could not.
 *   - The page calls the notify URL over HTTP, like a provider's server, with
 *     the body signed by the method's secret. So a test runs through the real
 *     route, the real signature check and the real lock.
 *   - The return carries its result signed as well. handleReturn() reports
 *     `succeeded` only when that signature holds — the
 *     page says only what it can prove — a hand-typed ?result=paid is
 *     `pending`, and pending shows the neutral sentence.
 *
 * The signature is HMAC-SHA256 with the secret from the method's settings,
 * compared with hash_equals(). The secret sits in jsonData in plain text and
 * in the versions of the record — acceptable for a provider that moves no
 * money and is never offered on a real shop.
 *
 * Invoice on payment (spec 6.2: OnPayment): in block C that means no invoice
 * at all, block D issues it.
 *
 * ── Follow-up payments (Abo-4b, P100) ────────────────────────────────────
 *
 * It can charge follow-up orders, so a test run has something to charge
 * with: an order with subscription lines (OrderView::$recurring) carries
 * `recurring` in the signed session, the test page puts a mandate
 * `test-mandate-<attempt>` into its paid notification, and the core keeps it
 * on the subscription. charge() answers what the method's option
 * `testFollowUpResult` says — paid, pending or failed. A pending charge is
 * answered by nobody: it goes "on account" after 7 days (P96), or the
 * operator records the payment.
 */
final class TestPaymentHandler implements RecurringPaymentHandlerInterface
{
    /** The mandate the test page hands out; charge() takes no other. */
    public const MANDATE_PREFIX = 'test-mandate-';

    public const CHARGE_RESULTS = ['paid', 'pending', 'failed'];
    public const SIGNATURE_HEADER = 'X-Consho-Test-Signature';

    public const RESULTS = ['paid' => AttemptState::Succeeded, 'cancelled' => AttemptState::Cancelled, 'failed' => AttemptState::Failed];

    /*
     * The address parameters, all `consho_`-prefixed: with
     * QUERY_PARAMS_ALLOW_LIST set, Contao's cache kernel strips every
     * parameter the list does not match — on every request, routes included
     * (StripQueryParametersSubscriber). The finding noticeParamStripped
     * recommends "consho_.*"; unprefixed, the return would lose its result
     * and read as pending, and the test page would be a 404.
     */
    public const PARAM_METHOD = 'consho_method';

    public const PARAM_SESSION = 'consho_session';

    public const PARAM_SIGNATURE = 'consho_signature';

    public const PARAM_RESULT = 'consho_result';

    public function __construct(private readonly UrlGeneratorInterface $router)
    {
    }

    public static function getType(): string
    {
        return 'test';
    }

    public function isAvailable(PaymentContext $context, array $config): bool
    {
        return '' !== self::secretOf($config);
    }

    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null
    {
        return null;
    }

    public function documentTrigger(array $config): DocumentTrigger
    {
        return DocumentTrigger::OnPayment;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        $secret = self::secretOf($config);

        if ('' === $secret) {
            return PaymentStart::failed('The test provider has no secret.');
        }

        $session = self::encode([
            'attempt' => $attempt->id,
            'order' => $order->orderNumber,
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'notify' => $attempt->notifyUrl,
            'return' => $attempt->returnUrl,
            // P93: the order sets up subscriptions — the page hands out a mandate.
            'recurring' => $order->recurring ? 1 : 0,
        ]);

        return PaymentStart::redirect($this->router->generate(
            'consho_test_payment',
            // The method in the clear: the page needs its secret to check the
            // rest, as a provider knows its merchant before it reads the order.
            [self::PARAM_METHOD => $attempt->methodId, self::PARAM_SESSION => $session, self::PARAM_SIGNATURE => self::sign($session, $secret)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        $result = $request->query->getString(self::PARAM_RESULT);
        $signature = $request->query->getString(self::PARAM_SIGNATURE);
        $secret = self::secretOf($config);

        if ('' === $secret || !isset(self::RESULTS[$result]) || !hash_equals(self::sign(self::returnData($attempt->id, $result), $secret), $signature)) {
            return AttemptState::Pending;
        }

        return self::RESULTS[$result];
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        $secret = self::secretOf($config);
        $body = $request->getContent();

        if ('' === $secret || !hash_equals(self::sign($body, $secret), (string) $request->headers->get(self::SIGNATURE_HEADER, ''))) {
            throw NotificationRejected::because('Signature does not match.');
        }

        $data = json_decode($body, true);

        /*
         * Bound to THIS attempt. The signature proves who wrote the message,
         * not which URL it was meant for; without the check a notification
         * signed for one attempt, replayed at the notify URL of another,
         * would pay the other one.
         */
        if (!\is_array($data) || ($data['attempt'] ?? null) !== $attempt->id) {
            throw NotificationRejected::because('Notification belongs to another attempt.');
        }

        /*
         * A paid message without a usable amount is refused, not thrown
         * (C-11): paid() would throw, the processor would
         * answer 500, and a provider resends a 5xx forever. A message no
         * provider would send is a 400.
         */
        $amount = \is_scalar($data['amount'] ?? null) ? (string) $data['amount'] : '';

        if ('paid' === ($data['event'] ?? null) && (1 !== preg_match('/^\d+(\.\d+)?$/', $amount) || !BigDecimal::of($amount)->isPositive())) {
            throw NotificationRejected::because('Paid without a positive amount.');
        }

        $mandate = \is_string($data['mandate'] ?? null) ? $data['mandate'] : '';

        return match ($data['event'] ?? null) {
            'paid' => NotificationResult::paid($amount, (string) ($data['reference'] ?? ''), $mandate),
            'failed' => NotificationResult::failed(),
            default => NotificationResult::ignored(),
        };
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
    }

    /**
     * P100: the answer the method's option sets, for a mandate this provider
     * handed out. Nothing is taken from anyone.
     */
    public function charge(PaymentAttempt $attempt, OrderView $order, string $mandate, array $config): ChargeResult
    {
        if ('' === self::secretOf($config)) {
            return ChargeResult::failed('The test provider has no secret.');
        }

        if (!str_starts_with($mandate, self::MANDATE_PREFIX)) {
            return ChargeResult::failed('Not a mandate of the test provider.');
        }

        return match (self::followUpResultOf($config)) {
            'pending' => ChargeResult::pending('TEST-PENDING-'.$attempt->id),
            'failed' => ChargeResult::failed('The test provider was set to fail.'),
            default => ChargeResult::paid($attempt->amount, 'TEST-CHARGE-'.$attempt->id),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function followUpResultOf(array $config): string
    {
        $result = \is_string($config['testFollowUpResult'] ?? null) ? $config['testFollowUpResult'] : '';

        return \in_array($result, self::CHARGE_RESULTS, true) ? $result : 'paid';
    }

    public static function mandateFor(int $attemptId): string
    {
        return self::MANDATE_PREFIX.$attemptId;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function secretOf(array $config): string
    {
        return \is_scalar($config['testSecret'] ?? null) ? trim((string) $config['testSecret']) : '';
    }

    public static function sign(string $data, string $secret): string
    {
        return hash_hmac('sha256', $data, $secret);
    }

    public static function returnData(int $attemptId, string $result): string
    {
        return $attemptId.'|'.$result;
    }

    /**
     * @param array<string, int|string> $data
     */
    public static function encode(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * The session of a test page, or null when its signature does not hold.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $session, string $signature, array $config): array|null
    {
        $secret = self::secretOf($config);

        if ('' === $secret || !hash_equals(self::sign($session, $secret), $signature)) {
            return null;
        }

        $json = base64_decode(strtr($session, '-_', '+/'), true);
        $data = false === $json ? null : json_decode($json, true);

        return \is_array($data) ? $data : null;
    }
}
