<?php declare(strict_types=1);

/*
 * One maintained status, freely named — with system flags underneath.
 *
 * The operator names the value; the system reads the flags, never the name.
 * Someone who calls their status "Kohle da" still gets correct documents,
 * and a translated shop does not need the code to learn a second vocabulary.
 * See docs/block-a-fundament.md, section 5.4.
 */
$GLOBALS['TL_DCA']['tl_consho_order_status'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_shop',
        'enableVersioning' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index']],
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
        'label' => ['fields' => ['title'], 'format' => '%s'],
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

    'palettes' => ['default' => '{title_legend},title,colour;{flag_legend},isPaid,isCancelled,isShipped,isCompleted,locksChanges'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_shop'],
        ],
        'sorting' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        /*
         * What this status signals in the order list.
         *
         * It has to be asked, not derived. The titles in this table are the
         * operator's own — "In Klärung", "Wartet auf Vorkasse", "Retoure
         * geprüft" — and no rule Consho could write would know which of those
         * is a problem and which is routine. The flags below don't answer it
         * either: they say what the system DOES. isCompleted (D-1) is a checked
         * fact, not a verdict on whether the operator still has work.
         *
         * Payment and delivery need no such field. Both come from enums, so
         * their mapping is fixed and lives in StatusColour.
         */
        'colour' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => ['open', 'progress', 'attention', 'done'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_order_status']['colours'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'open'],
        ],
        'isPaid' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'isCancelled' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'isShipped' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        /*
         * The order is done: payment and delivery both settled (D-1, A1).
         *
         * Block A left an "is complete" out on purpose (5.4) — the argument
         * was the colour: no rule knows whether a status is routine. This flag
         * says nothing about colour; it states a fact the balance can check,
         * and it is refused where the balance does not bear it out
         * (StatusRequirement).
         */
        'isCompleted' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'locksChanges' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
    ],
];
