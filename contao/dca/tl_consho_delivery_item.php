<?php declare(strict_types=1);

/*
 * Which quantity of which order item travelled with this delivery.
 *
 * This table is the reason partial delivery needs no extra field anywhere:
 * summing it per order item against the ordered quantity answers the question
 * on its own.
 */
$GLOBALS['TL_DCA']['tl_consho_delivery_item'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_delivery',

        // See tl_consho_delivery (D-1 review, S1).
        'notCopyable' => true,
        'doNotCopyRecords' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index', 'orderItem' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['id'],
            'panelLayout' => 'limit',
            'headerFields' => ['shippedAt', 'trackingNumber'],
        ],
        'label' => ['fields' => ['orderItem', 'quantity'], 'format' => '%s × %s'],
        'operations' => [
            'edit',
            '!delete',
        ],
    ],

    'palettes' => ['default' => '{item_legend},orderItem,quantity'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_delivery'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'orderItem' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_order_item.productName',
            'eval' => ['mandatory' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'quantity' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
    ],
];
