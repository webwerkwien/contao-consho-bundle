<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

/**
 * What a configuration check found, as a code rather than a sentence.
 *
 * Same reasoning as DeliverabilityProblem: these are read by a shop operator in
 * the back end, so the wording has to come from the language files. The check
 * says which finding applies and hands over the values to put in the sentence.
 */
enum ConfigurationFinding: string
{
    case TaxRateMissing = 'taxRateMissing';
    case DuplicateVatId = 'duplicateVatId';
    case MailTransportMissing = 'mailTransportMissing';
    case HomeCountryMissing = 'homeCountryMissing';
    case ProductManufacturerMissing = 'productManufacturerMissing';
    case ManufacturerEmailMissing = 'manufacturerEmailMissing';
    case ManufacturerEuResponsibleMissing = 'manufacturerEuResponsibleMissing';
    case DuplicateGtin = 'duplicateGtin';
    case TranslationMissing = 'translationMissing';
    case TranslationSafetyMissing = 'translationSafetyMissing';
    case ListPriceDeviation = 'listPriceDeviation';
    case VisibilityDeviation = 'visibilityDeviation';
    case PinnedProductMissing = 'pinnedProductMissing';
    case ProductShopMissing = 'productShopMissing';
    case ProductPriceMissing = 'productPriceMissing';
    case PriceTie = 'priceTie';
    case PriceCutAsPromotion = 'priceCutAsPromotion';
    case PathTemplateInvalid = 'pathTemplateInvalid';
    case ShippingPriceMissing = 'shippingPriceMissing';
    case MiniCartMissing = 'miniCartMissing';
    case NoticeParamStripped = 'noticeParamStripped';
    case RootWithoutCurrency = 'rootWithoutCurrency';
    case RootCurrencyNotSold = 'rootCurrencyNotSold';
    case CurrencyMissing = 'currencyMissing';
    case MultipleShopsShareMembers = 'multipleShopsShareMembers';
    case NetPriceGroupMissing = 'netPriceGroupMissing';
    case CatalogPageAlwaysCached = 'catalogPageAlwaysCached';
    case GroupPriceAboveList = 'groupPriceAboveList';

    // C-5
    case CheckoutPageMissing = 'checkoutPageMissing';
    case CheckoutElementMissing = 'checkoutElementMissing';
    case ShippingMethodMissing = 'shippingMethodMissing';
    case PaymentMethodMissing = 'paymentMethodMissing';
    case AgeGateMissing = 'ageGateMissing';

    // C-6
    case CartPageMissing = 'cartPageMissing';
    case CartElementMissing = 'cartElementMissing';
    case OrderPageMissing = 'orderPageMissing';
    // C-10: the order page is set but carries no order element.
    case OrderElementMissing = 'orderElementMissing';
    case LegalTextMissing = 'legalTextMissing';
    case LegalTextUnreachable = 'legalTextUnreachable';
    case PrivacyPageMissing = 'privacyPageMissing';
    case WarrantyNoticeMissing = 'warrantyNoticeMissing';
    case InvoiceSenderIncomplete = 'invoiceSenderIncomplete';
    case NumberRangeMissing = 'numberRangeMissing';
    // Two document ranges with one prefix (spec C, open points, C-12).
    case NumberPrefixShared = 'numberPrefixShared';

    // C-8: invoices.
    case InvoiceArchiveFailed = 'invoiceArchiveFailed';
    case InvoiceArchiveFolderUnsafe = 'invoiceArchiveFolderUnsafe';
    case ReverseChargeNoteMissing = 'reverseChargeNoteMissing';
    case DeliveryTimeMissing = 'deliveryTimeMissing';

    // C-9: the confirmation mails.
    case WarrantyNoticeFileMissing = 'warrantyNoticeFileMissing';
    case ConfirmationNotSent = 'confirmationNotSent';
    case OperatorAddressIsSender = 'operatorAddressIsSender';

    // C-11: CONSHO_TEST_PAYMENT is set.
    case TestPaymentActive = 'testPaymentActive';

    // D-2: the withdrawal function, and the legal texts after decision E1.
    case LegalTextAdvised = 'legalTextAdvised';
    case WithdrawalFunctionMissing = 'withdrawalFunctionMissing';
    case WithdrawalFunctionAdvised = 'withdrawalFunctionAdvised';
    case WithdrawalPeriodShort = 'withdrawalPeriodShort';

    // D-3: open withdrawals (F11), number ranges for refunds (F9).
    case WithdrawalsOpen = 'withdrawalsOpen';
    case RefundRangeMissing = 'refundRangeMissing';
    // WA (A5, 2026-10-22): withdrawals from the form that match no order yet.
    case WithdrawalsUnassigned = 'withdrawalsUnassigned';

