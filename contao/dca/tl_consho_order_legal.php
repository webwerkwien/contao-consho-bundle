<?php declare(strict_types=1);

/*
 * What the customer actually read, frozen at the click (spec 5.1, C-17).
 *
 * The terms and the withdrawal instruction hang off a content element, and a
 * content element can be edited. Linking to it from an order would mean that
 * next year's wording describes last year's contract — which is the one thing
 * a record of consent must not do. § 7 Abs 3 FAGG wants the § 4 information on
 * a durable medium; a reference to an editable element is not one.
 *
 * The text is stored with insert tags RESOLVED. A frozen `{{date}}` that
 * re-renders at display time would quietly move with the calendar, and the
 * checksum would no longer match what anyone sees.
 *
 * ⚠️ Readable in the back end, never writable: notEditable, notCopyable,
 * notDeletable, and no `new` operation. An operator has to be able to show a
 * court what was on screen; nobody has to be able to change it afterwards.
 * That is also why the table carries no versioning — there is no second
 * version to keep.
 */
$GLOBALS['TL_DCA']['tl_consho_order_legal'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid,kind' => 'index']],
    ],

    'list' => [
        'sorting' => ['mode' => 4, 'fields' => ['kind'], 'panelLayout' => 'limit', 'headerFields' => ['orderNumber', 'lastname']],
        'label' => ['fields' => ['kind', 'headline'], 'format' => '%s — %s'],
        'operations' => ['show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true]],
    ],

    'palettes' => ['default' => '{legal_legend},kind,headline,text;{proof_legend},checksum,sourceElement,capturedAt'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            // Without it Contao shows pid through the FIRST list field of
            // tl_consho_order, which is `signals` — no column, so "Info" was
            // a 500 (ParentLabelIsAColumnTest).
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // `terms` | `withdrawal` — LegalTextKind.
        'kind' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],

        'headline' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        /*
         * 🔴 No `'rte' => false` here, however explicit that would read.
         *
         * Contao's own PrepareForOutputEncodingMigration does
         * `str_starts_with($fieldConfig['eval']['rte'] ?? '', 'tiny')`, and
         * `??` does not catch `false` — only null. Setting the key to false
         * therefore breaks `contao:migrate` with a TypeError from inside the
         * core, on every installation, for every later migration too. The
         * absence of a rich text editor is expressed by not naming one.
         *
         * The schema changes are already applied when the run dies, so the
         * failure looks like something the server did rather than something
         * this file said.
         */
        'text' => [
            'inputType' => 'textarea',
            'eval' => ['readonly' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],

        /*
         * The same checksum the checkout showed. It is what makes this row
         * evidence rather than a copy: two orders that read the same wording
         * carry the same value, and a text that changed between them is
         * visible without reading both.
         */
        'checksum' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],

        // Where it came from. Allowed to dangle — the element may be deleted,
        // and the text above survives it. That is the point.
        'sourceElement' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        'capturedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
