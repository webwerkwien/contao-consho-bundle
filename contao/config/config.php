<?php declare(strict_types=1);

use Webwerkwien\ContaoConshoBundle\Model\CartItemModel;
use Webwerkwien\ContaoConshoBundle\Model\CartModel;
use Webwerkwien\ContaoConshoBundle\Model\CustomFieldModel;
use Webwerkwien\ContaoConshoBundle\Model\DeliveryItemModel;
use Webwerkwien\ContaoConshoBundle\Model\DeliveryModel;
use Webwerkwien\ContaoConshoBundle\Model\DocumentModel;
use Webwerkwien\ContaoConshoBundle\Model\FacetPageModel;
use Webwerkwien\ContaoConshoBundle\Model\UrlRedirectModel;
use Webwerkwien\ContaoConshoBundle\Model\ManufacturerModel;
use Webwerkwien\ContaoConshoBundle\Model\OrderItemModel;
use Webwerkwien\ContaoConshoBundle\Model\OrderModel;
use Webwerkwien\ContaoConshoBundle\Model\OrderStatusModel;
use Webwerkwien\ContaoConshoBundle\Model\MailTransportModel;
use Webwerkwien\ContaoConshoBundle\Model\OrderTokenModel;
use Webwerkwien\ContaoConshoBundle\Model\PaymentMethodModel;
use Webwerkwien\ContaoConshoBundle\Model\PaymentModel;
use Webwerkwien\ContaoConshoBundle\Model\PriceModel;
use Webwerkwien\ContaoConshoBundle\Model\ProductModel;
use Webwerkwien\ContaoConshoBundle\Model\ProductOptionModel;
use Webwerkwien\ContaoConshoBundle\Model\ProductPropertyModel;
use Webwerkwien\ContaoConshoBundle\Model\ProductShopModel;
use Webwerkwien\ContaoConshoBundle\Model\ProductTypeModel;
use Webwerkwien\ContaoConshoBundle\Model\PropertyGroupModel;
use Webwerkwien\ContaoConshoBundle\Model\PropertyOptionModel;
use Webwerkwien\ContaoConshoBundle\Model\ShippingMethodModel;
use Webwerkwien\ContaoConshoBundle\Model\ShippingPriceModel;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Model\TaxClassModel;
use Webwerkwien\ContaoConshoBundle\Model\TaxRateModel;
use Webwerkwien\ContaoConshoBundle\Model\TranslationModel;

/*
 * Back end modules.
 *
 * ⚠️ The groups are PREPENDED, not appended.
 *
 * $GLOBALS['BE_MOD'] is a plainly ordered array, so the usual
 * `$GLOBALS['BE_MOD']['consho'][...] = ...` lands the group at the very bottom,
 * below Contao's own content, design, account and system groups. For someone
 * running a shop, the shop is the first thing they came for — it belongs at the
 * top.
 *
 * The trade-off, recorded so it can be revisited: on a site where the shop is
 * one part among many, this pushes the page tree down. Should that ever matter,
 * the position is the place to make configurable — not the code around it.
 *
 * ── Why THREE groups and not one ──────────────────────────────────────────
 *
 * Contao's back end navigation has exactly two levels: category and module
 * (see BackendMainListener). There is no divider element inside a category, so
 * a single "Consho" group can only ever be one undifferentiated list.
 *
 * The category IS the divider — and a better one than a rule would be: each
 * category carries its own heading and collapses independently, with the state
 * remembered per user. Once a shop is set up, "Consho-Einrichtung" can stay
 * folded away for good.
 *
 * The three of them answer three different questions:
 *
 *   Consho              What do I do TODAY?          Orders.
 *   Consho-Stammdaten   What do I keep up to date?   Products, manufacturers.
 *   Consho-Einrichtung  What did I set up ONCE?      Everything else.
 *
 * Order differs per group because the question differs. Within the first two it
 * is frequency of use. Within the third it is the order in which a shop has to
 * be set up, because that group is read top to bottom exactly once — and each
 * entry depends on the ones above it:
 *
 *   1. Shop configuration   currency, number ranges, order states — nothing
 *                           else works without it
 *   1a. Mail configuration  the mailbox a shop configuration points at
 *   2. Taxes                a product cannot be saved without a tax class
 *   2a. Shipping methods    need a tax class and a shop currency; the shop
 *                           configuration selects them afterwards
 *   2b. Payment methods     likewise selected by the shop configuration
 *   3. Properties           referenced by a product type
 *   4. Custom fields        likewise
 *   5. Product types        wires 3 and 4 together, so it comes last
 *
 * Note the deliberate tension: the setup chain ends where the catalogue begins,
 * yet the catalogue sits ABOVE it in the menu. That is on purpose. The setup
 * order matters once; the daily order matters every day.
 */