    // D-4: the base group of the customers (K5).
    case CustomerGroupMissing = 'customerGroupMissing';

    // D-5: retention (L2) and the announcement of automatic anonymisation (L5).
    case RetentionPeriodMissing = 'retentionPeriodMissing';
    case AnonymizationDue = 'anonymizationDue';

    // D-7: statuses — a cancelling automatic target (M4), a status the
    // balance no longer bears (M3).
    case AutomaticStatusCancels = 'automaticStatusCancels';
    case StatusContradictsBalance = 'statusContradictsBalance';

    // D-7: the sender domain is none of the shop's domains (M5).
    case SenderDomainMismatch = 'senderDomainMismatch';

    // E-2: an invoice past its payment term, still unpaid (Z5).
    case InvoiceOverdue = 'invoiceOverdue';

    // Spec G, E15: prepayment unpaid for two weeks, holding its stock.
    case PrepaymentUnpaid = 'prepaymentUnpaid';

    // E-3: cash on delivery with a fee and no choice how it is taxed (Z3).
    case PaymentFeeTaxMissing = 'paymentFeeTaxMissing';

    // E-5: a delivery country of the shop that no shipping method reaches (V3).
    case DeliveryCountryWithoutShipping = 'deliveryCountryWithoutShipping';

    // E-6: the shipping costs page (V5) — not chosen, or chosen and empty.
    case ShippingCostsPageMissing = 'shippingCostsPageMissing';
    case ShippingCostsElementMissing = 'shippingCostsElementMissing';

    // F-5: an article delivered as a file without a file to hand out.
    case DigitalFileMissing = 'digitalFileMissing';

    // Block Lizenz (D7): a delivery type of a module that cannot deliver —
    // for every article (key pair, a server setting) or for some (a setting
    // at the article). One finding for every module, so the core keeps deciding
    // how loud it is and who sees it (DeliveryReadinessIssueSource).
    case DeliveryTypeNotReady = 'deliveryTypeNotReady';
    case DeliveryArticleNotReady = 'deliveryArticleNotReady';

    // Block G (G11): kept stock or a key pool at or below its threshold, or
    // gone — one finding since G, it replaces F-6's keyPoolLow.
    case StockLow = 'stockLow';

    // Abo-1: a sale mode on offer without a price (A4). Its sister "only
    // consumers cannot subscribe" went with the fixed business-only lock
    // (variant A, 2026-10-08).
    case SaleModePriceMissing = 'saleModePriceMissing';

    // Abo-2b (P91): sold only by plans for some customer groups — listings
    // show plans open to everyone, so the product is in none.
    case SubscriptionNotListed = 'subscriptionNotListed';

    // Variant A (A1): a rule renewing for the full minimum term open beyond
    // business customers (SameTermOpenIssueSource).
    case SubscriptionSameTermOpen = 'subscriptionSameTermOpen';

    // Abo-4 (P24): a shop selling subscriptions without an "on account"
    // method — its follow-up orders are not created.
    case SubscriptionBillingMethodMissing = 'subscriptionBillingMethodMissing';

    // Acceptance Abo-4: a start point without a domain — mails from the cron
    // and the console link to localhost.
    case StartPointWithoutDomain = 'startPointWithoutDomain';

    // Acceptance GARAN (2026-10-09): a start point forwards to its first page,
    // and that is a product page without a product — the domain answers 500
    // (StartPointProductPageIssueSource).
    case StartPointForwardsToProductPage = 'startPointForwardsToProductPage';

    // Abo-5 (P38): subscriptions resting for an unpaid invoice.
    case SubscriptionsSuspended = 'subscriptionsSuspended';

    // Abo-7 (P63): a shop selling subscriptions without a reachable page with
    // "Verträge hier kündigen" (§ 312k BGB).
    case CancellationPageMissing = 'cancellationPageMissing';

    // Abo-7 (P57): extraordinary cancellations waiting for a decision.
    case CancellationsWaiting = 'cancellationsWaiting';

    // E10: a decision on a cancellation whose mail did not leave.
    case DecisionNotSent = 'decisionNotSent';

    // Abo-Nachlese (P74, review S1): a subscription customer names another
    // country in the account; follow-up invoices keep the previous address.
    case SubscriptionCountryChanged = 'subscriptionCountryChanged';

    // Abo-4b (P99): subscriptions whose mandate charges through a payment
    // method the shop no longer offers — they are billed "on account".
    case MandateMethodUnavailable = 'mandateMethodUnavailable';

