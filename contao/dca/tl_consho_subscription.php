<?php declare(strict_types=1);

/*
 * A subscription as it was bought (Abo-3; A9, A10, P16–P22).
 *
 * Written once by SubscriptionRecorder, in the purchase's transaction, from
 * the FROZEN order line — never typed in the back end. Everything the rule
 * said at the purchase is copied here (A9): changing or deleting the rule
 * later changes nothing about this contract. The tax RATE is not frozen; it
 * follows the law in force (A9).
 *
 * ⚠️ Read-only in the mask (P20): closed, notEditable, notCopyable,
 * notDeletable. Editing would be a way around the frozen values. Handling a
 * cancellation or a change comes with Abo-6/Abo-7 as actions of their own.
 *
 * `subscriptionNumber` is derived from the first order (P19): the order number and the line's place among the order's
 * subscription lines, "SHOP-00083-1". A contract number, neither an order nor
 * an invoice number — hence no number range of its own.
 */
$GLOBALS['TL_DCA']['tl_consho_subscription'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // Abo-7: the cancellations of the subscription.
        'ctable' => ['tl_consho_cancellation', 'tl_consho_subscription_pause'],
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'notSortable' => true,
        // Unique like the order number it is derived from.
        'sql' => ['keys' => ['id' => 'primary', 'subscriptionNumber' => 'unique', 'firstOrder' => 'index', 'orderItem' => 'index', 'member' => 'index', 'predecessor' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 2,
            // id DESC as the tie-break, as in the order list.
            'fields' => ['startedAt DESC', 'id DESC'],
            'flag' => 8,
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'subscriptionNumber',
        ],
        'label' => [
            // Abo-8, P80: the end and the next billing next to the period.
            // Abo pausieren (P111): until when a pause runs or is announced.
            'fields' => ['subscriptionNumber', 'member', 'product', 'ruleTitle', 'state', 'periodUntil', 'nextBillingAt', 'endsAt', 'pauseUntil'],
            'showColumns' => true,
        ],
        'operations' => [
            'cancellations' => ['href' => 'table=tl_consho_cancellation', 'icon' => 'header.svg', 'primary' => true],
            // Abo pausieren (P101, P107, P111): announce or withdraw, and the history.
            'pause' => ['href' => 'key=pauseSubscription', 'icon' => 'stop.svg', 'primary' => true],
            'pauses' => ['href' => 'table=tl_consho_subscription_pause', 'icon' => 'invisible.svg', 'primary' => true],
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            // Abo-8, P80: the purchase order. No href: the address comes only
            // from SubscriptionOrderButtonListener, never an edit of the subscription.
            'order' => ['icon' => 'parent.svg', 'primary' => true],
        ],
    ],

    'palettes' => ['default' => '{subscription_legend},subscriptionNumber,shop,member,product,firstOrder,orderItem;{rule_legend},rule,ruleTitle,termLength,termUnit,billingInterval,renewal,noticeDays,reminderDays,graceDays,pauseMonths;{price_legend},quantity,unitNet,taxClass,currency,reverseCharge,businessCustomer,kind,terms;{period_legend},phase,state,startedAt,minimumEndsAt,periodFrom,periodUntil,nextBillingAt,endsAt,reminderSentAt;{pause_legend},pausedMonths,pauseFrom,pauseUntil;{renewal_legend},predecessor,renewedBy;{mandate_legend},mandateMethod,mandate'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        'subscriptionNumber' => [
            'inputType' => 'text',
            'search' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'shop' => [
            'foreignKey' => 'tl_consho_shop.title',
            'inputType' => 'select',
            'filter' => true,
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // A subscription always has an account (P12); SubscriptionRecorder
        // refuses one without.
        'member' => [
            'foreignKey' => 'tl_member.lastname',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // Allowed to dangle, like the order line's product.
        'product' => [
            'foreignKey' => 'tl_consho_product.title',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'firstOrder' => [
            'foreignKey' => 'tl_consho_order.orderNumber',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'orderItem' => [
            'foreignKey' => 'tl_consho_order_item.productName',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        /*
         * The rule as it was at the purchase (A9). `rule` may dangle once the
         * rule is deleted; the copy below is what counts.
         */
        'rule' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'ruleTitle' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'termLength' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        /*
         * `reference` on the stored values below: the
         * details showed "year", "indefinite", "content". Contao's value
         * formatter reads the reference without options, so the fields stay
         * text inputs and nothing offers a choice.
         */
        'termUnit' => [
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription']['termUnits'],
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 8, 'default' => ''],
        ],
        // Months; 0 = one payment for the whole term.
        'billingInterval' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        'renewal' => [
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription']['renewals'],
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'noticeDays' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        'reminderDays' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        'graceDays' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        // Abo pausieren (P102): the rule's allowance per contract year, frozen (A9).
        'pauseMonths' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],

        // A10: one subscription per line, the quantity is the number of licences.
        'quantity' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        // Net per licence, the order line's unitPrice.
        'unitNet' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'taxClass' => [
            'foreignKey' => 'tl_consho_tax_class.title',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'currency' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
        ],
        'reverseCharge' => [
            'inputType' => 'checkbox',
            'eval' => ['disabled' => true, 'tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'businessCustomer' => [
            'inputType' => 'checkbox',
            'eval' => ['disabled' => true, 'tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        // The kind of the bought line (DL1): digital or service.
        'kind' => [
            'inputType' => 'select',
            'options' => array_map(static fn (Webwerkwien\ContaoConshoBundle\Catalog\ProductKind $kind): string => $kind->value, Webwerkwien\ContaoConshoBundle\Catalog\ProductKind::cases()),
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription']['kinds'],
            'eval' => ['disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        // The terms as the customer read them at the purchase (Abo-2).
        'terms' => [
            'inputType' => 'textarea',
            'eval' => ['readonly' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'string', 'length' => 512, 'default' => ''],
        ],

        // `minimum` until the minimum term is over; later phases with Abo-5/6.
        'phase' => [
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription']['phases'],
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'minimum'],
        ],
        'state' => [
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription']['states'],
            'inputType' => 'text',
            'filter' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'active'],
        ],

        /*
         * Boundaries from SubscriptionCalendar (P16–P18): the start is the
         * purchase, every boundary is start + n months, clamped.
         */
        'startedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'minimumEndsAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'periodFrom' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'periodUntil' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'nextBillingAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // NULL while the subscription runs; set by a cancellation or an end
        // (Abo-6/Abo-7).
        'endsAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        // Abo-6 (P42): when the reminder of the current term went out; 0 until
        // then, and back to 0 when a same-term renewal starts a new term.
        'reminderSentAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        /*
         * Abo pausieren (P104, P106). `pausedMonths`: every month paused so
         * far, added to the start by SubscriptionCalendar::boundary() — the
         * one place that moves every later boundary. `pauseFrom`/`pauseUntil`:
         * the latest pause, announced or running; its history is
         * tl_consho_subscription_pause. Written by SubscriptionPause only.
         */
        'pausedMonths' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        'pauseFrom' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'pauseUntil' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        /*
         * "Lizenz verlängern" (Abo-7b, P69): the subscription this one renews,
         * whose entitlement it took over, and the one that renewed this — set
         * once, at the purchase, so a subscription is renewed at most once.
         */
        'predecessor' => [
            'foreignKey' => 'tl_consho_subscription.subscriptionNumber',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'renewedBy' => [
            'foreignKey' => 'tl_consho_subscription.subscriptionNumber',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],

        /*
         * Follow-up payment (Abo-4b, P93, P98): what the provider kept at the
         * first payment, and the method that charges it. Written by
         * SubscriptionMandates from a provider's notification only.
         *
         * ⚠️ The mandate is shown nowhere: "Details" says only that there is
         * one (SubscriptionShowListener), the customer export only the method
         * (CustomerExport), and anonymising the subscription clears both.
         */
        'mandateMethod' => [
            'foreignKey' => 'tl_consho_payment_method.title',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        // doNotShow (Abo-4b): "Details" never gets the value; the
        // listener adds "vorhanden"/"keines" as a row of its own.
        'mandate' => [
            'eval' => ['doNotShow' => true],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
    ],
];
