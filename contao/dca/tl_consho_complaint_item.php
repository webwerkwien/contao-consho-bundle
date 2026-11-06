<?php declare(strict_types=1);

/*
 * The lines a complaint concerns (block Reklamation, 3.1): which goods line
 * of the order, how many pieces, and what is wrong with them. Typed by the
 * operator under the complaint; ComplaintItemListener offers the order's
 * goods lines and checks the quantity.
 *
 * `rescindedQuantity`, `reduction`, `returnedQuantity`, `restock` and
 * `restockedQuantity` belong to the handling of the complaint and are in no
 * palette. `rescindedQuantity` is set when a rescission is decided and 0
 * otherwise, and `reduction` only for a decided price reduction — everything
 * that counts what went back reads these two alone.
 */
$GLOBALS['TL_DCA']['tl_consho_complaint_item'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_complaint',
        'enableVersioning' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index']],
    ],

    'list' => [
        // A column list like the complaints above it; the complaint is named in the headline.
        'sorting' => [
            'mode' => 1,
            'fields' => ['id'],
            'panelLayout' => 'limit',
        ],
        // Cells from ComplaintItemListener::label().
        // showFirstOrderBy: without it Contao adds the sorting field `id` as a column.
        'label' => ['fields' => ['orderItem', 'quantity', 'defect'], 'showColumns' => true, 'showFirstOrderBy' => false],
        // Back to "Reklamation entscheiden"; the address comes from
        // ComplaintDecideButtonListener.
        'global_operations' => [
            'decide' => ['icon' => 'bundles/contaoconsho/icons/complaint-process.svg', 'primary' => true],
        ],
        'operations' => [
            'edit',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => ['default' => '{item_legend},orderItem,quantity,defect'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'foreignKey' => 'tl_consho_complaint.number',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_complaint'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'orderItem' => [
            'inputType' => 'select',
            'foreignKey' => 'tl_consho_order_item.productName',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order_item'],
        ],
        'quantity' => [
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'rgxp' => 'digit', 'tl_class' => 'w25'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'defect' => [
            'inputType' => 'textarea',
            'eval' => ['tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'rescindedQuantity' => ['sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000']],
        // What a decided replacement sends again: at most the complained
        // quantity, 0 with every other remedy (U8).
        'replacementQuantity' => ['sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000']],
        'returnedQuantity' => ['sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000']],
        // Defective goods stay out of stock unless the operator says otherwise (R6).
        'restock' => ['sql' => ['type' => 'boolean', 'default' => false]],
        'restockedQuantity' => ['sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000']],
        // Gross amount the price is reduced by, only with the remedy "reduction".
        'reduction' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'notnull' => false]],
    ],
];
