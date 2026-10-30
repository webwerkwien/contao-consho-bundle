<?php declare(strict_types=1);

/*
 * Property groups — "Größe", "Farbe", "Gebinde – Volumen".
 *
 * Properties are normalised option lists and are the ONLY filterable
 * mechanism. Custom fields hold everything else and are deliberately not
 * filterable. Shopware and WooCommerce arrived at the same separation
 * independently; Isotope does not have it, which is why its filter had to run
 * over generic value tables. See Block A, section 3.1.
 */
$GLOBALS['TL_DCA']['tl_consho_property_group'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // The option table stays FIRST: Contao's default "children" operation
        // and the breadcrumb take ctable[0]. Translations go with the record
        // on delete; see tl_consho_product.
        'ctable' => ['tl_consho_property_option', 'tl_consho_translation'],
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
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
            'panelLayout' => 'search,limit',

            /*
             * Without this the breadcrumb falls back to the `edit` operation
             * label — and here `edit` jumps to the values, so editing the
             * property itself was headed "Werte der Eigenschaft ID 1
             * bearbeiten". See tl_consho_product for the mechanism.
             */
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title'],
            'format' => '%s',
        ],
        'operations' => [
            /*
             * `edit` means "edit this record" across every Consho table. The
             * jump to the children is its own operation.
             *
             * The other way round — `edit` jumping to the children and
             * `editheader` editing the record — is what Contao core does for
             * tl_news_archive, and it cost us a wrong breadcrumb: Contao names
             * a record without a title from the `edit` label, so creating a
             * property was headed "Werte der Eigenschaft ID 1 bearbeiten" over
             * a form showing the property itself.
             */
            // `primary` keeps the jump in the row — see tl_consho_product.
            'values' => ['href' => 'table=tl_consho_property_option', 'icon' => 'modules.svg', 'primary' => true],
            'edit',
            '!copy',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        'default' => '{title_legend},title,alias,filterable',
    ],

    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
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
         * The group's half of a filter address: the state is spelled
         * "colour:red", so this is what stands before the colon. See
         * Seo\FilterStateCodec (A-10) — which was written against aliases that
         * did not exist yet; they arrive here in B-3.
         *
         * Plain index, not UNIQUE: Contao writes an empty row when a record is
         * created, and with `default ''` the first unfinished one would occupy
         * ('') and block the second. Uniqueness is Catalog\AliasFactory's job.
         */
        'alias' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['rgxp' => 'alias', 'unique' => true, 'maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
        // The only question the editor is ever asked about this mechanism —
        // and it is about intent, not about data structures. Nobody has to
        // decide between "property" and "custom field".
        'filterable' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => true],
        ],
    ],
];
