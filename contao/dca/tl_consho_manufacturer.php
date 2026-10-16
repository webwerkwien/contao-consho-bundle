<?php declare(strict_types=1);

/*
 * Manufacturers, and the EU responsible persons that stand in for those outside
 * the Union — one table for both, because they carry the same data.
 *
 * The EU responsible person hangs off the MANUFACTURER, not the product: it is
 * the same for every article that manufacturer makes. On the product it would
 * be entered once per article and got wrong once per article.
 *
 * Required by Regulation (EU) 2023/988 (GPSR), Art. 19, in force since
 * 2024-12-13: every online offer of a physical product has to name the
 * manufacturer with a postal AND an electronic address.
 * See docs/block-a-fundament.md, section 8.
 */
$GLOBALS['TL_DCA']['tl_consho_manufacturer'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // Translations go with the record on delete; see tl_consho_product.
        'ctable' => ['tl_consho_translation'],
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'title' => 'index',
                'alias' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            // Tie-break: titles are free text, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['title', 'id'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title', 'country'],
            'format' => '%s <span class="tl_gray">[%s]</span>',
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
        'default' => '{title_legend},title,alias,logo;{address_legend},street,postal,city,state,country;{contact_legend},email,contactUrl,website;{eu_legend},euResponsible',
    ],

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
         * Only here because the product path template may contain this record
         * as a segment (Seo\ProductPathTemplate). A plain index, not UNIQUE:
         * Contao writes an empty row when a record is created, and with
         * `default ''` the first unfinished one would occupy ('') and block the
         * second — the trap paid for in A-10. Uniqueness is enforced by
         * Catalog\AliasFactory instead, the same way tl_consho_product does it.
         */
        'alias' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['rgxp' => 'alias', 'unique' => true, 'maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],

        /*
         * From Contao's file manager. Consho creates no image folders of its
         * own — principle 5: cover functions with Contao's core elements.
         */
        'logo' => [
            'inputType' => 'fileTree',
            'exclude' => true,
            'eval' => [
                'filesOnly' => true,
                'fieldType' => 'radio',
                'extensions' => '%contao.image.valid_extensions%',
                'tl_class' => 'w50',
            ],
            'sql' => ['type' => 'binary', 'length' => 16, 'notnull' => false],
        ],

        /*
         * The postal address is mandatory in full. "Name and address" under
         * Art. 19 means an address a letter reaches, not a town.
         */
        'street' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'postal' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 32, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        'city' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'state' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        /*
         * ⚠️ Options come from ManufacturerCountryOptions, NOT from the list
         * behind tax rates and delivery countries. That one is restrictable to
         * the countries a shop serves, and a seat abroad has nothing to do with
         * where the shop delivers.
         */
        'country' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
        ],

        /*
         * 🔴 email is mandatory, website is not, and the difference is the
         * whole point: an electronic address must allow direct contact. A
         * marketing page does not discharge the duty, and the field help says
         * so — otherwise everyone enters the marketing page.
         */
        'email' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'email', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'contactUrl' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'url', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'website' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'url', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        /*
         * Points at another record of this table. EuResponsibleOptionsListener
         * offers only manufacturers seated in the Union and never the record
         * itself.
         *
         * ── "Person" is the legal term, and it means a company ─────────────
         *
         * GPSR Art. 16 requires a responsible ECONOMIC OPERATOR established in
         * the Union; the catalogue of who may be one comes from Art. 4 of
         * Regulation (EU) 2019/1020: the manufacturer if seated here, the
         * importer, an authorised representative under written mandate, or a
         * fulfilment service provider. All of those are firms in practice.
         *
         * 🎯 The common case for a small shop: importing directly from a third
         * country makes the SHOP the importer, and therefore the responsible
         * operator itself. Buying through a German wholesaler makes it the
         * wholesaler. The field help says so, because "Person" reads like a
         * human being and gets a human being typed into it.
         */
        'euResponsible' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_manufacturer.title',
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
    ],
];
