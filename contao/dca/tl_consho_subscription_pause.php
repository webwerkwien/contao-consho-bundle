<?php declare(strict_types=1);

/*
 * The pauses of a subscription (Abo pausieren, P101–P112): one row per pause,
 * announced, running, over or withdrawn. The subscription carries the latest
 * in `pauseFrom`/`pauseUntil` and the sum in `pausedMonths`; this is the
 * history — for the allowance per contract year (P102), the export (P111)
 * and the operator.
 *
 * Written by SubscriptionPause only. The `previous…` columns hold what the
 * subscription said before the pause moved it, so a withdrawal (P107) puts
 * back exactly that instead of computing it again.
 */
$GLOBALS['TL_DCA']['tl_consho_subscription_pause'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_subscription',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['pauseFrom DESC', 'id DESC'],
            'panelLayout' => 'limit',
            'headerFields' => ['subscriptionNumber', 'state', 'pausedMonths'],
        ],
        'label' => ['fields' => ['pauseFrom', 'pauseUntil', 'months', 'source', 'withdrawnAt'], 'format' => '%s – %s · %s · %s · %s'],
        'operations' => [
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
        ],
    ],

    'palettes' => ['default' => '{pause_legend},months,pauseFrom,pauseUntil,source,requestedAt,withdrawnAt'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'foreignKey' => 'tl_consho_subscription.subscriptionNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_subscription'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // Whole periods, in months (P103).
        'months' => [
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

        // `account` ("Meine Abos") | `operator` (the back end) — P101.
        'source' => [
            'inputType' => 'select',
            'options' => ['account', 'operator'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription_pause']['sources'],
            'eval' => ['disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'requestedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // 0 = standing (P107: only before it began).
        'withdrawnAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        // What the subscription said before (see above). Not shown.
        'previousNextBillingAt' => ['eval' => ['doNotShow' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'previousMinimumEndsAt' => ['eval' => ['doNotShow' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'previousReminderSentAt' => ['eval' => ['doNotShow' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'previousPauseFrom' => ['eval' => ['doNotShow' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'previousPauseUntil' => ['eval' => ['doNotShow' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
