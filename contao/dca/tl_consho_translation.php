<?php declare(strict_types=1);

/*
 * Translations of every entity with visible text — one table, addressed via
 * ptable/pid, like tl_content. See Block M, 3.2.
 *
 * No back end view of its own: it is served from the parent table's mask
 * (M-4). The DCA exists for the schema and for tl_undo.
 */
$GLOBALS['TL_DCA']['tl_consho_translation'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,

        /*
         * 🔴 Required as soon as any table lists this one as its `ctable`.
         *
         * Without it DC_Table addresses child rows by `pid` alone. Deleting
         * product 5 would then take manufacturer 5's translations along
         * (deleteChildren), copying it would duplicate them under the wrong
         * parent (copyChildren), and — worst, because it needs no action at
         * all — merely opening the product list would delete every translation
         * of every other table whose pid has no product of that id
         * (reviseTable, "records of the child table that are not related").
         * With it, all three add `AND ptable = <parent table>`, the same way
         * tl_content serves articles, themes and itself.
         *
         * `notCreatable` and `notCopyable` do not interfere: they only govern
         * a list view of this table, which does not exist.
         */
        'dynamicPtable' => true,
        'notCreatable' => true,
        'notCopyable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'ptable,pid,language' => 'unique',
                'ptable,language,alias' => 'unique',
                'ptable,language' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'ptable' => [
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'language' => [
            'sql' => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'title' => [
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        /*
         * NULL, not '' — and that is not a matter of taste. Property groups
         * and options have no alias (specification 6.1). If the empty string
         * stood there instead, the SECOND option of the same language could
         * not be saved: MySQL treats two empty strings in a unique index as a
         * duplicate, but two NULLs as different.
         *
         * `doNotCopy`, because copying a parent copies its translations too
         * (DC_Table::copyChildren walks every ctable on act=copy, whether or
         * not `children` is in the URL). A copied alias would violate the
         * unique index (ptable, language, alias) and abort the copy with an
         * SQL error. Emptied, the column falls back to NULL — this is the same
         * treatment the parent's own alias gets, where `unique` empties it.
         * The texts are kept; saving the copy generates a fresh alias from
         * the translated title.
         */
        'alias' => [
            'eval' => ['doNotCopy' => true],
            'sql' => ['type' => 'string', 'length' => 255, 'notnull' => false],
        ],
        'teaser' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'metaDescription' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'description' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'safetyWarnings' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'safetyInformation' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        // Payment notice of a payment method (C-2).
        'instructions' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        // The operator's invoice texts of a shop (C-8).
        'invoiceFooter' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'invoiceReverseChargeNote' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        // A product's custom field texts in this language, alias => text,
        // through CustomFieldCodec (ZW-3).
        'customFieldValues' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
