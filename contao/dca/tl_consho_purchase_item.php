<?php declare(strict_types=1);

/*
 * One line of a purchase list (Block G, G-7; E1–E3). Frozen when the list is
 * handed out: the article's name, numbers, supplier and pack size as they
 * were — a list reissued later says what was ordered, not what the article
 * says today.
 *
 * `quantity` and `received` in pieces; `state`: open, partial, received,
 * unavailable (E3: "nicht lieferbar" — the rest no longer counts as ordered,
 * and the article comes back in the next purchase).
 */
$GLOBALS['TL_DCA']['tl_consho_purchase_item'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_purchase_list',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index', 'article,state' => 'index']],
    ],

    'list' => [
        'sorting' => ['mode' => 4, 'fields' => ['supplier', 'id'], 'headerFields' => ['number', 'createdAt', 'state']],
        'label' => ['fields' => ['title', 'quantity', 'received', 'state']],
        'operations' => ['show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true]],
    ],

    'palettes' => ['default' => '{item_legend},title,sku,supplier,supplierSku,packUnit,quantity,received,state'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => ['foreignKey' => 'tl_consho_purchase_list.number', 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0], 'relation' => ['type' => 'belongsTo', 'load' => 'lazy']],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Allowed to dangle once the article is deleted; the frozen title stays.
        'article' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'title' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'sku' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'supplier' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'supplierSku' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'packUnit' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 1],
        ],
        'quantity' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'received' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'state' => [
            'inputType' => 'select',
            'options' => ['open', 'partial', 'received', 'unavailable'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_purchase_item']['states'],
            'eval' => ['disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'open'],
        ],
    ],
];
