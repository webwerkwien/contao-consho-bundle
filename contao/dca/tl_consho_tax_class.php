<?php declare(strict_types=1);

/*
 * A product carries a tax CLASS, not a rate. Country and order date resolve
 * the rate, which is then frozen on the order item. That is what later carries
 * destination-based taxation and OSS without touching the schema.
 * See docs/block-a-fundament.md, section 3.5.
 */
$GLOBALS['TL_DCA']['tl_consho_tax_class'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ctable' => ['tl_consho_tax_rate'],
        'enableVersioning' => true,
        'sql' => ['keys' => ['id' => 'primary']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            // Tie-break: titles are free text, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['title', 'id'],
            'flag' => 1,
            'panelLayout' => 'search,limit',

            /*
             * Without this the breadcrumb falls back to the `edit` operation
             * label, and `edit` jumps to the rates here — editing the class
             * itself would be headed "Sätze der Steuerklasse ID 1 bearbeiten".
             * See tl_consho_product for the mechanism.
             */
            'defaultSearchField' => 'title',
        ],
        'label' => ['fields' => ['title'], 'format' => '%s'],
        'operations' => [
            // `edit` edits the record, the child table gets its own operation.
            // See tl_consho_property_group for what the reverse arrangement cost.
            // `primary` keeps the jump in the row — see tl_consho_product.
            'rates' => ['href' => 'table=tl_consho_tax_rate', 'icon' => 'header.svg', 'primary' => true],
            'edit',
            '!copy',
            // The '!' sets `primary` and keeps it in the row, because
            // TaxClassDeleteVoter greys this one out for a class still in use —
            // see tl_consho_shipping_method for why the ⋯ menu would swallow it.
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => ['default' => '{title_legend},title,notTaxable'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        /*
         * Block Wertgutscheine (WG3): the class of multi-purpose vouchers —
         * their sale is not a taxable supply, so no rate in any country
         * (ContaoTaxRateRepository::findRate() answers 0, the class needs no
         * rates). Whether a voucher is multi-purpose is the operator's call
         * (WG2); the findings keep the class and the product kind together.
         */
        'notTaxable' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'filter' => true,
            'eval' => ['tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
    ],
];