$conshoBackendModules = [
    // ── Daily operation ──────────────────────────────────────────────────
    'consho' => [
        /*
         * Block Dashboard (DB1): the first item of the group — what is open,
         * the findings, the figures. Contao's own start page stays.
         */
        'consho_dashboard' => [
            'callback' => Webwerkwien\ContaoConshoBundle\Controller\Backend\DashboardModule::class,
        ],

        'consho_order' => [
            'tables' => [
                'tl_consho_order',
                'tl_consho_order_item',
                'tl_consho_payment',
                'tl_consho_delivery',
                'tl_consho_delivery_item',
                'tl_consho_document',

                // Required: the `legal` operation of tl_consho_order points at it
                // (C-7), and Contao refuses a table a module does not list ("Table …
                // is not allowed in module …") — without it the button answers with
                // a 403 every time.
                'tl_consho_order_legal',

                // D-2.
                'tl_consho_withdrawal',
                'tl_consho_withdrawal_item',

                // D-3.
                'tl_consho_complaint',

                // F-4.
                'tl_consho_entitlement',
            ],

            // D-3: the page "Widerruf bearbeiten" — decide, return, refund.
            'processWithdrawal' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\WithdrawalBackendController::class, '__invoke'],

            // The PDF and HTML buttons of a document (C-8). `key` actions
            // run after the module and table checks; the record is checked
            // in the controller.
            'invoicePdf' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\DocumentBackendController::class, 'pdf'],
            'invoiceHtml' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\DocumentBackendController::class, 'html'],

            // "Lieferschein" at a delivery (dev#2).
            'deliveryNotePdf' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\DocumentBackendController::class, 'deliveryNotePdf'],

            // "Erneut senden" at an order (C-9).
            'resendConfirmation' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\OrderConfirmationBackendController::class, 'resend'],

            // "Zahlungsziel verschieben" at an order (E8).
            'deferPayment' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\PaymentDeferralBackendController::class, '__invoke'],

            // "Als versandt melden" at a delivery (D-1).
            'reportDelivery' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\DeliveryReportBackendController::class, 'report'],

            // "Leistung erbracht" at a service line (Dienstleistung, DL3).
            'servicePerformed' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\ServicePerformedBackendController::class, 'report'],
        ],

        // Abo-3 (P20): the subscriptions, read-only. Right after the orders
        // they come from; handling a cancellation follows with Abo-6/Abo-7.
        'consho_subscription' => [
            'tables' => ['tl_consho_subscription', 'tl_consho_cancellation', 'tl_consho_subscription_pause'],
            // Abo pausieren (P101): announce or withdraw a pause.
            'pauseSubscription' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\SubscriptionPauseBackendController::class, '__invoke'],
            // Cancellations and pauses of one subscription, on one page.
            'subscriptionHistory' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\SubscriptionHistoryBackendController::class, '__invoke'],
            // E10: the decision mail on a cancellation once more.
            'resendDecision' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\DecisionResendBackendController::class, 'resend'],
        ],

        /*
         * D-3 (F11): the withdrawals of all orders in one
         * list. They are rare, which is exactly why they must not hide inside
         * an order — the refund is due 14 days after receipt. The same table
         * as under the order, listed flat here (WithdrawalListListener).
         */
        'consho_withdrawal' => [
            'tables' => ['tl_consho_withdrawal', 'tl_consho_withdrawal_item'],
            'processWithdrawal' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\WithdrawalBackendController::class, '__invoke'],
        ],

        /*
         * Block Wertgutscheine (WG13): the vouchers with their balance and
         * bookings; "Gutschein ausstellen" for goodwill, "Saldo korrigieren"
         * with a reason. Daily work like the withdrawals, hence this group.
         */
        'consho_voucher' => [
            'tables' => ['tl_consho_voucher', 'tl_consho_voucher_booking'],
            'issueVoucher' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\VoucherBackendController::class, 'issue'],
            'correctVoucher' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\VoucherBackendController::class, 'correct'],
        ],

        /*
         * Block G (G16, G-7): the stock — "auf der Ebene, wo auch die
         * Bestellungen sitzen" — in their group, after the
         * withdrawals: the subscriptions stay right after the orders (P20). The list of articles
         * with limited stock, their movements, and the purchase lists with
         * their lines; "Einkauf" builds one, "Eingang" books what came.
         */
        'consho_stock' => [
            'tables' => ['tl_consho_stock', 'tl_consho_stock_movement', 'tl_consho_purchase_list', 'tl_consho_purchase_item'],
            'purchase' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\PurchaseBackendController::class, 'purchase'],
            'purchaseOutput' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\PurchaseBackendController::class, 'output'],
            'purchaseReceive' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\PurchaseBackendController::class, 'receive'],
        ],

        /*
         * D-4 (K2, K6): who is a customer, computed from the orders and the
         * base group — no table of its own, hence a `callback`.
         */
        'consho_customer' => [
            'callback' => Webwerkwien\ContaoConshoBundle\Controller\Backend\CustomerModule::class,
        ],

        // Block R's "Steuermeldung" is a view of the dashboard since DB6
        // (`view=taxReport`), no longer a menu item.
    ],

    // ── Master data, maintained again and again ──────────────────────────
    'consho_data' => [
        'consho_product' => [
            // Every table reachable from this module has to be listed here, or
            // Contao answers "Table … is not allowed in module …" — the price
            // table is a child of the product and is edited from within it.
            // The translation table likewise: it is a ctable, and deleting a
            // product reaches it through this module.
            'tables' => ['tl_consho_product', 'tl_consho_price', 'tl_consho_translation', 'tl_consho_key'],
        ],

        // Manufacturers arrive with new suppliers — recurring upkeep, not
        // setup. After the products, because products are the commoner reach.
        'consho_manufacturer' => [
            'tables' => ['tl_consho_manufacturer', 'tl_consho_translation'],
        ],

        // Releasing a facet is recurring editorial work, not setting a shop up:
        // a search term turns up, a page is written for it, another is dropped.
        // That belongs next to the products, not three clicks deep in the
        // configuration.
        'consho_facet_page' => [
            'tables' => ['tl_consho_facet_page'],
        ],

        // Block H (H-3): promotions are recurring editorial work too — a sale
        // this weekend, a manufacturer's week next month.
        'consho_price_rule' => [
            'tables' => ['tl_consho_price_rule'],
        ],
        // Block H (H-4): codes and basket discounts, next to the price rules.
        'consho_cart_rule' => [
            'tables' => ['tl_consho_cart_rule'],
        ],
    ],

    // ── Set up once, in this order ───────────────────────────────────────
    'consho_setup' => [
        'consho_shop' => [
            // Same rule as the product module above: every table reachable from
            // here has to be listed. Backend::getBackendModule() checks the
            // table against this list and throws AccessDeniedException — the
            // list is the permission, not a hint.
            'tables' => ['tl_consho_shop', 'tl_consho_order_status', 'tl_consho_number_range'],
        ],

        // Before the shop configuration in the reading order? No — after it.
        // A shop is set up first and then told which mailbox to use; the mail
        // configuration itself depends on nothing and can be filled in at any
        // point, so it sits next to the thing that references it.
        'consho_mail' => [
            'tables' => ['tl_consho_mail_transport'],

            // "Testmail senden" (C-9).
            'testMail' => [Webwerkwien\ContaoConshoBundle\Controller\Backend\MailTransportTestController::class, '__invoke'],
        ],
        'consho_tax' => [
            'tables' => ['tl_consho_tax_class', 'tl_consho_tax_rate'],
        ],
        // After taxes: a shipping method needs a tax class, and its amounts a
        // shop currency. The shop configuration selects it afterwards (C2).
        'consho_shipping' => [
            'tables' => ['tl_consho_shipping_method', 'tl_consho_shipping_price', 'tl_consho_translation'],
        ],
        'consho_payment' => [
            'tables' => ['tl_consho_payment_method', 'tl_consho_translation'],
        ],
        // After payment: a subscription is paid by it (Abo, A5), and the
        // rules are chosen at the product like the methods at the shop.
        'consho_subscription_rule' => [
            'tables' => ['tl_consho_subscription_rule'],
        ],
        // tl_consho_translation in both: groups, options and product types are
        // translated in their own masks and delete their translations as
        // child rows — see the product module above.
        'consho_property' => [
            'tables' => ['tl_consho_property_group', 'tl_consho_property_option', 'tl_consho_translation'],
        ],
        'consho_custom_field' => [
            'tables' => ['tl_consho_custom_field'],
        ],
        'consho_product_type' => [
            'tables' => ['tl_consho_product_type', 'tl_consho_translation'],
        ],
    ],
];

