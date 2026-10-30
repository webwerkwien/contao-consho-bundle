<?php declare(strict_types=1);

/*
 * One change of a voucher's balance (block Wertgutscheine, WG9): when, by how
 * much, what was left, why, and on which order or whose account. Written only
 * by VoucherLedger, in the same transaction as the balance itself.
 *
 * `type`: issue, redeem, refund, withdrawal, manual (VoucherBookingType).
 * `user`: the back end user for `manual` and an issue by hand, 0 for Consho.
 */
$GLOBALS['TL_DCA']['tl_consho_voucher_booking'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_voucher',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index', 'orderId' => 'index']],
    ],

    'list' => [
        'sorting' => ['mode' => 4, 'fields' => ['createdAt DESC', 'id DESC'], 'headerFields' => ['code', 'currency', 'balance']],
        'label' => ['fields' => ['createdAt', 'type', 'amount', 'balanceAfter']],
        // Read only and ordered by time: without this list Contao adds its
        // defaults, among them a "cut" that could never be used.
        'operations' => ['!show'],
    ],

    'palettes' => ['default' => '{booking_legend},createdAt,type,amount,balanceAfter,orderId,paymentId,user,note'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => ['foreignKey' => 'tl_consho_voucher.code', 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0], 'relation' => ['type' => 'belongsTo', 'load' => 'lazy']],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'createdAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'type' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'amount' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'balanceAfter' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'orderId' => [
            'foreignKey' => 'tl_consho_order.orderNumber',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // The payment row the booking belongs to (WG-5, WG-6).
        'paymentId' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'user' => [
            'foreignKey' => 'tl_user.name',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'note' => [
            'inputType' => 'textarea',
            'eval' => ['readonly' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
