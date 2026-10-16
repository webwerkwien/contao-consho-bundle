<?php declare(strict_types=1);

/*
 * Addresses a product used to answer on.
 *
 * Changing the address pattern on a product page moves EVERY product address
 * at once. The alternative to this table would be to freeze the pattern after
 * the first live shop — and a setting that cannot be changed is a setting that
 * was decided by whoever set the shop up first.
 *
 * Shopware keeps its superseded seo_url rows as non-canonical, Magento writes
 * redirect rewrites. Same idea: do not forbid the change, remember what it
 * replaced.
 *
 * ── Bookkeeping, not a place to work ──────────────────────────────────────
 *
 * No back end module. Rows appear when a pattern is saved and leave when
 * consho:url:prune is run. An editor who finds them in a list would reasonably
 * assume they are meant to be maintained by hand, and they are not.
 *
 * ── Why productId and not newPath ─────────────────────────────────────────
 *
 * Change the pattern twice and there are two generations of old addresses. A
 * table of old→new pairs would answer the first one with the second one, which
 * then redirects again: a chain that costs a round trip per generation and
 * loses weight with search engines at every link. Holding the product means
 * every generation resolves to today's address in one hop.
 */
$GLOBALS['TL_DCA']['tl_consho_url_redirect'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',

                /*
                 * One path on one site points at one product. The recorder
                 * writes with ON DUPLICATE KEY UPDATE, so saving the same
                 * pattern twice cannot produce a second row.
                 */
                'pid,oldPath' => 'unique',
                'productId' => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],

        // The root page the address belonged to. A multilingual shop has one
        // product page per site, and their addresses differ.
        'pid' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * The superseded address, without scheme and host — what a request
         * carries as its path. Stored complete rather than as a pattern: a
         * pattern would have to be interpreted when it is resolved and could
         * match more than was meant. A path matches exactly itself.
         *
         * NULL-able, although no back end mask writes here: should one ever be
         * added, `default ''` plus the UNIQUE index above would let the first
         * unfinished row occupy (0, '') and block every further one — the trap
         * paid for in A-10.
         */
        'oldPath' => ['sql' => ['type' => 'string', 'length' => 255, 'notnull' => false]],

        'productId' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // Not tstamp: this row is never modified, so "changed at" would be
        // misleading. consho:url:prune reads it to decide what is old enough.
        'createdAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
