<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * Why the checkout cannot go on yet, and which building block to go back to
 * (7.2, 12).
 *
 * A problem is never resolved silently. C2 and C13 both say it: a choice that
 * has become invalid sends the visitor back to the block that made it, with a
 * reason — the checkout does not pick a replacement. Someone who chose
 * registered post and finds standard post on the invoice was not asked.
 *
 * `step()` is what makes that possible without every template knowing the
 * rules.
 */
enum CheckoutProblem: string
{
    case BasketEmpty = 'basketEmpty';
    case UnpricedLine = 'unpricedLine';
    case AddressIncomplete = 'addressIncomplete';
    case DeliveryCountryNotServed = 'deliveryCountryNotServed';
    case VatIdInvalid = 'vatIdInvalid';
    case VatServiceUnavailable = 'vatServiceUnavailable';
    case NoShippingMethod = 'noShippingMethod';
    case ShippingMethodGone = 'shippingMethodGone';
    case NoPaymentMethod = 'noPaymentMethod';
    case PaymentMethodGone = 'paymentMethodGone';
    case AgeNotVerifiable = 'ageNotVerifiable';
    case DateOfBirthMissing = 'dateOfBirthMissing';

    // E-6 (V6): the chosen carrier needs the recipient's phone.
    case PhoneMissing = 'phoneMissing';
    case TooYoung = 'tooYoung';

    // Block G (G5): a line asks more than is in stock. Fixed in the basket.
    case StockShort = 'stockShort';

    public function step(): CheckoutBlock
    {
        return match ($this) {
            self::BasketEmpty, self::UnpricedLine, self::StockShort => CheckoutBlock::Address,
            // Address: a VAT ID entered there is what makes a business.
            self::AddressIncomplete, self::DeliveryCountryNotServed,
            self::VatIdInvalid, self::VatServiceUnavailable => CheckoutBlock::Address,
            self::NoShippingMethod, self::ShippingMethodGone,
            self::AgeNotVerifiable, self::DateOfBirthMissing, self::TooYoung, self::PhoneMissing => CheckoutBlock::Shipping,
            self::NoPaymentMethod, self::PaymentMethodGone => CheckoutBlock::Payment,
        };
    }

    /**
     * Whether it stops the order. `VatServiceUnavailable` does not: C15 says
     * an unreachable VIES means ordering WITH tax plus "check again", never a
     * blocked checkout — the visitor cannot fix an EU outage by waiting.
     */
    public function blocks(): bool
    {
        return self::VatServiceUnavailable !== $this;
    }
}
