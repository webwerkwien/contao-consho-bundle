<?php declare(strict_types=1);

/*
 * Projection, not cache — one row per article that is "derzeit nicht
 * verfügbar" right now (block Verfügbarkeit, V1).
 *
 * The listing's filter "Nur verfügbare" and its switch "Nicht Verfügbares ans
 * Ende" read it. Why a table of its own and not a column of
 * tl_consho_product_visibility: that projection is written in SQL
 * (INSERT … SELECT), and the rule of a key pool — free codes less what is
 * ordered and not yet handed out — lives in PHP alone (Stock\Availability).
 * A second copy of it in SQL is the copy that drifts.
 *
 * Only articles that can be sold carry a row: a variant, or a main product
 * without variants. A main product with variants never does — its state is
 * that of its variants (V3).
 *
 * Kept by Stock\SoldOutMarks, rebuilt hourly as a net and checked by
 * consho:sold-out:verify. A row too few shows an article the visitor cannot
 * buy — the purchase click asks Availability itself, so nothing is sold that
 * is not there.
 */
$GLOBALS['TL_DCA']['tl_consho_sold_out'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'article' => 'unique',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'article' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_product'],
        ],
    ],
];
