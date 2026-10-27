<?php declare(strict_types=1);

/*
 * Product <-> property option, as FILTERABLE INFORMATION.
 *
 * Deliberately a second table rather than one table with a `role` column: a
 * forgotten `WHERE role = 'variant'` returns wrong data silently — no error,
 * just a product with variants that are not variants. Two tables make the
 * mistake impossible. Shopware separates these for the same reason.
 * See Block A, section 3.1.
 *
 * The composite unique key stops the same property being attached twice at the
 * database level, not by a check in code.
 */
$GLOBALS['TL_DCA']['tl_consho_product_property'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // A ctable of the product for delete and undo; copied by
        // ProductCopyListener, not by DC_Table (see tl_consho_product).
        'doNotCopyRecords' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,optionId' => 'unique',

                /*
                 * Covering, and that is the point. The listing asks both
                 * directions: "which options does this product carry" (the
                 * unique index above) and "which products carry this option"
                 * — the latter for the filter counts in Block B, section
                 * 5.2.
                 *
                 * A single index on optionId answers the second one only down
                 * to the row: InnoDB adds the PRIMARY key to a secondary index,
                 * not the columns one happens to want, so `SELECT pid … WHERE
                 * optionId = ?` would look up every row it found. With pid in
                 * the index the answer never leaves it.
                 *
                 * The former single-column index on optionId is gone with
                 * it: `contao:migrate
                 * --no-interaction` DOES apply index changes, including the
                 * DROP. Its reluctance to delete covers columns and tables —
                 * a renamed field needs --with-deletes, an index does not.
                 */
                'optionId,pid' => 'index',
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
