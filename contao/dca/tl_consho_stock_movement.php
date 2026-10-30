<?php declare(strict_types=1);

/*
 * One change of a stock (Block G, G2): when, by how much, what it was after,
 * why, and on whose account. Written only by StockBooking, in the same
 * transaction as the change itself — a stock figure nobody can explain is the
 * thing this table exists to prevent.
 *
 * `reason`: order, cancellation, return, manual, receipt (StockReason).
 * `user`: the back end user for `manual`, 0 for Consho itself.
 */
$GLOBALS['TL_DCA']['tl_consho_stock_movement'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_stock',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index', 'orderId' => 'index']],
    ],

    'list' => [
        'sorting' => ['mode' => 4, 'fields' => ['createdAt DESC', 'id DESC'], 'headerFields' => ['article', 'quantity']],
        'label' => ['fields' => ['createdAt', 'delta', 'quantityAfter', 'reason']],
        // Read only and ordered by time: without this list Contao adds its
        // defaults, among them a "cut" that could never be used.
        'operations' => ['!show'],
    ],

    'palettes' => ['default' => '{movement_legend},createdAt,delta,quantityAfter,reason,orderId,withdrawalId,purchaseList,user'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => ['foreignKey' => 'tl_consho_stock.id', 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0], 'relation' => ['type' => 'belongsTo', 'load' => 'lazy']],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'createdAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'delta' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'quantityAfter' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'reason' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'orderId' => [
            'foreignKey' => 'tl_consho_order.orderNumber',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'withdrawalId' => [
            'foreignKey' => 'tl_consho_withdrawal.number',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // G-7: the purchase list a receipt belongs to.
        'purchaseList' => [
            'foreignKey' => 'tl_consho_purchase_list.number',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'user' => [
            'foreignKey' => 'tl_user.name',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
