<?php declare(strict_types=1);

/*
 * The positions of a withdrawal of part of an order (D-2, W3). Empty for a
 * withdrawal of the whole order and for one named in free text.
 *
 * Read-only for the same reason as the withdrawal itself. D-3 adds what came
 * back (`returnedQuantity`).
 */
$GLOBALS['TL_DCA']['tl_consho_withdrawal_item'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_withdrawal',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index']],
    ],

    'list' => [
        'sorting' => ['mode' => 4, 'fields' => ['id'], 'panelLayout' => 'limit', 'headerFields' => ['number', 'receivedAt']],
        'label' => ['fields' => ['quantity', 'orderItem', 'acceptedQuantity', 'returnedQuantity'], 'format' => '%s × %s (%s / %s)'],
        'operations' => ['show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true]],
    ],

    'palettes' => ['default' => '{item_legend},orderItem,quantity,acceptedQuantity,returnedQuantity'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'foreignKey' => 'tl_consho_withdrawal.number',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_withdrawal'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'orderItem' => [
            'inputType' => 'text',
            'foreignKey' => 'tl_consho_order_item.productName',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order_item'],
        ],
        // What the CUSTOMER declared — 0 on a row the operator added (D-3).
        'quantity' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],

        /*
         * D-3. The operator's side, beside the customer's, never over it:
         * `declared` tells the two kinds of row apart (a withdrawal of the
         * whole order, or of a part named in free text, has no declared rows;
         * accepting it adds rows with declared = 0).
         */
        'declared' => [
            'eval' => ['readonly' => true],
            'sql' => ['type' => 'boolean', 'default' => true],
        ],
        'acceptedQuantity' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'returnedQuantity' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],

        /*
         * Block G (G9): whether the returned goods go back into stock — the
         * operator's tick on "Widerruf bearbeiten", on by default (damaged
         * goods do not) — and how much went back so far. A corrected return
         * books the difference against this (StockReturn).
         */
        'restock' => [
            'inputType' => 'checkbox',
            'eval' => ['disabled' => true, 'tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => true],
        ],
        'restockedQuantity' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
    ],
];
