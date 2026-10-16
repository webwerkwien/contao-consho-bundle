<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;

/**
 * Where an order stands, in two balances (spec D 5.1, decision A1).
 *
 * Michael, 2026-09-27: "eine bestellung kann nicht abgeschossen werden, wenn
 * zahlung oder versand noch offen sind, sie könnte aber abgeschlossen sein,
 * wenn kein versand aber stornierte zahlung vorliegt."
 *
 * "Settled" is therefore not "paid and shipped" but "nothing owed in either
 * direction": a cancelled order that never shipped and whose money went back
 * is as settled as one that was paid and delivered. An overpaid one is NOT —
 * money still has to go back.
 *
 * The states (PaymentState, DeliveryState) remain what is shown; the balance
 * is what is checked.
 */
final class OrderBalance
{
    /**
     * @param string             $claim     what the customer owes: the grand total, 0 once cancelled
     * @param string             $paid      the sum of all payments, refunds negative
     * @param array<int, string> $openGoods order item id => quantity still to ship, positive only
     * @param bool               $cancelled by its status or, sticky, by cancelledAt — the withdrawal
     *                                      function asks (D-2, E7: nothing to withdraw from)
     */
    public function __construct(
        public readonly string $claim,
        public readonly string $paid,
        public readonly PaymentState $paymentState,
        public readonly DeliveryState $deliveryState,
        public readonly array $openGoods,
        public readonly bool $anyShipped,
        public readonly bool $cancelled = false,
    ) {
    }

    /**
     * The claim of an order row: its grand total, or 0 once cancelled
     * (cancelledAt). For the checks that hold a locked order row in hand —
     * the payment method change and the payment start — so that they owe
     * what the balance owes. Until the D-1 review they asked the grand total,
     * and a cancelled order moved on to a flagless status ("In Klärung")
     * could still be paid online (Fable, S4).
     *
     * A cancelling STATUS is not looked at here: the callers refuse on it
     * already (statusHolds).
     *
     * @param array<string, mixed> $order a row of tl_consho_order
     */
    public static function claimOfRow(array $order): string
    {
        return '' !== (string) ($order['cancelledAt'] ?? '') ? '0' : (string) ($order['grandTotal'] ?? '0');
    }

    /**
     * What is still to be paid; negative where money has to go back.
     */
    public function outstanding(): string
    {
        return (string) BigDecimal::of($this->claim)->minus($this->paid);
    }

    public function paymentSettled(): bool
    {
        return BigDecimal::of($this->outstanding())->isZero();
    }

    public function deliverySettled(): bool
    {
        return [] === $this->openGoods;
    }

    public function settled(): bool
    {
        return $this->paymentSettled() && $this->deliverySettled();
    }
}
