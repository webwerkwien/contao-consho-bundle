<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;

/**
 * Nachnahme — cash on delivery (spec E, Z2).
 *
 * The carrier collects at the door and pays the operator later; the invoice
 * goes into the parcel (OnShipment). Only as good as the shipping method it
 * travels with: offered ONLY when the chosen shipping method says it can
 * collect (ShippingCapabilities::$cashOnDelivery). For the core's flat rate
 * that is a tick the operator sets, because it is a matter of the contract
 * with the carrier, not of code — unlike the age check (C19), which is a
 * service a carrier performs and must declare itself.
 *
 * The only core method with a fee (Z4). Its tax is the operator's choice at
 * the method (Z3, E-1); how it may be charged is the operator's to answer —
 * the mask quotes § 56 Abs 3 ZaDiG 2018 and § 270a BGB without judging.
 *
 * The fee is NET, in ONE currency. In any other currency the method is not
 * offered: a fee that cannot be charged is not a free delivery.
 *
 * Like prepayment it moves no money itself: the operator records the
 * carrier's payout in the order mask. Until then the order is open.
 */
final class CashOnDeliveryHandler implements PaymentHandlerInterface
{
    public static function getType(): string
    {
        return 'cod';
    }

    public function isAvailable(PaymentContext $context, array $config): bool
    {
        if (true !== $context->shipping?->cashOnDelivery) {
            return false;
        }

        $fee = self::fee($config);

        return null === $fee || $fee['currency'] === strtoupper($context->currency);
    }

    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null
    {
        $fee = self::fee($config);

        return null === $fee ? null : new PaymentAdjustment($fee['net'], PaymentAdjustmentOrigin::Fee);
    }

    public function documentTrigger(array $config): DocumentTrigger
    {
        return DocumentTrigger::OnShipment;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        return PaymentStart::completed(\is_scalar($config['instructions'] ?? null) ? (string) $config['instructions'] : '');
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

    /**
     * The configured fee, or null for none. A fee without a currency, or one
     * that is not a positive number, counts as none — the mask's rgxp and
     * mandatory checks keep both from being saved.
     *
     * @param array<string, mixed> $config
     *
     * @return array{net: string, currency: string}|null
     */
    public static function fee(array $config): array|null
    {
        $net = \is_scalar($config['fee'] ?? null) ? trim((string) $config['fee']) : '';
        $currency = \is_scalar($config['feeCurrency'] ?? null) ? strtoupper(trim((string) $config['feeCurrency'])) : '';

        if ('' === $net || '' === $currency) {
            return null;
        }

        try {
            return BigDecimal::of($net)->isPositive() ? ['net' => $net, 'currency' => $currency] : null;
        } catch (MathException) {
            return null;
        }
    }
}