    // Dienstleistung (DL5): a product carries what its type's kind does not
    // use — a delivery, file or keys on a service or physical good, a
    // subscription on a physical good.
    case ProductKindMismatch = 'productKindMismatch';

    // Block GARAN (GA7): a manufacturer's durability guarantee without its
    // label, or a label without a duration (DurabilityGuaranteeIssueSource).
    case DurabilityLabelMissing = 'durabilityLabelMissing';
    case DurabilityYearsMissing = 'durabilityYearsMissing';
    // Review and acceptance GARAN: a label the web cannot reach, one too heavy
    // for a mail, a value at a variant that does nothing.
    case DurabilityLabelNotPublic = 'durabilityLabelNotPublic';
    case DurabilityLabelTooLarge = 'durabilityLabelTooLarge';
    case DurabilityOnVariant = 'durabilityOnVariant';
    // GA8: a nested label that never shows — no full label to open, or a
    // folder the web cannot reach.
    case DurabilityNestedUnused = 'durabilityNestedUnused';

    // GARAN part 2 (L4, L5): the frame of the customer mails — no sender in
    // the foot, a logo too heavy for every mail (MailFrameIssueSource).
    case MailFooterEmpty = 'mailFooterEmpty';
    case MailLogoTooLarge = 'mailLogoTooLarge';

    // Block Wertgutscheine (WG3): the kind "Wertgutschein" and the class
    // "nicht steuerbar" belong together (VoucherProductIssueSource).
    case VoucherTaxed = 'voucherTaxed';
    case NotTaxableClassOnGoods = 'notTaxableClassOnGoods';
    case VoucherValueMissing = 'voucherValueMissing';

    // Block R (O2, R6): the OSS threshold in sight while the business taxes
    // where the shop is (OssThresholdIssueSource).
    case OssThresholdNear = 'ossThresholdNear';
    case OssThresholdReached = 'ossThresholdReached';

    // Block R (R10): e-invoices on, a required detail emptied since
    // (EInvoiceIssueSource).
    case EInvoiceIncomplete = 'eInvoiceIncomplete';

    // Nachlese (KA5, KA6, E2): customers who cannot get an account, a customer
    // page that locks them out, and no way back from a login (CustomerAccess-
    // IssueSource).
    case RegistrationPathMissing = 'registrationPathMissing';
    case RegistrationPathMissingNoGuests = 'registrationPathMissingNoGuests';
    case CustomerPageLocksOut = 'customerPageLocksOut';
    case LoginPageMissing = 'loginPageMissing';

