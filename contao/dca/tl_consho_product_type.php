<?php declare(strict_types=1);

/*
 * Product types decide which properties and custom fields a product may carry.
 */
$GLOBALS['TL_DCA']['tl_consho_product_type'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // Translations go with the record on delete; see tl_consho_product.
        'ctable' => ['tl_consho_translation'],
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
            'panelLayout' => 'filter;search,limit',

            // Names the record in the breadcrumb; see tl_consho_product.
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title', 'kind'],
            'format' => '%s <span class="label-info">[%s]</span>',
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
        'default' => '{title_legend},title,alias,kind;{template_legend},detailPartial;{property_legend},propertyGroups;{custom_legend},customFields',
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
         * Physical, digital or service (docs/block-dienstleistung.md, DL1, DL5).
         *
         * The kind sits on the TYPE rather than the product: an operator
         * defines the type once and every product of it inherits the answer —
         * shipping, product safety, withdrawal, tax country and whether a
         * subscription can be sold all follow from it. On the product it would
         * be the same question for the thousandth article.
         *
         * Default physical. Whoever sets nothing sells physical goods in case
         * of doubt and gets the product safety check; the opposite default
         * would switch off a legal obligation in silence.
         *
         * Frozen on the order line and the subscription at purchase, so a type
         * changed later does not change what was sold.
         */
        'kind' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'options' => array_map(static fn (Webwerkwien\ContaoConshoBundle\Catalog\ProductKind $kind): string => $kind->value, Webwerkwien\ContaoConshoBundle\Catalog\ProductKind::cases()),
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_product_type']['kinds'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'physical'],
        ],
        /*
         * The type's own fields on the detail page — a wine's vintage and grape,
         * a glass's nothing. The element owns the layout; this owns a partial for
         * what only this type has (docs/block-b-katalog-frontend.md, 7.2).
         *
         * Not overridable per product: a field meant to stay empty gets filled.
         *
         * Offered: every variant of consho_product_type, e.g.
         * templates/consho_product_type/wine.html.twig — see
         * DetailPartialOptionsListener. Empty means no partial, not an error.
         */
        'detailPartial' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
        'propertyGroups' => [
            'inputType' => 'checkboxWizard',
            'exclude' => true,
            'foreignKey' => 'tl_consho_property_group.title',
            'eval' => ['multiple' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
            'relation' => ['type' => 'hasMany', 'load' => 'lazy'],
        ],
        'customFields' => [
            'inputType' => 'checkboxWizard',
            'exclude' => true,
            'foreignKey' => 'tl_consho_custom_field.title',
            'eval' => ['multiple' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
            'relation' => ['type' => 'hasMany', 'load' => 'lazy'],
        ],
    ],
];
