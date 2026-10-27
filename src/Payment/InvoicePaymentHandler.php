<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;

/**
 * Auf Rechnung — purchase on account (spec E, Z1).
 *
 * The goods go out first, the invoice with them (OnShipment), and the
 * customer pays by transfer within the payment term the invoice states. The
 * operator carries the risk of not being paid, so the method comes with the
 * usual limits and nothing more: customer groups, a highest amount, and
 * "billing and delivery in the same country". A credit check is a module of
 * its own with a contract of its own (spec E, E5).
 *
 * No fee (Z4): whether a fee for paying by transfer is lawful in Austria is
 * not settled (§ 56 Abs 3 ZaDiG 2018, spec E section 3), and a field for it
 * would make it look as if it were.
 *
 * Like prepayment it moves no money itself: start() is done at once, there is
 * no return and no notification, and the operator records the transfer in
 * the order mask. Until then the order is open (Z5) — an invoice is a claim,
 * not a payment.
 *
 * Settings (virtual fields, tl_consho_payment_method):
 *   dueDays        payment term in days, printed on the invoice as a date
 *   graceDays      days after the term until unpaid digital goods rest
 *                  (Nachlese E5a, PurchaseDelinquency)
 *   allowedGroups  member groups that may choose it; empty means everyone,
 *                  guests included
 *   maxNet         highest net total of goods and shipping (as the checkout
 *                  judges a limit, C-5), empty for no limit
 *   maxNetCurrency the currency of that limit. A shop selling in several
 *                  currencies has one limit, in one of them: in any other
 *                  currency the method is NOT offered — a limit that cannot
 *                  be compared must not become no limit
 *   sameCountry    only when billing and delivery country are the same
 *   notHandedOutAtOnce  not for a basket with digital content or a voucher
 *                  (Nachlese E5b). Here
 *                  the release IS the delivery and brings the invoice; holding
 *                  it back until the money came would leave the customer
 *                  without an invoice to pay. Whoever wants the money first
 *                  sells such goods by prepayment.
 *   accountHolder, iban, bic, bankName, giroCode — the bank connection,
 *                  the same section as prepayment
 */
final class InvoicePaymentHandler implements PaymentHandlerInterface
{
    public const DEFAULT_DUE_DAYS = 14;

    public const DEFAULT_GRACE_DAYS = 14;

    private const DETAILS = ['accountHolder', 'iban', 'bic', 'bankName'];

    public static function getType(): string
    {
        return 'invoice';
    }

    /**
     * The payment term in days. Read by the document source as well, under
     * the same key, for the date on the invoice.
     *
     * @param array<string, mixed> $config
     */
    public static function dueDays(array $config): int
    {
        $days = $config['dueDays'] ?? null;

        if (null === $days || '' === $days || !is_numeric($days)) {
            return self::DEFAULT_DUE_DAYS;
        }

        return max(0, (int) $days);
    }

    /**
     * Days after the term until the digital goods of an unpaid purchase rest
     * (Nachlese E5a, PurchaseDelinquency). Empty is the default, like dueDays —
     * and so is a method that has no such setting at all.
     *
     * @param array<string, mixed> $config
     */
    public static function graceDays(array $config): int
    {
        $days = $config['graceDays'] ?? null;

        if (null === $days || '' === $days || !is_numeric($days)) {
            return self::DEFAULT_GRACE_DAYS;
        }

        return max(0, (int) $days);
    }

    public function isAvailable(PaymentContext $context, array $config): bool
    {
        $groups = array_values(array_filter(
            array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, \is_array($config['allowedGroups'] ?? null) ? $config['allowedGroups'] : []),
            static fn (int $id): bool => $id > 0,
        ));

        // A guest is in no group, so a restricted method is closed to guests
        // — which is what restricting it to groups is for.
        if ([] !== $groups && [] === array_intersect($groups, $context->customerGroups)) {
            return false;
        }

        if (($config['sameCountry'] ?? false) && '' !== $context->billingCountry && $context->billingCountry !== $context->deliveryCountry) {
            return false;
        }

        if (($config['notHandedOutAtOnce'] ?? false) && $context->handedOutAtOnce) {
            return false;
        }

        $limit = $config['maxNet'] ?? '';

        if (\is_scalar($limit) && '' !== trim((string) $limit)) {
            $currency = \is_scalar($config['maxNetCurrency'] ?? null) ? strtoupper(trim((string) $config['maxNetCurrency'])) : '';

            if ($currency !== strtoupper($context->currency)) {
                return false;
            }

            try {
                if (BigDecimal::of($context->netTotal)->isGreaterThan(BigDecimal::of(trim((string) $limit)))) {
                    return false;
                }
            } catch (MathException) {
                // A limit that is not a number limits nothing; the mask's
                // rgxp keeps it from being saved in the first place.
            }
        }

        return true;
    }

    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null
    {
        return null;
    }

    public function documentTrigger(array $config): DocumentTrigger
    {
        return DocumentTrigger::OnShipment;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        $notice = \is_scalar($config['instructions'] ?? null) ? (string) $config['instructions'] : '';
        $details = [];

        foreach (self::DETAILS as $key) {
            $details[$key] = \is_scalar($config[$key] ?? null) ? (string) $config[$key] : '';
        }

        return PaymentStart::completed($notice, $details);
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        return AttemptState::Pending;
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        return NotificationResult::ignored();
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
    }
}
