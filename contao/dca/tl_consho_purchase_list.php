<?php declare(strict_types=1);

/*
 * A purchase list (Block G, G-7): what the operator decided to reorder, saved
 * the moment it was handed out — clipboard, print or CSV.
 *
 * Not a purchase order: no prices, no supplier invoice, nothing sent to the
 * supplier. Consho knows what was ordered and what has come; how it is
 * ordered happens outside ("den eigentlichen Bestellprozess dann außerhalb von
 * Consho automatisieren").
 *
 * Written by PurchaseLists, never in a mask. `state` follows the lines:
 * open, partial, done.
 */
$GLOBALS['TL_DCA']['tl_consho_purchase_list'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ctable' => ['tl_consho_purchase_item'],
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'state' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 2,
            'fields' => ['createdAt DESC', 'id DESC'],
            'flag' => 8,
            'panelLayout' => 'filter;search,limit',
        ],
        'label' => [
            'fields' => ['number', 'createdAt', 'suppliers', 'lineCount', 'state'],
            'showColumns' => true,
        ],
        'operations' => [
            'items' => ['href' => 'table=tl_consho_purchase_item', 'icon' => 'children.svg', 'primary' => true],
            'receive' => ['href' => 'key=purchaseReceive', 'icon' => 'ok.svg', 'primary' => true],
            'output' => ['href' => 'key=purchaseOutput', 'icon' => 'copy.svg', 'primary' => true],
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
        ],
    ],

    'palettes' => ['default' => '{list_legend},number,createdAt,user,suppliers,lineCount,state'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // "EK-0001", from the id — a working number, not a document number.
        'number' => [
            'inputType' => 'text',
            'search' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        'createdAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'user' => [
            'foreignKey' => 'tl_user.name',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // The suppliers on the list, for the overview and the search.
        'suppliers' => [
            'inputType' => 'text',
            'search' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'lineCount' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'state' => [
            'inputType' => 'select',
            'filter' => true,
            'options' => ['open', 'partial', 'done'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_purchase_list']['states'],
            'eval' => ['disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'open'],
        ],
    ],
];
