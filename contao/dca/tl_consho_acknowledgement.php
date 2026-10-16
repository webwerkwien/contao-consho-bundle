<?php declare(strict_types=1);

/*
 * Findings an operator has taken note of.
 *
 * Not a settings table and not editable in the back end: a row appears when
 * somebody clicks "Zur Kenntnis" on the start page, and disappears when the
 * finding it belongs to changes.
 *
 * ⚠️ The fingerprint covers the STATE of a finding, not its kind. Confirming
 * "GTIN 4006381333931 on 2 products" does not silence the same GTIN on three —
 * that is a different fingerprint and the note comes back. Without that rule a
 * confirmation would be a permanent blindfold, which is the problem it was
 * meant to solve, only quieter.
 *
 * Who and when is recorded because "we did that on purpose" is only a
 * statement if somebody stands behind it.
 */
$GLOBALS['TL_DCA']['tl_consho_acknowledgement'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'fingerprint' => 'unique',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // The code of the finding, kept alongside the fingerprint so the table
        // can be read by a human without recomputing hashes.
        'finding' => [
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'fingerprint' => [
            'sql' => ['type' => 'string', 'length' => 40, 'default' => ''],
        ],

        // Who took note. A user may be deleted later; the id then dangles, and
        // that is acceptable — the timestamp still says when.
        'author' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
