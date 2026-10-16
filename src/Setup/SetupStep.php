<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Setup;

use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding as F;

/**
 * The steps of the setup page (block I), in the order a fresh installation
 * takes them.
 *
 * A step is defined by its findings, not by queries of its own (spec I,
 * "Auslegung"): it is done when none of them is reported for the shop. That
 * keeps one answer to "what is missing" — the one the start page, the
 * dashboard and the checkout already give.
 *
 * Only warnings are listed. Notes belong to the dashboard; a setup page that
 * listed a duplicate GTIN as unfinished setup would never be finished.
 * SetupStepTest holds every blocking finding to exactly one step.
 */
enum SetupStep: string
{
    case Shop = 'shop';
    case Basics = 'basics';
    case Mail = 'mail';
    case Shipping = 'shipping';
    case Payment = 'payment';
    case NumberRanges = 'numberRanges';
    case ShopPages = 'shopPages';
    case LegalTexts = 'legalTexts';

    /**
     * @return list<F>
     */
    public function findings(): array
    {
        return match ($this) {
            // The start point and its currency. Whether one exists at all is
            // the one thing no finding says (I7) — SetupGuide asks for it.
            self::Shop => [F::CurrencyMissing, F::RootWithoutCurrency, F::RootCurrencyNotSold, F::StartPointWithoutDomain],
            // Everything else in the shop configuration's own mask.
            self::Basics => [
                F::HomeCountryMissing, F::InvoiceSenderIncomplete, F::TaxRateMissing, F::DeliveryTimeMissing,
                F::ReverseChargeNoteMissing, F::RetentionPeriodMissing, F::EInvoiceIncomplete,
                F::WithdrawalPeriodShort, F::AutomaticStatusCancels, F::InvoiceArchiveFolderUnsafe,
            ],
            self::Mail => [F::MailTransportMissing],
            self::Shipping => [F::ShippingMethodMissing, F::ShippingPriceMissing, F::AgeGateMissing, F::DeliveryCountryWithoutShipping],
            self::Payment => [F::PaymentMethodMissing, F::PaymentFeeTaxMissing, F::SubscriptionBillingMethodMissing, F::TestPaymentActive],
            self::NumberRanges => [F::NumberRangeMissing, F::RefundRangeMissing],
            self::ShopPages => [
                F::CartPageMissing, F::CartElementMissing, F::CheckoutPageMissing, F::CheckoutElementMissing,
                F::OrderPageMissing, F::OrderElementMissing, F::ShippingCostsElementMissing,
                F::WithdrawalFunctionMissing, F::CancellationPageMissing,
                // Nachlese KA7: accounts — pages and a module, not the shop mask.
                F::RegistrationPathMissing, F::CustomerPageLocksOut, F::LoginPageMissing,
            ],
            self::LegalTexts => [F::LegalTextMissing, F::LegalTextUnreachable, F::PrivacyPageMissing, F::WarrantyNoticeMissing, F::WarrantyNoticeFileMissing],
        };
    }

    public static function of(F $finding): self|null
    {
        foreach (self::cases() as $step) {
            if (\in_array($finding, $step->findings(), true)) {
                return $step;
            }
        }

        return null;
    }
}
