<?php declare(strict_types=1);

/*
 * One rate per tax class, country and validity period.
 *
 * The period is not a refinement. Germany cut VAT temporarily in 2020; without
 * a validity window every invoice from that time would retroactively show the
 * rate that came afterwards. The period is what keeps a document reproducible.
 */
$GLOBALS['TL_DCA']['tl_consho_tax_rate'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_tax_class',
        'enableVersioning' => true,

        // A rate belongs to its tax class; moving it would change the gross of
        // the articles of two classes, and `oncut` runs only after the new pid
        // is written, when the old class can no longer be read (C25). The
        // table has no manual order either.
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
                'pid,country' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            // Tie-break: a country carries several rates, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['country', 'id'],
            'panelLayout' => 'search,limit',
            'headerFields' => ['title'],

            // Names the record in the breadcrumb; see tl_consho_product.
            'defaultSearchField' => 'country',
        ],
        'label' => ['fields' => ['country', 'rate'], 'format' => '%s — %s %%'],
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

    'palettes' => ['default' => '{rate_legend},country,rate;{validity_legend},validFrom,validTo'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'foreignKey' => 'tl_consho_tax_class.title',
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'country' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'eval' => ['mandatory' => true, 'chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
        ],
        'rate' => [
            'inputType' => 'text',
            'exclude' => true,

            /*
             * `digit` is not integers only: Contao validates it with
             * Validator::isNumeric() and converts a decimal comma to a dot
             * itself. That matters — Ireland charges 13.5 % and Switzerland
             * 8.1 %, so a whole-number field would be wrong within the EU's
             * own neighbourhood.
             */
            'eval' => ['mandatory' => true, 'rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 6, 'scale' => 4, 'default' => '0.0000'],
        ],
        /*
         * 🔴 Both date columns are NULL-able, and that is not a detail.
         *
         * With `default 0` the field renders as 01.01.1970 instead of empty,
         * so "leave empty for always" becomes impossible to follow. Worse, the
         * back end then submits that date back: in CET it parses to -3600, and
         * an unsigned column rejects it with SQLSTATE[22003]. Measured on
         * the test server on 2026-09-09 — saving the very first rate failed.
         *
         * NULL means "no boundary". See the vault note "Eigene Tabelle mit
         * Backend-Maske in Contao 5", trap 1.
         *
         * Date only, unlike tl_consho_price (date and time): a tax rate changes
         * at the start of a day. validTo includes that whole day, so the date is
         * entered as the law states it. See ContaoTaxRateRepository.
         */
        'validFrom' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        'validTo' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
    ],
];
