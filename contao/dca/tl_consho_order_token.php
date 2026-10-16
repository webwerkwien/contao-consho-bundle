<?php declare(strict_types=1);

/*
 * Tokens are stored HASHED. The plaintext exists only in the link that goes
 * out by mail — a database dump must not hand anyone the ability to view
 * orders, trigger withdrawals or fetch downloads.
 *
 * One token per PURPOSE, not one per order. A download link a customer
 * forwards to a colleague must not also cancel their contract.
 *
 * ⚠️ This table is deliberately absent from every back end module's `tables`
 * and from the order's `ctable`, so nothing in the back end can open it. There
 * is nothing here a person could act on: a hash cannot be read back into a
 * link, and showing a column of hashes would only invite someone to try.
 * The DCA exists so that contao:migrate creates the table.
 */
$GLOBALS['TL_DCA']['tl_consho_order_token'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',

                /*
                 * Unique on the hash, and that is a guard rather than tidiness:
                 * the validator looks a token up BY its hash, so a duplicate
                 * would make one link resolve to two orders. The database
                 * refuses that outright instead of leaving it to the generator.
                 */
                'tokenHash' => 'unique',
                'pid,purpose' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            // Without it Contao shows pid through the FIRST list field of
            // tl_consho_order, which is `signals` — no column, so "Info" was
            // a 500 (ParentLabelIsAColumnTest, 2026-09-25).
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'purpose' => ['sql' => ['type' => 'string', 'length' => 32, 'default' => '']],

        // 64 characters is exactly a sha256 in hex. Not a round number picked
        // for looks — a shorter column would silently truncate the hash and
        // every lookup would fail.
        'tokenHash' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],

        'createdAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * NULL-able, not `default 0`.
         *
         * 0 would mean "expired on 01.01.1970" to anything comparing against
         * time(), so every unlimited token would read as long expired. The
         * absence of an expiry has to be expressible, and NULL is how — the
         * same lesson as the date fields in A-3 and A-5, except that here it
         * changes behaviour rather than display.
         *
         * Since D-6 every token is issued with an expiry, and
         * OrderTokenExpiryMigration gave the old ones one. NULL stays
         * expressible so a row nobody could judge is never read as 1970.
         */
        'expiresAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],
        'usedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        /*
         * Whether acting through this token proves the mailbox (C-10). Only a
         * token that travelled BY MAIL does: the purchase redirects the buyer
         * to the order page with a token of its own, and a click there proves
         * nothing about the address — found at the acceptance, where the
         * address was "confirmed" before the mail had been opened (Michael,
         * 2026-09-25: „ok" to two tokens). Tokens from before are false:
         * redirect and mail shared one then, so none of them can tell.
         */
        'confirmsEmail' => ['sql' => ['type' => 'boolean', 'default' => false]],
    ],
];