    /**
     * The back end modules in which the finding is put right (Michael,
     * 2026-10-07, H1): a user sees it if they may open ONE of them. An empty
     * list is for what no module repairs — a console command, an environment
     * variable — and only administrators see it.
     *
     * No default arm on purpose (H3): a new finding without an answer here is
     * an UnhandledMatchError in the test that walks every case, not a note
     * that quietly reaches nobody.
     *
     * `page` and `article` are Contao's own modules: start points and the
     * pages chosen there, and the content elements on them.
     *
     * @return list<string>
     */
    public function fixedIn(): array
    {
        return match ($this) {
            self::TaxRateMissing => ['consho_shop', 'consho_tax'],
            self::DuplicateVatId, self::HomeCountryMissing, self::MultipleShopsShareMembers, self::NetPriceGroupMissing,
            self::InvoiceSenderIncomplete, self::NumberRangeMissing, self::NumberPrefixShared, self::InvoiceArchiveFolderUnsafe,
            self::ReverseChargeNoteMissing, self::DeliveryTimeMissing, self::OperatorAddressIsSender,
            self::WithdrawalPeriodShort, self::RefundRangeMissing, self::CustomerGroupMissing,
            self::RetentionPeriodMissing, self::AutomaticStatusCancels, self::EInvoiceIncomplete => ['consho_shop'],
            self::MailTransportMissing, self::SenderDomainMismatch => ['consho_shop', 'consho_mail'],
            self::ShippingPriceMissing, self::ShippingMethodMissing, self::AgeGateMissing,
            self::DeliveryCountryWithoutShipping => ['consho_shop', 'consho_shipping'],
            self::PaymentMethodMissing, self::SubscriptionBillingMethodMissing => ['consho_shop', 'consho_payment'],
            self::PaymentFeeTaxMissing => ['consho_payment'],
            self::ProductManufacturerMissing, self::DuplicateGtin, self::TranslationMissing,
            self::TranslationSafetyMissing, self::ProductShopMissing, self::ProductPriceMissing, self::PriceTie, self::PriceCutAsPromotion,
            self::GroupPriceAboveList, self::DigitalFileMissing, self::SaleModePriceMissing,
            self::ProductKindMismatch, self::VoucherTaxed, self::NotTaxableClassOnGoods,
            self::VoucherValueMissing, self::DurabilityLabelMissing, self::DurabilityYearsMissing,
            self::DurabilityOnVariant, self::DeliveryArticleNotReady => ['consho_product'],
            self::DurabilityLabelNotPublic, self::DurabilityLabelTooLarge, self::DurabilityNestedUnused => ['consho_product', 'files'],
            self::MailFooterEmpty => ['consho_shop'],
            self::MailLogoTooLarge => ['consho_shop', 'files'],
            self::SubscriptionNotListed => ['consho_product', 'consho_subscription_rule'],
            self::SubscriptionSameTermOpen => ['consho_subscription_rule'],
            self::ManufacturerEmailMissing, self::ManufacturerEuResponsibleMissing => ['consho_manufacturer'],
            self::StockLow => ['consho_stock'],
            self::WithdrawalsOpen, self::WithdrawalsUnassigned => ['consho_withdrawal'],
            self::InvoiceArchiveFailed, self::ConfirmationNotSent, self::AnonymizationDue,
            self::StatusContradictsBalance, self::InvoiceOverdue, self::PrepaymentUnpaid => ['consho_order'],
            // The tax report is order data (review A1); the switch "Steuer im
            // Bestimmungsland" sits at the shop.
            self::OssThresholdNear, self::OssThresholdReached => ['consho_order', 'consho_shop'],
            self::SubscriptionsSuspended, self::CancellationsWaiting, self::DecisionNotSent, self::SubscriptionCountryChanged => ['consho_subscription'],
            self::MandateMethodUnavailable => ['consho_subscription', 'consho_payment'],
            self::RootWithoutCurrency, self::RootCurrencyNotSold, self::CurrencyMissing => ['page', 'consho_shop'],
            self::PathTemplateInvalid, self::CatalogPageAlwaysCached, self::CheckoutPageMissing, self::CartPageMissing,
            self::OrderPageMissing, self::PrivacyPageMissing, self::WarrantyNoticeMissing,
            self::WarrantyNoticeFileMissing, self::WithdrawalFunctionMissing, self::WithdrawalFunctionAdvised,
            self::ShippingCostsPageMissing, self::StartPointWithoutDomain, self::StartPointForwardsToProductPage, self::CancellationPageMissing,
            self::CustomerPageLocksOut, self::LoginPageMissing => ['page'],
            // The registration is a front end module of a theme.
            self::RegistrationPathMissing, self::RegistrationPathMissingNoGuests => ['themes'],
            self::PinnedProductMissing, self::MiniCartMissing, self::CheckoutElementMissing, self::CartElementMissing,
            self::OrderElementMissing, self::ShippingCostsElementMissing => ['article'],
            self::LegalTextMissing, self::LegalTextUnreachable, self::LegalTextAdvised => ['page', 'article'],
            // A missing key pair or sales domain is put right on the server
            // (D7): no module repairs it.
            self::ListPriceDeviation, self::VisibilityDeviation, self::NoticeParamStripped, self::TestPaymentActive,
            self::DeliveryTypeNotReady => [],
        };
    }

