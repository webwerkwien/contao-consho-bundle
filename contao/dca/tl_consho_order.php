<?php declare(strict_types=1);

/*
 * The order comes into being when the customer clicks "buy", not when payment
 * succeeds. The contract is concluded at that point — and the alternative has a
 * well-documented failure mode: the payment provider's redirect breaks and a
 * paid order simply does not exist.
 *
 * Customer data is always complete here. A guest order has no member; it does
 * not have less data. Addresses are a frozen COPY, because an address on an
 * order is a historical fact rather than a reference — otherwise a customer
 * moving house rewrites last year's invoice.
 * See docs/block-a-fundament.md, sections 3.3 and 3.4.
 */
$GLOBALS['TL_DCA']['tl_consho_order'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ctable' => ['tl_consho_order_item', 'tl_consho_payment', 'tl_consho_delivery', 'tl_consho_document', 'tl_consho_order_legal', 'tl_consho_withdrawal', 'tl_consho_complaint', 'tl_consho_entitlement'],
        'enableVersioning' => true,

        /*
         * An order is a record of a purchase, not a template (D-1). Copying
         * one took its children along — DC_Table::copyChildren() asks only
         * doNotCopyRecords, not notCopyable — and produced payments nobody
         * made and parcels nobody sent, locked and undeletable (second Fable
         * review of D-1, S-a).
         */
        'notCopyable' => true,

        /*
         * Orders are not deletable (C-7).
         *
         * Two reasons, and the second is the one that is easy to miss. An order
         * is an accounting record with a statutory retention period — and the
         * delete would leave the shop inconsistent in a way that undo cannot
         * repair: MethodInUseVoter keeps a shipping or payment method from
         * being deleted WHILE an order references it, so deleting the order
         * first releases the method, and restoring the order from tl_undo
         * afterwards resurrects a reference to something that is gone.
         *
         * An order that should not have happened is cancelled through its
         * status, which is what the status table is for.
         */
        'notDeletable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                // Per shop, like the number ranges (tl_consho_number_range.pid)
                // and the documents: two shops with the same or no prefix —
                // a copied shop — issue the same number.
                'shop,orderNumber' => 'unique',
                'shop' => 'index',
                // DB5: the dashboard's figures read the running month.
                'createdAt' => 'index',
                'member' => 'index',
                'orderStatus' => 'index',
                // MethodInUseVoter asks per list row of the method tables.
                'shippingMethod' => 'index',
                'paymentMethod' => 'index',
                // Abo-4: the orders of a subscription, newest first.
                'subscription' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 2,
            /*
             * `id DESC` is the tie-break, not decoration.
             *
             * createdAt has second resolution, and two orders in the same
             * second are entirely ordinary — a burst at the end of a campaign,
             * or an import. On a tie MySQL is free to return any order it
             * likes, and it picks the one that costs it least, which here was
             * ascending id: the newest order came out at the BOTTOM.
             *
             * id is autoincrement, so it is monotonic and never ties. The order
             * number would not do: number ranges are per shop and may have
             * gaps.
             */
            'fields' => ['createdAt DESC', 'id DESC'],
            'flag' => 8,
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'orderNumber',
        ],
        /*
         * One column per field instead of one run-on line.
         *
         * ⚠️ With showColumns, Contao DISCARDS a label_callback that returns a
         * string and falls back to its own formatted values (DataContainer::
         * generateRecordLabel: `return is_array($label) ? $label : $args`).
         * OrderLabelListener therefore returns an ARRAY, one entry per field
         * listed here, in this order.
         *
         * The headers come from `fields[…]['label']`, which is why those four
         * fields carry an explicit label reference below. Without it Contao
         * writes the raw column name into the header — "orderNumber".
         *
         * The price: Contao skips the group header when showColumns is on
         * (DC_Table, `&& !showColumns`), so the "September 2026" bands are
         * gone. Alignment was worth more here — amounts under amounts.
         */
        'label' => [
            'fields' => ['signals', 'orderNumber', 'createdAt', 'customer', 'grandTotal'],
            'showColumns' => true,
        ],
        'operations' => [
            /*
             * `primary` on all five jumps. Without it they would move behind
             * the ⋯ menu, because ONE operation carrying the flag switches the
             * whole list over (`has_primary` in DataContainerOperationsBuilder)
             * — and Contao's named `edit` brings it along. This list would be
             * left with the pencil alone, and the five jumps are what it is
             * for. In the core the jump to the children is primary as well.
             */
            'items' => ['href' => 'table=tl_consho_order_item', 'icon' => 'header.svg', 'primary' => true],

            // Not show.svg, which the 'show' operation already uses in this
            // very row — the same picture would mean 'documents' once and
            // 'details' once.
            'documents' => ['href' => 'table=tl_consho_document', 'icon' => 'document.svg', 'primary' => true],

            /*
             * The same icon means the same thing in the label and in the button
             * beside it. Payments used to be a star and deliveries a sync arrow
             * — the sync arrow would then have meant "delivery" as a button and
             * "payment" as a signal, in one and the same row.
             *
             * Contao has no icon for money at all, so the assignment is
             * arbitrary either way. Where it is arbitrary, consistency is what
             * is left to get right.
             */
            'payments' => ['href' => 'table=tl_consho_payment', 'icon' => 'sync.svg', 'primary' => true],
            'deliveries' => ['href' => 'table=tl_consho_delivery', 'icon' => 'copy.svg', 'primary' => true],

            // F-4. What the customer may use from the digital lines.
            'entitlements' => ['href' => 'table=tl_consho_entitlement', 'icon' => 'lock.svg', 'primary' => true],

            // What the customer read when they ordered (C-7). Reachable from
            // the list, because the question it answers is asked about a
            // particular order, not browsed.
            'legal' => ['href' => 'table=tl_consho_order_legal', 'icon' => 'articles.svg', 'primary' => true],

            // D-2. The declared withdrawals. Both buttons show whether there
            // is anything behind them (F13, OrderAftercareButtonListener).
            'withdrawals' => ['href' => 'table=tl_consho_withdrawal', 'icon' => 'undo.svg', 'primary' => true],

            // D-3 (U2): complaints as notes.
            'complaints' => ['href' => 'table=tl_consho_complaint', 'icon' => 'important.svg', 'primary' => true],
            'edit',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],

            // C-9. POST like the core's own resend (tl_opt_in): the button is a
            // form with the request token, not a link any page could trigger.
            // Primary like every operation here (decision 2026-09-23, see
            // EveryDeleteButtonAsksBeforeDeletingTest).
            'resendConfirmation' => ['href' => 'key=resendConfirmation', 'icon' => 'resend.svg', 'method' => 'POST', 'primary' => true],

            // E8: a later payment term agreed with the customer — a page with
            // a form, like "Pausieren" at a subscription.
            'deferPayment' => ['href' => 'key=deferPayment', 'icon' => 'forward.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        'default' => '{order_legend},orderNumber,orderStatus,createdAt,language,subscription,servicePeriodFrom,servicePeriodUntil;{customer_legend},member,salutation,firstname,lastname,email,phone,company;{billing_legend},billingStreet,billingPostal,billingCity,billingState,billingCountry;{shipping_legend},shippingCompany,shippingFirstname,shippingLastname,shippingStreet,shippingPostal,shippingCity,shippingState,shippingCountry,shippingPhone;{method_legend},shippingMethodTitle,paymentMethodTitle,remainderDeliveryDays;{consent_legend},termsAcceptedAt,digitalConsentAt,businessCustomer,minimumAge,dateOfBirth;{tax_legend},vatId,vatIdStatus,vatIdRequestIdentifier;{total_legend},currency,netTotal,taxTotal,grandTotal',
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * No column, no widget — this exists only so the signal column gets a
         * heading. showColumns reads `fields[…]['label']`, and a field that is
         * not declared would have its raw name written into the header.
         *
         * Deliberately without `sql`, so contao:migrate does not create a
         * column for it, and without `inputType`, so it never appears in a
         * palette.
         */
        'signals' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_order']['signalsColumn']],

        // Likewise virtual. The column shows first AND last name, so it cannot
        // borrow the `lastname` label — the heading would say "Nachname" over
        // "Erika Musterfrau".
        'customer' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_order']['customerColumn']],

        'createdAt' => [
            'label' => &$GLOBALS['TL_LANG']['tl_consho_order']['createdAt'],
            'inputType' => 'text',
            'sorting' => true,
            'flag' => 8,
            'eval' => ['rgxp' => 'datim', 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        /*
         * The language the order was placed in, taken from the page it came
         * through. It has to STAND on the order rather than be worked out at
         * run time: an invoice mail resent a year later must go out in the
         * language the customer ordered in — not in that of the back end user
         * who pressed the button.
         *
         * With n start pages to one shop this is not a corner case, it is the
         * normal one.
         */
        'language' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 8, 'default' => ''],
        ],
        'shop' => [
            'filter' => true,
            'foreignKey' => 'tl_consho_shop.title',
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'orderNumber' => [
            'label' => &$GLOBALS['TL_LANG']['tl_consho_order']['orderNumber'],
            'inputType' => 'text',
            'search' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],

        /*
         * The one maintained field on the whole record. Payment and delivery
         * state are derived from their own tables — see PaymentStateCalculator
         * and DeliveryStateCalculator — which is what makes partial payment
         * and partial delivery fall out instead of needing more fields.
         */
        'orderStatus' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'foreignKey' => 'tl_consho_order_status.title',
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'member' => [
            'inputType' => 'text',
            'foreignKey' => 'tl_member.lastname',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'currency' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
        ],
        'netTotal' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'taxTotal' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'grandTotal' => [
            'label' => &$GLOBALS['TL_LANG']['tl_consho_order']['grandTotal'],
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],

        /*
         * The chosen methods as reference plus a copy of the title, like the
         * addresses (research, conclusion 5). The reference keeps a method from
         * being deleted (MethodInUseVoter); the title stays true after a
         * rename. Written by the purchase (C-7), shown from then on.
         */
        'shippingMethod' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'shippingMethodTitle' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'paymentMethod' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * The payment instructions as the buyer was given them, in the order's
         * language (C-9). Frozen at the purchase like the title, because a
         * confirmation sent — or resent — later must not show today's text.
         * Operator HTML from the method's rich text field; not in the palette,
         * `act=show` lists it.
         */
        'paymentInstructions' => ['sql' => ['type' => 'text', 'notnull' => false]],
        'paymentMethodTitle' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        // NULL-able: empty means "not confirmed", not 01.01.1970. See A-3.
        'emailConfirmedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        // D-5 (L3, L4): when the customer asked for erasure, and when the
        // personal data of this order was removed. Set by CustomerErasure
        // and OrderAnonymizer only.
        'erasureRequestedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],
        'anonymizedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        // Likewise NULL-able, and written by C-9. Empty is what raises the
        // "confirmation not sent" warning and offers "send again".
        'confirmationSentAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        /*
         * When the customer changed the payment method on the order page
         * (C-10). Set once — a second change is not offered (Michael,
         * 2026-09-25). NULL-able like the other moments: 0 would read as 1970.
         */
        'paymentChangedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        /*
         * Working days until the rest follows a partial shipment (E-6b). Empty
         * takes the shop's default; the invoice at the first parcel names
         * "Teillieferung" and the day the rest is expected.
         */
        'remainderDeliveryDays' => [
            'label' => &$GLOBALS['TL_LANG']['tl_consho_order']['remainderDeliveryDays'],
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'nullIfEmpty' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'notnull' => false],
        ],

        /*
         * When the order was cancelled (D-1). Set the first time a status with
         * isCancelled is set, and it STAYS: the next status — "Abgeschlossen"
         * after "Storniert" — must not bring the claim back. Found by the test
         * of Michael's own case (2026-09-27): with the cancellation living in
         * the status alone, "storniert, erstattet, abschließen" was refused,
         * because the completing status owed the full amount again.
         *
         * Like a cancellation document, it is not undone. A mistaken
         * cancellation is a new order, not a reopened one.
         */
        'cancelledAt' => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        /*
         * The end of the payment term the standing invoice states (E-2, Z5):
         * the last second of its "Zahlbar bis" day, 0 where the invoice names
         * none. Written with every invoice, so a new one after a change of
         * payment method replaces it. Read by the overdue note.
         */
        'paymentDueAt' => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * When the payment balance first came out settled (D-1). The mail
         * "Zahlung erhalten" and the status change after payment happen once,
         * at that moment — not again after a refund and a new payment.
         */
        'paymentSettledAt' => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        /*
         * When the customer ticked the terms box, frozen alongside the wording
         * they ticked it for (tl_consho_order_legal). Either half on its own
         * proves nothing: a timestamp without the text does not say what was
         * agreed, and the text without a timestamp does not say that it was.
         *
         * NULL-able — a shop that does not require the tick has no timestamp,
         * and 0 would read as 01.01.1970.
         */
        'termsAcceptedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        /*
         * The consumer's consent to the early start of digital content and
         * the confirmation that the right of withdrawal lapses with it (F-3,
         * F15; § 18 Abs. 1 Z 11 FAGG, § 356 Abs. 6 BGB). NULL where it was not
         * given or not asked — then the digital lines wait for the end of the
         * withdrawal period (F16).
         */
        'digitalConsentAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        /*
         * Counted as a business at the purchase (F-3): a net price group or a
         * verified VAT ID. Frozen, because the release of digital lines
         * depends on it and group memberships change.
         */
        'businessCustomer' => [
            'inputType' => 'checkbox',
            'eval' => ['disabled' => true, 'tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],

        // Abo-4 (P31): the subscription a follow-up order bills, 0 for a
        // purchase. A purchase that starts a subscription is found through
        // tl_consho_subscription.firstOrder.
        'subscription' => [
            'foreignKey' => 'tl_consho_subscription.subscriptionNumber',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        // Abo-4 (P31): the period a follow-up order bills, frozen on the
        // order — the subscription moves on with every period, and an older
        // order must go on naming its own. NULL for a purchase.
        'servicePeriodFrom' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        'servicePeriodUntil' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        /*
         * The age gate, frozen as evidence (C-19).
         *
         * ⚠️ This is the one place the date of birth is kept on purpose. The
         * open point from C-5 — that the BASKET should store the answer rather
         * than the date — stands and is about a basket that may never become an
         * order. Here the operator has to be able to show, years later, on what
         * basis an age-restricted item went out; the same reasoning that puts
         * the VAT consultation number on the order and not on the basket.
         *
         * `minimumAge` is stored beside it because the limit can change: 16
         * today does not prove the order was lawful if it was 18 back then.
         */
        'minimumAge' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'dateOfBirth' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'date', 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 10, 'default' => ''],
        ],

        /*
         * The VAT check is frozen too, consultation number included. Whether
         * reverse charge applied to THIS order has to stay answerable years
         * later, and the customer's member record may have been rechecked a
         * dozen times since.
         */
        'vatId' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        'vatIdStatus' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'vatIdRequestIdentifier' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
    ],
];

