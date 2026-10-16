<?php declare(strict_types=1);

use Contao\DC_Table;

/*
 * The basket (C9). A table WITHOUT a back end module, on purpose (decision
 * 2026-09-16): baskets are short-lived and carry personal data in
 * checkoutData; nobody needs to browse them. The file exists so that
 * contao:migrate knows the columns.
 *
 * Should a module ever be added, the four `not…` flags below are where to
 * start — they are what keeps a stray ?do=… from writing anything.
 *
 * 🔴 The column `order` is a reserved word in MySQL and SQLite, and **Doctrine
 * does not quote it for you** — not in a hand-written query, and not in
 * Connection::insert()/update()/delete() with an array either. Measured
 * against DBAL 4.4.4 on 2026-09-23; this comment claimed the opposite until
 * then, and it was wrong.
 *
 * It went unnoticed because nothing ever wrote the column: C-7 is the first
 * caller (markConverted), and it would have failed on the server with a syntax
 * error naming the column and explaining nothing. Every statement touching it
 * must quote the name itself, through Connection::quoteIdentifier() so that
 * MySQL and SQLite each get their own form.
 */
$GLOBALS['TL_DCA']['tl_consho_cart'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ctable' => ['tl_consho_cart_item'],
        'notCreatable' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                // NULL, not '': a member basket has no token, and MySQL lets
                // any number of NULLs share a unique index.
                //
                // The shop is part of the key since C-3b. A cookie identifies
                // the visitor, not the basket, so one token carries a basket
                // per shop. A member has one per shop too, but by the queries
                // in DbalCartRepository, not by a key — nothing here stops a
                // second open member basket in the same shop.
                //
                // contao:migrate swaps the index itself, no migration needed:
                // CommandCompiler protects tables and columns, indexes go into
                // the Doctrine diff. ADDING the wider key cannot fail on
                // existing rows, because everything unique under (tokenHash)
                // stays unique under (tokenHash, shop).
                //
                // ⚠️ The key says "one row per (token, shop)", the code means
                // "one OPEN basket per (token, shop)" — there is no `state`
                // in it. Once C-7 sets a basket to 'converted' and keeps its
                // tokenHash, the next click of that cookie in that shop runs
                // into the key. To settle in C-7.
                'tokenHash,shop' => 'unique',
                'member' => 'index',
                'state,updatedAt' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // 64 hex characters of SHA-256 — or NULL. NULL, not '': a member
        // basket has no token, and any number of NULLs share a unique index
        // while a second empty string would be refused.
        'tokenHash' => ['sql' => ['type' => 'string', 'length' => 64, 'notnull' => false, 'default' => null]],

        'member' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_member'],
        ],
        'shop' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_shop'],
        ],

        'checkoutData' => ['sql' => ['type' => 'json', 'length' => 16777215, 'notnull' => false]],
        // When checkoutData was last written (E1): the address has a clock of
        // its own, CartLifetime::CHECKOUT_DATA, independent of the lines.
        // 0 on baskets from before — the cron then goes by updatedAt.
        'checkoutDataAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'notice' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        // Block H (H-4, W1): the discount code typed in the basket — kept as
        // typed less the outer spaces, compared exactly (W6).
        'discountCode' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        // Block Wertgutscheine (WG8, E3): the voucher codes typed in the
        // basket, normalised, comma-separated, in the order they were typed —
        // the order they pay in. At most CodeEntry::MAX_VOUCHERS.
        'vouchers' => ['sql' => ['type' => 'string', 'length' => 255, 'default' => '']],
        'state' => ['sql' => ['type' => 'string', 'length' => 16, 'default' => 'open']],

        // The order this basket became (C-7 writes it). A relation, unlike
        // tl_consho_order_item.product: an order is never deleted under a
        // basket — and where it were, the basket is gone with it.
        'order' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],

        'createdAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'updatedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