    /**
     * Whether this needs attention now or is merely worth knowing.
     *
     * DuplicateVatId is deliberately the lighter one: two brands of one company
     * may run separate invoice sequences, so the finding has to ask rather than
     * accuse. A missing tax rate is different — a country the shop offers
     * cannot be priced, and an order from it will fail.
     */
    public function isWarning(): bool
    {
        return match ($this) {
            self::TaxRateMissing, self::MailTransportMissing => true,

            /*
             * Without a home country there is no gross (C25): the shop shows
             * no price anywhere and sells nothing. Nothing to judge.
             */
            self::HomeCountryMissing => true,

            /*
             * The GPSR findings are warnings, not notes. Offering a physical
             * product without the manufacturer details is a breach of Art. 19
             * at every single offer — not a matter of taste, and cheap to fix
             * once someone knows.
             */
            self::ProductManufacturerMissing,
            self::ManufacturerEmailMissing,
            self::ManufacturerEuResponsibleMissing => true,

            /*
             * A note, like the duplicate VAT id and for the same reason: two
             * products sharing a GTIN is almost always a mistake, but only
             * almost — a shop may carry the same article twice during a
             * changeover. A finding that accuses where it should ask gets
             * ignored, and then the accusing ones go unread too.
             */
            self::DuplicateVatId, self::DuplicateGtin => false,

            // Two companies may share a prefix; the core cannot tell (C-12).
            self::NumberPrefixShared => false,

            /*
             * A product that has a translation row with an address but no
             * translated safety notices is PUBLISHED AND STILL INVISIBLE in
             * that language (VisibilityCriteria, rule 4). Without a warning the
             * operator looks for a bug in the shop. And a warning cannot be
             * acknowledged away, which is right for a legal requirement.
             *
             * A product without any translation is merely absent there — often
             * on purpose, so the operator may take note of it.
             */
            self::TranslationSafetyMissing => true,
            self::TranslationMissing => false,

            /*
             * A projected list price that does not match the prices hides a
             * product from its listing or sorts it by a price it does not have
             * (A-4b). Nothing about it is a matter of judgement, and
             * consho:price:rebuild fixes it — so nothing to acknowledge.
             */
            self::ListPriceDeviation => true,

            /*
             * The same for the visibility projection (B-10): a row that does
             * not match the catalogue keeps a product out of its listing, shows
             * one that is gone, or sorts it by a "from" price it does not have.
             * consho:visibility:rebuild fixes it — nothing to acknowledge.
             */
            self::VisibilityDeviation => true,

            /*
             * A pin pointing at nothing renders nothing; the listing and the
             * shop work on. Worth knowing, and a deleted product is often a
             * deliberate one — so a note.
             */
            self::PinnedProductMissing => false,

            /*
             * Published and never listed (B-8). "Nirgends gelistet" is not
             * "unreachable", and it can be deliberate — an article sent to
             * regular customers by link only. A note.
             */
            self::ProductShopMissing, self::ProductPriceMissing => false,

            /*
             * Two price rows of equal rank at once: the price is whichever
             * row comes first. Worth fixing, but the shop sells on and the
             * deciding rule is block H's — a note.
             */
            self::PriceTie => false,

            /*
             * A cheaper row with a start and no end shows as a promotion with
             * a struck price for good. Usually a lasting cut was meant, but a
             * promotion "until further notice" can be too — a note.
             */
            self::PriceCutAsPromotion => false,

            /*
             * A stored path template the route cannot use (B, written past the
             * mask). The page falls back to the default template, so every
             * product of it answers under another address than the one
             * configured — without a redirect. Nothing to judge, a warning.
             */
            self::PathTemplateInvalid => true,

            /*
             * A selected shipping method without an amount in the shop's
             * currency is not offered (FlatRateShipping). If it was the only
             * one, nobody can order — nothing to judge, a warning (spec 11.3).
             */
            self::ShippingPriceMissing => true,

            /*
             * "Back to the page" without a mini basket anywhere: the shop
             * still works, adding an article just looks like nothing
             * happened to the visitor (spec 11.2, 11.3). Nothing fails, so
             * a hint rather than a warning.
             */
            self::MiniCartMissing => false,

            /*
             * An allow list that does not carry Consho's query parameters.
             * Until C-10 that was consho_notice alone, and only a refusal went
             * unseen — a hint. Since the review of C-10 it covers every
             * parameter the shop reads from the address: the checkout step,
             * the purchase outcome and the order page's token. Stripped, the
             * checkout never leaves its first step and every link to an order
             * page is a 404. A WARNING therefore — but not blocking: the
             * allow list is the operator's decision about their own cache,
             * nothing the shop can put right.
             */
            self::NoticeParamStripped => true,

            /*
             * A net price group that no longer exists (C20, C-4): the shop
             * prices and sells exactly as before, the group is simply skipped
             * when deciding who reads net. A hint.
             *
             * ⚠️ It is the least harmless of the hints, and that is on purpose
             * rather than an oversight. A trade customer who should read net
             * reads gross instead — visible to them, invisible to the operator,
             * and nothing breaks that would point at the cause. A warning would
             * be wrong all the same: it would block the checkout of a shop
             * whose prices are correct, over a display setting.
             */
            self::NetPriceGroupMissing => false,

            /*
             * A page set to "always load from cache" in a shop that prices per
             * customer (C-4): a member is handed the guest's page, gross tiles
             * beside a net mini basket. Every amount shown is a real price of
             * this shop, just the wrong one for this visitor, and the operator
             * ticked the box themselves — so a hint, not a lock on a checkout
             * that works.
             */
            self::CatalogPageAlwaysCached => false,

            /*
             * A customer group paying more than everyone else (C-4). Allowed
             * on purpose — it follows from "specificity decides, not the
             * smaller number" (block A, 5.1), and a group buying single units
             * where the public price is a pallet rate is a real shop.
             *
             * Usually it is a typo, or a list price lowered later while the
             * group kept its old one. A hint says so without accusing; a
             * warning would block the checkout of a shop that is doing exactly
             * what it was told to.
             */
            self::GroupPriceAboveList => false,

            /*
             * All four block the checkout of the shop they belong to (11.2).
             * They are not matters of taste: without a checkout page there is
             * nowhere to go from the basket, without an element the page is
             * blank, and without a published shipping or payment method the
             * calculation ends in "no method available" — at the end of the
             * visitor's typing, not before it.
             */
            self::CheckoutPageMissing,
            self::CheckoutElementMissing,
            self::ShippingMethodMissing,
            self::PaymentMethodMissing => true,

            /*
             * A published article from 16 or 18 that NOBODY can order (C19):
             * no age-verified group, no shipping method that checks. It blocks
             * that article, not the shop — everything else sells on, which is
             * why it is its own finding and not part of the four above.
             *
             * A warning all the same. The article is on the shelf and cannot
             * be bought, and the reason is two screens away in the shop
             * configuration; an operator would look for a bug in the basket.
             */
            self::AgeGateMissing => true,

            /*
             * The C-6 findings, all seven warnings and all seven blocking the
             * checkout of their shop (11.3). Each one would otherwise be found
             * by the buyer rather than by the operator:
             *
             * - no basket or order page: the way there ends, or the way back
             *   after the purchase does;
             * - a legal text missing or unreachable: § 8 FAGG wants the terms
             *   and § 4 FAGG the withdrawal instruction BEFORE the order, and
             *   without them the withdrawal period does not start (§ 12 FAGG);
             * - no privacy page: Art. 13 DSGVO asks for it at the moment the
             *   data is collected;
             * - an incomplete invoice sender: § 11 UStG / § 14 UStG wants the
             *   full name and address of the issuer on every invoice, and the
             *   invoice is written at the buy click;
             * - no number range where a payment method issues on order: the
             *   purchase would run into the invoice and stop there, after the
             *   order exists.
             *
             * None of them is a matter of judgement, and none can be
             * acknowledged away — legal requirements are not a taste.
             */
            self::CartPageMissing,
            self::CartElementMissing,
            self::OrderPageMissing,
            // C-10 (E5): an order page without its element sends the buyer
            // from the purchase and from the confirmation mail to nothing.
            self::OrderElementMissing,
            self::LegalTextMissing,
            self::LegalTextUnreachable,
            self::PrivacyPageMissing,
            self::WarrantyNoticeMissing,

            /*
             * C-9: the same notice as a FILE for the confirmation. The
             * confirmation has to carry it (Art. 8 Abs. 7 lit. a Directive
             * 2011/83/EU, § 7 Abs 3 FAGG) — without the file every order
             * would be confirmed incompletely. Blocking like the page.
             */
            self::WarrantyNoticeFileMissing,
            self::InvoiceSenderIncomplete,
            self::NumberRangeMissing => true,

            /*
             * The three invoice findings of C-8 — warnings, but none of them
             * blocks the checkout (DbalInvoiceIssueSource does not name them
             * to CheckoutReadiness):
             *
             * - a failed archive leaves the order and the invoice intact; only
             *   the stored copy is missing, and retrieval says so;
             * - an unsafe archive folder stops the ARCHIVE, never the sale:
             *   DocumentArchiver refuses to write into a public folder;
             * - a missing reverse charge note concerns the rare order with a
             *   verified VAT ID across a border. Blocking every sale for it
             *   would punish the ordinary buyer for the operator's gap.
             *
             * Not acknowledgeable either: each names something that has to
             * be done, not a judgement to accept.
             */
            self::InvoiceArchiveFailed,
            self::InvoiceArchiveFolderUnsafe,
            self::ReverseChargeNoteMissing,

            /*
             * Not blocking either, although it concerns a mandatory detail:
             * the basis — UStR 2000 Rz 1524, the expected period on an invoice
             * issued before delivery — was read only through a secondary
             * source (2026-09-24). A block would stop every sale on a reading
             * nobody has checked against the text yet.
             */
            self::DeliveryTimeMissing => true,

            /*
             * C-9. A confirmation that did not go out is a warning: the law
             * asks for it (§ 7 Abs 3 FAGG), and "Erneut senden" is one click
             * away. It does NOT block the checkout — the next order's mail
             * may well go through, and stopping sales over a past one would
             * punish the next buyer (not in CheckoutReadiness::BLOCKING).
             *
             * The operator writing to their own address is a hint: on
             * Microsoft 365 the copy lands under "Sent" only, elsewhere it
             * arrives, and the shop sells either way.
             */
            self::ConfirmationNotSent => true,
            self::OperatorAddressIsSender => false,

            /*
             * C-11 (spec 11.3): the test provider confirms payments without
             * taking money. A warning, because on a real shop that is an
             * order marked paid that nobody paid — but not blocking: on the
             * test installation it is exactly what is wanted, and a lock
             * there would stop the very test it was switched on for.
             */
            self::TestPaymentActive => true,

            /*
             * The three currency findings of C-1c, all three warnings for the
             * same reason as the missing home country: below such a start page
             * nothing can be priced, or it is priced in money the shop cannot
             * take. Spec section 4.0 rules out falling back on "some" currency
             * — a price in the wrong one is worse than no shop.
             *
             * RootWithoutCurrency and CurrencyMissing look at the same gap from
             * two ends, and both are needed: the first names the PAGE and can
             * therefore say where to click, the second names the SHOP and also
             * covers the one whose start pages nobody has created yet. Where
             * both apply they overlap; that is the price of each saying
             * something the other cannot.
             */
            self::RootWithoutCurrency,
            self::RootCurrencyNotSold,
            self::CurrencyMissing => true,

            /*
             * 🔴 A note, and never anything else. Several shops are allowed and
             * intended — a warning would block their checkout (block C, 11.2)
             * for a configuration this bundle expressly supports. What the
             * operator has to know is what it implies: members, addresses and
             * VAT ids live in one tl_member, so all shops of an installation
             * need ONE controller within the meaning of the GDPR (block A,
             * 4.0). Two unrelated companies need two installations, and that
             * is a decision, not a defect.
             */
            self::MultipleShopsShareMembers => false,

            /*
             * D-2, decision E1 (Michael, 2026-09-27: "Sperre bei AGB ist schon
             * falsch, man muss keine haben. Sonst nur sperrend, wenn man in
             * der EU Betreiber ist.").
             *
             * The function "Vertrag widerrufen" missing where the shop sits in
             * the EU/EEA is a warning AND blocks (CheckoutReadiness) — like
             * the withdrawal instruction under the same condition.
             *
             * Outside the EU/EEA, or for terms nobody has to agree to, the
             * same gaps are notes: the law this code can read does not demand
             * them, and the operator decides.
             *
             * A period under 14 days while the shop delivers into the EU/EEA
             * (W4) is a warning that never blocks (Michael: "für einen shop in
             * der eu unter dem default eine warnung zeigen") — which law
             * applies to a buyer is a question about the buyer, and the
             * operator may know better.
             */
            self::WithdrawalFunctionMissing, self::WithdrawalPeriodShort => true,
            self::LegalTextAdvised, self::WithdrawalFunctionAdvised => false,

            /*
             * D-3. Open withdrawals are a warning — a refund falls due 14 days
             * after receipt — and a missing range for refunds too (F9): the
             * booking would stop at it. Neither blocks the checkout (not in
             * CheckoutReadiness::BLOCKING): selling has nothing to do with
             * either.
             */
            self::WithdrawalsOpen, self::RefundRangeMissing, self::WithdrawalsUnassigned => true,

            /*
             * D-4 (K5). The field is mandatory, but only a save asks for it —
             * a shop from before D-4 has none until then. Nothing fails without
             * it: accounts without an order are merely missing from "Kunden".
             * A note.
             */
            self::CustomerGroupMissing => false,

            /*
             * D-5. Without a period nothing is ever anonymised, and an
             * erasure request can only be noted — a warning, as it is a
             * legal duty, but no block on the checkout: selling does not
             * depend on it. The announcement is a note: it is what the
             * operator asked for (L5), said beforehand.
             */
            self::RetentionPeriodMissing => true,
            self::AnonymizationDue => false,

            /*
             * The automatic change into a cancelling status never happens
             * (OrderStatusIssueSource) — a setting that silently does nothing
             * is a warning. A status the balance no longer bears is a note:
             * which status fits is the operator's call, and an old order may
             * keep its status on purpose (D-7, M3).
             */
            self::AutomaticStatusCancels => true,
            self::StatusContradictsBalance => false,

            // A shop may send from the company's main domain on purpose.
            self::SenderDomainMismatch => false,

            // E-2 (Z5): a late payment is the operator's to chase or to wait
            // for; nothing is misconfigured. A note.
            self::InvoiceOverdue => false,

            // E15: likewise — waiting longer for a transfer is a choice.
            self::PrepaymentUnpaid => false,

            // The method is silently missing from the checkout until the
            // operator decides (E-1, Z3). A warning.
            self::PaymentFeeTaxMissing => true,

            // The checkout no longer offers a country the operator ticked
            // (E-5, V3). Said loudly, so it is not mistaken for a decision.
            self::DeliveryCountryWithoutShipping => true,

            // E-6 (V5): not chosen is a note — Consho sets no legal texts and
            // does not decide which statements a shop owes. Chosen but empty
            // is a warning: the operator meant a page that shows nothing.
            self::ShippingCostsPageMissing => false,
            self::ShippingCostsElementMissing => true,

            // Orders of these articles are not released (F-5): a warning.
            self::DigitalFileMissing => true,

            // Likewise for a module's delivery type (D7): the orders wait, and
            // nobody is told. Warnings, not blocking — like the missing file,
            // the order stands and is released once it is put right.
            self::DeliveryTypeNotReady, self::DeliveryArticleNotReady => true,

            // Sold out is quiet on the shop's side: the page says so, nobody
            // buys, nothing fails (G11). A warning.
            self::StockLow => true,

            // The product cannot be bought that way (Abo-1, A4) — a warning.
            self::SaleModePriceMissing => true,
            // Right in a closed shop (Abo-2b, P91) — a note.
            self::SubscriptionNotListed => false,
            // May be meant in another country (Michael: „Hinweis statt Sperre").
            self::SubscriptionSameTermOpen => false,

            // Nobody bills what the customer keeps using (Abo-4) — a warning.
            self::SubscriptionBillingMethodMissing => true,

            // The mail goes out and its links lead nowhere; nothing fails, so
            // nobody sees it (acceptance Abo-4) — a warning.
            self::StartPointWithoutDomain => true,

            // The start point's own address is an error page, and nothing in
            // the back end shows it — a warning.
            self::StartPointForwardsToProductPage => true,

            // Following up is the operator's, like an overdue invoice (P38).
            self::SubscriptionsSuspended => false,

            // A statutory duty the shop fails where nobody looks (P63) — a
            // warning, not blocking: Consho sets no legal texts and does not
            // judge where § 312k applies.
            self::CancellationPageMissing => true,

            // The customer waits for an answer, like an open withdrawal (F11).
            self::CancellationsWaiting => true,

            // E10: the customer does not know how the cancellation ended —
            // like a confirmation that did not go out.
            self::DecisionNotSent => true,

            // Invoices go to an address the customer has left (review S1) —
            // a warning.
            self::SubscriptionCountryChanged => true,

            // The customer chose to be charged and gets invoices (P99) — a
            // warning.
            self::MandateMethodUnavailable => true,

            // Nothing goes wrong — release and sale modes ignore what the kind
            // does not use (DigitalRelease, SaleModes) — but the mask hides
            // it, so it would never be seen. A note.
            self::ProductKindMismatch => false,

            // The guarantee is owed before the purchase in the harmonised
            // form (§ 4 Abs. 1 Z 12a, § 8 Abs. 1 FAGG), and the shop shows
            // nothing that says it is missing (GA7).
            self::DurabilityLabelMissing, self::DurabilityYearsMissing, self::DurabilityLabelNotPublic => true,

            // Nothing is missing; a mail grows heavy, or a value does nothing.
            // The nested label is optional — the full one stands on the
            // product page either way (GA8).
            self::DurabilityLabelTooLarge, self::DurabilityOnVariant, self::DurabilityNestedUnused => false,

            // The mails go out either way; whether a sender is owed in them
            // is the operator's judgement (L5), a heavy logo only a risk (L4).
            self::MailFooterEmpty, self::MailLogoTooLarge => false,

            // Tax charged where none is due, or none where it is (WG3) —
            // money at every sale. Warnings.
            self::VoucherTaxed, self::NotTaxableClassOnGoods => true,

            // The voucher is worth what was paid for it — usually its price,
            // so most likely right, but not said. A note.
            self::VoucherValueMissing => false,

            // Reports, does not warn (O2): sales outside Consho count too, and
            // switching is the operator's decision. Notes.
            self::OssThresholdNear, self::OssThresholdReached => false,

            // Invoices go out without the XML a business customer may need.
            self::EInvoiceIncomplete => true,

            // A subscription needs an account (P12), a customer page a group it
            // admits — without them the customer is turned away and nothing is
            // logged (acceptance Abo-8). Warnings, not blocking: a guest still
            // buys once, and whether the shop wants accounts is the operator's.
            // Without guest checkout no new customer buys at all — still a
            // warning, since accounts created by hand need no registration.
            self::RegistrationPathMissing, self::RegistrationPathMissingNoGuests,
            self::CustomerPageLocksOut, self::LoginPageMissing => true,
        };
    }
}
