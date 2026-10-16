<?php declare(strict_types=1);


/*
 * Catalogue price rules (block H, H-3; docs/block-h-preise.md, H5): "20 %
 * on manufacturer X until Sunday" — a promotion over many products without a
 * price row at each.
 *
 * Percent only (Michael, 2026-10-05: "ja, wie empfohlen"): a fixed amount is
 * meant gross by the operator and would be a different net amount per tax
 * rate. Fixed amounts are the cart rules' (H-4).
 *
 * Conditions: an empty list means "any". All filled-in lists have to match.
 * The rule works on the price PriceCalculator found (CatalogPriceRules), as a
 * promotion: struck through against the 30-day price like any other (H-2).
 * `reduced`: whether it also applies to goods already on promotion (Michael:
 * "man muss auch festlegen können, ob der Rabatt auch für bereits reduzierte
 * Ware gilt"). Several matching rules: the cheapest result, no sum (C2).
 *
 * No shop: price rows have none either — prices are per currency, and the
 * projection per market, not per shop.
 */
$GLOBALS['TL_DCA']['tl_consho_price_rule'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'published' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            'fields' => ['title', 'id'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title', 'percent'],
            'format' => '%s <span class="tl_gray">[−%s %%]</span>',
        ],
        'operations' => [
            'edit',
            '!copy',
            '!delete',
            'toggle',
            '!show',
            '!versions',
        ],
    ],

    'palettes' => [
        'default' => '{title_legend},title,percent,reduced;{condition_legend},products,manufacturers,productTypes,propertyOptions,memberGroups;{publish_legend},published,validFrom,validTo',
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Back end only — the customer sees the badge and the struck price, not the name.
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'percent' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'digit', 'minval' => 0.01, 'maxval' => 100, 'maxlength' => 6, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 5, 'scale' => 2, 'default' => '0.00'],
        ],
        'reduced' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'filter' => true,
            'eval' => ['tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        // Main products; a variant matches through its main product.
        'products' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        'manufacturers' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_manufacturer.title',
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        'productTypes' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_product_type.title',
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        // Filter properties (the one filter criterion, block A); any of them.
        'propertyOptions' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        // Empty: everyone, guests included. Otherwise members of any of them.
        'memberGroups' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_member_group.name',
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        'published' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'toggle' => true,
            'filter' => true,
            'eval' => ['doNotCopy' => true, 'tl_class' => 'w50 m12 clr'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        // Date and time, from inclusive, to exclusive — as the price rows
        // (tl_consho_price, A-4b), NULL for no boundary.
        'validFrom' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard clr'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        'validTo' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
    ],
];
