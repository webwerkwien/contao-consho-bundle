<?php declare(strict_types=1);

/*
 * One counter per shop AND per type.
 *
 * Order numbers may have gaps — they go to the payment provider as a reference
 * and exist before anyone knows whether the order completes. Document numbers
 * must not: the tax office wants an unbroken sequence. Keeping them apart is
 * why this is a table and not a column.
 * See docs/block-a-fundament.md, section 3.5.
 */
$GLOBALS['TL_DCA']['tl_consho_number_range'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_shop',
        /*
         * 🔴 **Leaving the delete operation out hides the button — it does
         * not forbid the act.** `DC_Table::delete()` and `deleteAll()` ask
         * `notDeletable` and nothing else, and "select multiple" builds its
         * actions from the config rather than from the operations list.
         * Measured on c5 on 2026-09-23: the mass-edit view of this very table
         * offered Löschen, although no row has a delete button.
         *
         * What that meant here: the invoice counter was deletable. The next
         * invoice would then start at 1 again, beside the ones already
         * issued — an unbroken sequence is exactly what this table exists to
         * guarantee, and a gap is the one thing the tax office looks for.
         *
         * The cascade stays intact: deleting a shop still takes its ranges,
         * because `DC_Table::deleteChildren()` asks `doNotDeleteRecords`, not
         * this flag.
         */
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid,type' => 'unique']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            // Tie-break: there are only a handful of types, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['type', 'id'],
            'panelLayout' => 'search,limit',
            'headerFields' => ['title'],
            'defaultSearchField' => 'type',
        ],
        'label' => ['fields' => ['type', 'prefix', 'current'], 'format' => '%s — %s%s'],
        'operations' => [
            'edit',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
        ],
    ],

    'palettes' => ['default' => '{range_legend},type,prefix,padding,current'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_shop'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'type' => [
            'inputType' => 'select',
            'exclude' => true,
            'search' => true,
            'options' => ['order', 'invoice', 'deliveryNote', 'creditNote', 'cancellation'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_number_range']['types'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => 'order'],
        ],
        'prefix' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 32, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        'padding' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 5],
        ],

        /*
         * The counter itself is editable on purpose: an operator migrating
         * from another system has to be able to continue an existing sequence,
         * and a new prefix may start again at 0. Below a number already issued
         * under the same prefix it is refused (NumberRangeListener, CW-5 I4).
         */
        // doNotCopy: copying a shop copies its number ranges (ctable), and
        // the counter went along — the copy's first invoice was the source's
        // next number, and with the same prefix a number existed twice.
        // Found at the acceptance of C-12 (2026-09-26): "Testshop 2" was a
        // copy and stood at 4 invoices without ever issuing one.
        'current' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'doNotCopy' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