// The union operator keeps the left-hand order and does not overwrite existing
// keys, so Contao's own groups follow unchanged.
$GLOBALS['BE_MOD'] = $conshoBackendModules + ($GLOBALS['BE_MOD'] ?? []);

// Block G (G-7, E5 a): a text field that suggests the values in use.
$GLOBALS['BE_FFL']['conshoSuggest'] = Webwerkwien\ContaoConshoBundle\Widget\SuggestField::class;

unset($conshoBackendModules);

/*
 * Model registration. Without this, Model::findByPk() cannot resolve the table
 * and fails with "no class for table".
 */
$GLOBALS['TL_MODELS']['tl_consho_shop'] = ShopModel::class;
$GLOBALS['TL_MODELS']['tl_consho_cart'] = CartModel::class;
$GLOBALS['TL_MODELS']['tl_consho_cart_item'] = CartItemModel::class;
$GLOBALS['TL_MODELS']['tl_consho_product'] = ProductModel::class;
$GLOBALS['TL_MODELS']['tl_consho_product_type'] = ProductTypeModel::class;
$GLOBALS['TL_MODELS']['tl_consho_property_group'] = PropertyGroupModel::class;
$GLOBALS['TL_MODELS']['tl_consho_property_option'] = PropertyOptionModel::class;
$GLOBALS['TL_MODELS']['tl_consho_custom_field'] = CustomFieldModel::class;
$GLOBALS['TL_MODELS']['tl_consho_product_property'] = ProductPropertyModel::class;
$GLOBALS['TL_MODELS']['tl_consho_product_option'] = ProductOptionModel::class;
$GLOBALS['TL_MODELS']['tl_consho_product_shop'] = ProductShopModel::class;
$GLOBALS['TL_MODELS']['tl_consho_tax_class'] = TaxClassModel::class;
$GLOBALS['TL_MODELS']['tl_consho_tax_rate'] = TaxRateModel::class;
$GLOBALS['TL_MODELS']['tl_consho_price'] = PriceModel::class;
$GLOBALS['TL_MODELS']['tl_consho_order_status'] = OrderStatusModel::class;
$GLOBALS['TL_MODELS']['tl_consho_order'] = OrderModel::class;
$GLOBALS['TL_MODELS']['tl_consho_order_item'] = OrderItemModel::class;
$GLOBALS['TL_MODELS']['tl_consho_payment'] = PaymentModel::class;
$GLOBALS['TL_MODELS']['tl_consho_delivery'] = DeliveryModel::class;
$GLOBALS['TL_MODELS']['tl_consho_delivery_item'] = DeliveryItemModel::class;
$GLOBALS['TL_MODELS']['tl_consho_document'] = DocumentModel::class;
$GLOBALS['TL_MODELS']['tl_consho_order_token'] = OrderTokenModel::class;
$GLOBALS['TL_MODELS']['tl_consho_mail_transport'] = MailTransportModel::class;
$GLOBALS['TL_MODELS']['tl_consho_facet_page'] = FacetPageModel::class;
$GLOBALS['TL_MODELS']['tl_consho_url_redirect'] = UrlRedirectModel::class;
$GLOBALS['TL_MODELS']['tl_consho_manufacturer'] = ManufacturerModel::class;
$GLOBALS['TL_MODELS']['tl_consho_translation'] = TranslationModel::class;
$GLOBALS['TL_MODELS']['tl_consho_shipping_method'] = ShippingMethodModel::class;
$GLOBALS['TL_MODELS']['tl_consho_shipping_price'] = ShippingPriceModel::class;
$GLOBALS['TL_MODELS']['tl_consho_payment_method'] = PaymentMethodModel::class;
