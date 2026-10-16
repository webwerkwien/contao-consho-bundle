<?php declare(strict_types=1);

/*
 * A voucher (block Wertgutscheine, WG9): its code, value, what was paid for
 * it, currency, shop and what is left.
 *
 * Created only by VoucherLedger — bought (WG-4) or issued by hand through
 * "Gutschein ausstellen" (WG13, VoucherBackendController) — never through
 * this mask: a new row without its first booking would be a balance nobody
 * can explain. The same for `balance`, which only VoucherLedger writes, and
 * for code, shop, currency, value and price, which are what the voucher IS.
 * The mask edits what the operator decides later: validity, the further
 * shops (WG9), the block, the note.
 *
 * `code` is stored bare (VoucherCode), unique; the list shows it in groups.
 * `paidAmount` is the tax base once it is spent (Art. 73a MwStSystRL), 0 for
 * one issued by hand. `validUntil` NULL: unlimited (WG10, OGH 7 Ob 22/12d).
 * `source`: purchase, manual.
 */
$GLOBALS['TL_DCA']['tl_consho_voucher'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ctable' => ['tl_consho_voucher_booking'],
        'notCreatable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'enableVersioning' => true,
        'sql' => ['keys' => ['id' => 'primary', 'code' => 'unique', 'shop' => 'index', 'orderId' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 2,
            'fields' => ['createdAt DESC'],
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label' => [
            // Formatted by VoucherListListener: the code in groups, money as money.
            'fields' => ['code', 'balance', 'faceValue', 'validUntil', 'source', 'blocked'],
            'showColumns' => true,
        ],
        'global_operations' => [
            // In the row, not behind ⋯: the one way a voucher comes into being here.
            'issueVoucher' => ['href' => 'key=issueVoucher', 'icon' => 'new.svg', 'primary' => true],
        ],
        'operations' => [
            'edit',
            'bookings' => ['href' => 'table=tl_consho_voucher_booking', 'icon' => 'children.svg', 'primary' => true],
            'correctVoucher' => ['href' => 'key=correctVoucher', 'icon' => 'modules.svg', 'primary' => true],
            '!versions',
            '!show',
        ],
    ],

    'palettes' => [
        'default' => '{voucher_legend},code,currency,faceValue,paidAmount,balance,source,orderId,createdAt;{use_legend},shop,shops,validUntil,blocked;{note_legend},note',
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'code' => [
            'search' => true,
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'createdAt' => [
            'sorting' => true,
            'flag' => Contao\DataContainer::SORT_DAY_DESC,
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // The shop that issued it — the one it is valid in (WG9).
        'shop' => [
            'filter' => true,
            'foreignKey' => 'tl_consho_shop.title',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        // Further shops of the same operator it may be spent in (WG9).
        'shops' => [
            'foreignKey' => 'tl_consho_shop.title',
            'inputType' => 'checkbox',
            'eval' => ['multiple' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
            'relation' => ['type' => 'hasMany', 'load' => 'lazy'],
        ],
        'currency' => [
            'filter' => true,
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
        ],
        'faceValue' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'paidAmount' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'balance' => [
            'sorting' => true,
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        // The last day it can be spent, inclusive (the date's midnight, as
        // Contao stores a date); NULL: unlimited (WG10). NULL rather than 0
        // like `availableFrom` of block G — a 0 would show as 01.01.1970.
        'validUntil' => [
            'sorting' => true,
            'inputType' => 'text',
            'eval' => ['rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        'blocked' => [
            'filter' => true,
            'inputType' => 'checkbox',
            'eval' => ['tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'source' => [
            'filter' => true,
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        // The order that bought it (WG-4); 0 for one issued by hand.
        'orderId' => [
            'foreignKey' => 'tl_consho_order.orderNumber',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'orderItem' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // WG-6 (WG7): the withdrawal that took this bought voucher back —
        // blocked it, and keeps back what was spent of it.
        'withdrawal' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'note' => [
            'inputType' => 'textarea',
            'eval' => ['tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
