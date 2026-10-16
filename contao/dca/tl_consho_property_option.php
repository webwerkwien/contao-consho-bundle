<?php declare(strict_types=1);

/*
 * A single value of a property — "250 ml", "rot".
 */
$GLOBALS['TL_DCA']['tl_consho_property_option'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_property_group',
        // Translations go with the record on delete; see tl_consho_product.
        'ctable' => ['tl_consho_translation'],
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'alias' => 'index',
                'pid' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            // Tie-break: sorting values collide after a paste, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['sorting', 'id'],
            'panelLayout' => 'search,limit',
            'headerFields' => ['title'],

            // Names the record in the breadcrumb; see tl_consho_product.
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title'],
            'format' => '%s',
        ],
        'operations' => [
            'edit',
            // Written out on purpose, unlike everywhere else in this bundle:
            // Contao's named default would be the clipboard here, because this
            // table has a ptable. CopyOperationPermissionListener says what
            // that costs and brings the POST and the permission check back.
            'copy' => ['href' => 'act=copy', 'method' => 'POST', 'icon' => 'copy.svg', 'primary' => true],
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        'default' => '{title_legend},title,alias',
    ],

    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'pid' => [
            'foreignKey' => 'tl_consho_property_group.title',
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'sorting' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        /*
         * The option's half of a filter address — what stands after the colon
         * in "colour:red".
         *
         * Unique WITHIN ITS GROUP, not table-wide: "rot" may exist once under
         * Farbe and once under Lackfarbe, because the group is already part of
         * the address. Enforced by Catalog\AliasFactory with an AliasScope on
         * `pid`, not by a database key — see the note about empty rows in
         * tl_consho_property_group.
         */
        'alias' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['rgxp' => 'alias', 'maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
    ],
];
