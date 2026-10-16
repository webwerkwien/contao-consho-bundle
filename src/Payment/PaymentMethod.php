<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;

/**
 * A payment method as a shop offers it: the record plus its ready config
 * array (C3), `instructions` included (PaymentHandlerInterface).
 *
 * `feeTaxMode` is how a FEE of this method is taxed (E-1, Z3), chosen by the
 * operator exactly like shipping: split over the goods, or one fixed class.
 * Null means not chosen, and a method that asks for a fee without it is not
 * offered — whether a payment fee is an ancillary supply or a supply of its
 * own is a tax question Consho does not answer for the operator
 * (docs/block-e-versand-zahlung.md, section 3).
 */
final class PaymentMethod
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly array $config,
        public readonly ShippingTaxMode|null $feeTaxMode = null,
        public readonly int $feeTaxClassId = 0,
    ) {
    }

    /**
     * Whether a fee can be taxed: a mode is chosen, and a fixed one has its class.
     */
    public function feeTaxDecided(): bool
    {
        return match ($this->feeTaxMode) {
            null => false,
            ShippingTaxMode::Proportional => true,
            ShippingTaxMode::Fixed => $this->feeTaxClassId > 0,
        };
    }
}
