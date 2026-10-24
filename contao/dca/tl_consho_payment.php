<?php declare(strict_types=1);

/*
 * Payment RECORDS, not a payment status.
 *
 * The state follows from the sum. Part payments, instalments and refunds fall
 * out of this by themselves — a status field could express none of them
 * without growing a second field for the amount already paid, and then a third
 * for how much was refunded.
 *
 * A refund is a row with a negative amount, which is why `amount` has no
 * `natural` regexp.
 */
$GLOBALS['TL_DCA']['tl_consho_payment'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'enableVersioning' => true,

        /*
         * A copied payment is money nobody received — and a copy of a locked
         * one was born locked and could not even be deleted (Fable review of
         * D-1, S1). Money is recorded, not duplicated.
         */
        'notCopyable' => true,

        // And never along with a copied order (copyChildren asks only this).
        'doNotCopyRecords' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',

                /*
                 * One payment per attempt, refused by the database (C-11, Z4).
                 * The notification locks the attempt first and returns early
                 * once it is paid; this key holds even where the lock would
                 * not — two processes, a replica, a later bug. NULL for rows
                 * the operator enters: MySQL lets any number of them through a
                 * unique key.
                 */
                'attempt' => 'unique',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            // Tie-break: receivedAt has second resolution, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['receivedAt', 'id'],
            'panelLayout' => 'limit',
            'headerFields' => ['orderNumber', 'grandTotal'],
            'defaultSearchField' => 'method',
        ],
        'label' => ['fields' => ['receivedAt', 'amount', 'method'], 'format' => '%s — %s (%s)'],
        'operations' => [
            'edit',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => ['default' => '{payment_legend},receivedAt,amount,method,reference'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            // Without it Contao shows pid through the FIRST list field of
            // tl_consho_order, which is `signals` — no column, so "Info" was
            // a 500 (ParentLabelIsAColumnTest, 2026-09-25).
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'receivedAt' => [
            'inputType' => 'text',
            'exclude' => true,

            // Now, as the core does for tl_member.dateAdded. Without it
            // a new payment offered 01.01.1970 01:00 — seen at the
            // acceptance of D-1.
            'default' => time(),
            'eval' => ['mandatory' => true, 'rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'amount' => [
            'inputType' => 'text',
            'exclude' => true,
            // rgxp digit: a decimal comma becomes a dot (Widget::validator()),
            // and the mask shows the amount with the back end's separator
            // (DecimalFieldListener) — without it "25,00" would reach the column.
            'eval' => ['conshoMoney' => true, 'mandatory' => true, 'rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'method' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'reference' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        // The attempt a provider's notification paid (C-11). Not in the
        // palette: written by the core, never typed. NULL for a payment the
        // operator records by hand.
        'attempt' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false, 'default' => null],
        ],

        /*
         * When this payment had consequences — an invoice, a status, a mail
         * (D-1, decision A2). From then on the mask is read-only and the row
         * cannot be deleted: an invoice would otherwise name a payment that
         * no longer exists as it was. A correction is a counter-entry, a
         * negative amount. Written by PaymentRecorded, never typed.
         */
        'lockedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],
    ],
];
