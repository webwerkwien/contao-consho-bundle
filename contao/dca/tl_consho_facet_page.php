<?php declare(strict_types=1);

/*
 * Facet combinations the editor deliberately turns into landing pages.
 *
 * "Schwedenbitter 250 ml", "Farben in Rot" — these get a path segment, their
 * own title and description, and are indexed. Everything else stays in the
 * excluded query parameter.
 *
 * The table is the editorial decision itself: a combination exists here or it
 * does not get indexed. Nothing is released automatically, because a shop with
 * six filters has thousands of combinations and almost none of them is a page
 * anyone searches for.
 * See docs/block-a-fundament.md, section 2.
 *
 * ── A landing page lives below its listing page ────────────────────────────
 *
 * pid is the category page carrying the listing element, and the landing
 * page's address is that page's auto_item: /kraeuter/schwedenbitter-250-ml.html.
 * Shop and language follow from the page, so there are no translation rows.
 * See docs/block-b-katalog-frontend.md, section 4.5 (decision B10).
 *
 * ── Why this is a module and not a child in the site structure ─────────────
 *
 * Releasing a facet is recurring editorial work: a search term turns up, a
 * page is written for it, another one is dropped again. It lives one click
 * from the menu rather than inside the page tree.
 *
 * So no ptable: the page is an ordinary field. And no entry in tl_page's ctable
 * either — see contao/dca/tl_page.php. Listed there, DC_Table::reviseTable()
 * would delete every row here whose pid is not an existing page, pid 0
 * included, whenever the site structure is listed. A record someone has just
 * created and not yet saved with a page would disappear. doNotDeleteRecords
 * would stop that, but it would stop the delete cascade as well.
 *
 * The cascade on deleting a page runs through FacetPageDeleteListener instead:
 * it appends the landing pages to the page's tl_undo entry and deletes them.
 */
$GLOBALS['TL_DCA']['tl_consho_facet_page'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'enableVersioning' => true,

        // Copying a category page must not copy its landing pages: the copy
        // starts with the same filter preset, so every copied landing page
        // would be a second indexed page with identical content.
        // Today that already follows from not being in tl_page's ctable —
        // copyChildren() only walks ctable. The flag stays as the guard should
        // the table ever be listed there again: copyChildren() checks it on
        // the CHILD table. It does not affect copying a landing page itself.
        'doNotCopyRecords' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
                'pid,alias' => 'unique',

                // The same filter combination must not be released twice: that
                // would be two indexed pages with identical content, the very
                // problem this table prevents. FacetStateListener normalises
                // the value first, or the index could be walked around by
                // typing the groups in another order.
                'pid,filterState' => 'unique',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            // Tie-break: aliases are free text, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['alias', 'id'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'alias',
        ],
        'label' => [
            'fields' => ['alias', 'pid', 'filterState'],
            'format' => '%s <span class="tl_gray">[%s] %s</span>',
        ],
        'operations' => [
            'edit',
            '!copy',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        'default' => '{page_legend},pid;{filter_legend},filterState,alias;{seo_legend},pageTitle,description',
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],

        /*
         * The listing page this landing page lives below. Its address is that
         * page's auto_item: /<page alias>/<alias>.html. Shop and language follow
         * from the page, and so does the filter preset the combination narrows.
         *
         * "250 ml" below "Herbal bitters" and "250 ml" below "Teas" are two
         * different sets of products and two different searches — so the
         * release belongs to the page, not to the shop.
         *
         * A select, not a pageTree: the tree can be limited to root nodes, not
         * to "pages that carry a listing". The options come from
         * FacetPagePageListener; FacetStateListener checks the page again on
         * save.
         */
        'pid' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'foreignKey' => 'tl_page.title',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_page'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * Stored normalised — see FacetStateListener. What stands here is what
         * FilterStateCodec produces, never what was typed.
         */
        /*
         * ⚠️ Nullable, not `default ''` — both columns hang off a UNIQUE
         * index. Contao creates an empty row first when a record is added;
         * with `default ''` the first unfinished record would occupy the
         * pair (0, '') and the SECOND one could no longer be created. NULL
         * does not collide with NULL in a UNIQUE index.
         */
        'filterState' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'clr'],
            'sql' => ['type' => 'string', 'length' => 255, 'notnull' => false],
        ],
        'alias' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'alias', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'notnull' => false],
        ],
        /*
         * Mandatory: a landing page carrying its category's title would be a
         * second page with the same title — the opposite of its purpose.
         */
        'pageTitle' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'clr'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'description' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'eval' => ['tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