/*
 * Customer and address fields, generated rather than written out thirty times:
 * the shape is identical and only the prefix differs.
 *
 * Editable until a document carries them — the delivery address until a
 * delivery or delivery note, customer and billing address until an invoice
 * (OrderAddressLockListener, Connect-2 E2). They were called "frozen" here
 * before 2026-10-10 without anything freezing them.
 */
$conshoOrderStrings = [
    'salutation' => 128,
    'firstname' => 255,
    'lastname' => 255,
    'email' => 255,
    'phone' => 64,
    'company' => 255,
];

foreach (['billing', 'shipping'] as $scope) {
    $conshoOrderStrings[$scope.'Street'] = 255;
    $conshoOrderStrings[$scope.'Postal'] = 32;
    $conshoOrderStrings[$scope.'City'] = 255;
    $conshoOrderStrings[$scope.'State'] = 64;
    $conshoOrderStrings[$scope.'Country'] = 2;
}

$conshoOrderStrings['shippingCompany'] = 255;
$conshoOrderStrings['shippingFirstname'] = 255;
$conshoOrderStrings['shippingLastname'] = 255;
// The recipient's phone for the carrier (E-6, V6), only where it needed one.
$conshoOrderStrings['shippingPhone'] = 64;

foreach ($conshoOrderStrings as $conshoName => $conshoLength) {
    $GLOBALS['TL_DCA']['tl_consho_order']['fields'][$conshoName] = [
        'inputType' => 'text',
        'exclude' => true,
        'search' => $conshoLength > 64,
        'eval' => ['maxlength' => $conshoLength, 'tl_class' => 'w50'],
        'sql' => ['type' => 'string', 'length' => $conshoLength, 'default' => ''],
    ];

    // By reference, the way Contao's own DCAs do it: the language file is what
    // fills these, and showColumns reads the label straight off the field.
    $GLOBALS['TL_DCA']['tl_consho_order']['fields'][$conshoName]['label'] = &$GLOBALS['TL_LANG']['tl_consho_order'][$conshoName];
}

unset($conshoOrderStrings, $conshoName, $conshoLength);
