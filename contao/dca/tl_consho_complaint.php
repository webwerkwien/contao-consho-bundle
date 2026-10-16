<?php declare(strict_types=1);

/*
 * A complaint as a note on the order (D-3, decision U2, 2026-09-27: "nur
 * Merker"): when it came in, what it was about, when it was dealt with. No
 * positions, no documents — a warranty case with its own handling is a later
 * block.
 *
 * Unlike the withdrawal this is the operator's own note, typed in the back
 * end, and it may be corrected or deleted like any note.
 */
$GLOBALS['TL_DCA']['tl_consho_complaint'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'enableVersioning' => true,
        'notCopyable' => true,
        'doNotCopyRecords' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['receivedAt', 'id'],
            'panelLayout' => 'limit',
            'headerFields' => ['orderNumber', 'lastname'],
        ],
        'label' => ['fields' => ['receivedAt', 'note', 'resolvedAt'], 'format' => '%s — %s — %s'],
        'operations' => [
            'edit',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => ['default' => '{complaint_legend},receivedAt,note,resolvedAt'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'receivedAt' => [
            'inputType' => 'text',
            'exclude' => true,
            // Today, as for a payment (D-1) — not 01.01.1970.
            'default' => time(),
            'eval' => ['mandatory' => true, 'rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'note' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'resolvedAt' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
    ],
];
