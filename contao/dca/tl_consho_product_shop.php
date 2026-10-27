<?php declare(strict_types=1);

/*
 * Which products a shop shows.
 *
 * One catalogue, a selection per shop — a customer running two shops with
 * overlapping ranges maintains each product once. See
 * Block A, section 3.1.
 *
 * Per-shop prices and texts are a possible later stage; they would add columns
 * here rather than introduce a new concept.
 */
$GLOBALS['TL_DCA']['tl_consho_product_shop'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // A ctable of the product for delete and undo; copied by
        // ProductCopyListener, not by DC_Table (see tl_consho_product).
        'doNotCopyRecords' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,shop' => 'unique',

                /*
                 * Covering, for the same reason as tl_consho_product_property:
                 * every listing query starts with "which products does this
                 * shop carry", and with pid in the index that answer comes out
                 * of the index itself.
                 *
                 * The former single-column index on shop goes with it —
                 * index changes are applied even without --with-deletes, which
                 * only guards columns and tables.
                 */
                'shop,pid' => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_product'],
        ],
        'shop' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_shop'],
        ],
    ],
];
