<?php declare(strict_types=1);

/*
 * The price offers of a subscription (Abo-10, P113–P132): one row per day the
 * price changes at. A changed price of the plan is offered before that day;
 * until an answer that agrees the frozen price stays (A9).
 *
 *  - open: an increase, waiting for the answer until `deadlineAt`
 *  - accepted: agreed (`account`), by silence of a business customer
 *    (`silence`, P122), or a decrease (P116)
 *  - declined: refused; the subscription ends at `effectiveAt` (P121)
 *  - lapsed: no answer from a consumer; the subscription ends likewise (P122)
 *  - void: the subscription ended before (P124)
 *
 * Written by SubscriptionPriceOffers, the biller (`appliedAt`) and
 * SubscriptionPause (the dates, P125) only. Net amounts per unit, in the
 * subscription's currency (P126). No personal data beyond the reference to
 * the subscription (P132).
 */
$GLOBALS['TL_DCA']['tl_consho_subscription_price'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_subscription',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid,effectiveAt' => 'unique', 'state' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['effectiveAt DESC', 'id DESC'],
            'panelLayout' => 'limit',
            'headerFields' => ['subscriptionNumber', 'state', 'unitNet', 'currency'],
        ],
        'label' => ['fields' => ['effectiveAt', 'oldUnitNet', 'newUnitNet', 'state'], 'format' => '%s · %s → %s · %s'],
        'operations' => [
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
        ],
    ],

    'palettes' => ['default' => '{offer_legend},createdAt,effectiveAt,deadlineAt,oldUnitNet,newUnitNet,currency,quantity,decrease;{answer_legend},state,answeredAt,answeredBy,cancellation,appliedAt;{mail_legend},mailSentAt,reminderSentAt,answerMailSentAt'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'foreignKey' => 'tl_consho_subscription.subscriptionNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_subscription'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'createdAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        // The day the new price applies from (P114) and the end of the answer
        // (P118: that day minus the customer's notice).
        'effectiveAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'deadlineAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        // Net per unit (P126), four places like `unitNet` of the subscription.
        'oldUnitNet' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'newUnitNet' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'currency' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
        ],
        'quantity' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '1.0000'],
        ],
        'decrease' => [
            'inputType' => 'checkbox',
            'eval' => ['disabled' => true, 'tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],

        'state' => [
            'inputType' => 'select',
            'options' => ['open', 'accepted', 'declined', 'lapsed', 'void'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription_price']['states'],
            'eval' => ['disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'open'],
        ],
        'answeredAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // `account` ("Meine Abos") | `silence` (P122) — '' for a decrease or void.
        'answeredBy' => [
            'inputType' => 'select',
            'options' => ['account', 'silence'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription_price']['answers'],
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        // The cancellation a refusal or a consumer's silence recorded (P121, P122).
        'cancellation' => [
            'foreignKey' => 'tl_consho_cancellation.number',
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // When the biller took the new price over (P120).
        'appliedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        'mailSentAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'reminderSentAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // The mail on the answer or the lapse (P137); 0 while none is due or
        // when it did not go out — the finding says so.
        'answerMailSentAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
