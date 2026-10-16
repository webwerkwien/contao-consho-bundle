<?php declare(strict_types=1);

/*
 * Product <-> property option, as VARIANT AXIS. Rows here define WHICH variant
 * a product row is. See the note in tl_consho_product_property.php for why
 * these are two tables and not one with a role column.
 */
$GLOBALS['TL_DCA']['tl_consho_product_option'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // A ctable of the product for delete and undo; copied by
        // ProductCopyListener, not by DC_Table — a copied variant leaves its
        // options behind (see tl_consho_product).
        'doNotCopyRecords' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,optionId' => 'unique',
                'optionId' => 'index',
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
        'optionId' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_property_option'],
        ],
    ],
];
