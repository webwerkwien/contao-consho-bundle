<?php declare(strict_types=1);

/*
 * The amounts of a shipping method, one row per currency (C2). Entered like
 * product prices (spec 5.3): currency, country, "gross | net", amount — only
 * net is stored, the amount is shown from net on load. The gross is converted
 * with the method's reference tax class (ShippingPriceInputListener).
 */
$GLOBALS['TL_DCA']['tl_consho_shipping_price'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_shipping_method',
        'enableVersioning' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['currency', 'id'],
            'panelLayout' => 'search,limit',
            'headerFields' => ['title', 'type'],
            'defaultSearchField' => 'currency',
        ],
        'label' => ['fields' => ['currency', 'net'], 'format' => '%s %s netto'],
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

    'palettes' => [
        // maxWeight only for a weight rate (WeightBracketListener takes it out elsewhere).
        'default' => '{price_legend},maxWeight,currency,inputCountry,inputMode,amount',
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_shipping_method'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Same rules as tl_consho_price.currency: options from the shops, no
        // submitOnChange, a currency without a shop is refused on save, and an
        // empty column default rather than 'EUR'.
        'currency' => [
            'inputType' => 'select',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
        ],
        'net' => [
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        /*
         * The upper end of a weight bracket in kg (E-4, V2), decimal(10,4) like
         * the product weights. Mandatory where shown: "bis 0 kg" holds nothing,
         * and WeightRateShipping ignores such a row rather than read it as
         * "no limit".
         */
        'maxWeight' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'digit', 'maxlength' => 12, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'inputCountry' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['includeBlankOption' => true, 'alwaysSave' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
        ],
        'inputMode' => [
            'inputType' => 'radio',
            'exclude' => true,
            'options' => ['gross', 'net'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_shipping_price']['inputModes'],
            'default' => 'gross',
            // consho-switch: as in tl_consho_price.
            'eval' => ['mandatory' => true, 'alwaysSave' => true, 'tl_class' => 'w50 clr consho-switch'],
            'sql' => ['type' => 'string', 'length' => 5, 'default' => 'net'],
        ],
        // 🔴 'sql' => null, not a missing key — see tl_consho_price.amount.
        'amount' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'digit', 'alwaysSave' => true, 'maxlength' => 16, 'tl_class' => 'w50'],
            'sql' => null,
        ],
    ],
];
