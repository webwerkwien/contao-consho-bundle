<?php declare(strict_types=1);

/*
 * Projection, not cache — like tl_consho_product_list_price.
 *
 * One row per visible article per shop, language AND currency: a main product
 * only when it is visible AND priced (exactly what a listing may show), a
 * variant when it is visible, priced or not (filters on variant axes need
 * exactly that). The
 * rules stay in VisibilityCriteria alone; this table only stores their result,
 * so the listing no longer evaluates them per product row (B-9: a full scan of
 * tl_consho_product in every listing query). It can be rebuilt completely at
 * any time and checked by machine — consho:visibility:rebuild / :verify.
 * See Block B, section 5.7.
 *
 * `net`/`gross` are the "from" price in `currency`, on main product rows only.
 *
 * ── Why `currency` is part of the key (C-1c, task 5b) ─────────────────────
 *
 * Until C-1c a shop had exactly one currency, so the currency followed from
 * (shop, language) and the column would have been redundant. Since the
 * currency hangs on the start point (spec 4.0), one shop can be reached in
 * German through example.de in euro and through example.ch in francs — the
 * DACH case, and the main one this product is built for. Both are the same
 * shop and the same language, so on the old key the second row overwrote the
 * first without a word.
 *
 * `variant` is a column and not `pid = main`: comparing two columns cannot use
 * an index, and "main product rows of this shop, language and currency" is what
 * every listing asks.
 *
 * ── The three reading indexes ─────────────────────────────────────────────
 *
 * `currency` sits in each of them directly behind `language`, ahead of the
 * sorted and compared columns. Every listing query compares shop, language and
 * currency for equality and then sorts by `gross`/`net` or looks up
 * `variant`/`main`; an index can only serve the ORDER BY when every equality
 * column comes first. Behind `gross` the currency would be dead weight, and a
 * price list would still have to read both currencies and sort them in memory.
 *
 * `tstamp` is when the row was calculated — for diagnosis only. The cron for
 * start/stop keeps its marker in tl_consho_projection_state.
 */
$GLOBALS['TL_DCA']['tl_consho_product_visibility'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,shop,language,currency' => 'unique',
                'shop,language,currency,variant,main' => 'index',
                'shop,language,currency,gross' => 'index',
                'shop,language,currency,net' => 'index',
                'main' => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_product'],
        ],
        'main' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'variant' => ['sql' => ['type' => 'boolean', 'default' => false]],
        'shop' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'language' => ['sql' => ['type' => 'string', 'length' => 5, 'default' => '']],
        'currency' => ['sql' => ['type' => 'string', 'length' => 3, 'default' => '']],
        'net' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'notnull' => false, 'default' => null]],
        'gross' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'notnull' => false, 'default' => null]],
    ],
];
