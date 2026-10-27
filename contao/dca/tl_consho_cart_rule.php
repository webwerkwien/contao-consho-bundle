<?php declare(strict_types=1);


/*
 * Cart rules (Block H, H-4; H6, W1–W6): a discount on
 * the basket — with a code the customer types in the basket, or without one,
 * applied by itself.
 *
 * - Effect: percent off, a fixed amount off, or free shipping (W4: the chosen
 *   shipping method costs 0).
 * - A fixed amount is meant as the customer reads the prices (W2): gross, net
 *   for a customer shown net prices. It and the minimum order value (W3) are
 *   amounts of one CURRENCY: a rule stating either applies in that currency
 *   only.
 * - The base: goods lines, never subscription lines (H8), not the reduced ones
 *   unless `reduced`, and only those the product
 *   conditions match.
 * - One code per order; the cheapest for the customer among the code and the
 *   rules without one, no sum (C3).
 * - The code is compared exactly — "Groß- und Kleinschreibung ist nicht egal"
 *   (W6) — in PHP, because the column's collation would not.
 * - Uses in total and per customer (W5: member by account, guest by e-mail),
 *   counted in tl_consho_cart_rule_redemption at the purchase click.
 */
$GLOBALS['TL_DCA']['tl_consho_cart_rule'] = [
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
            'fields' => ['title', 'code'],
            'format' => '%s <span class="tl_gray">%s</span>',
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
        '__selector__' => ['effect'],
        'default' => '{title_legend},title,code;{effect_legend},effect,reduced;{condition_legend},shops,currency,minimumOrder,products,manufacturers,productTypes,memberGroups;{limit_legend},maxUses,maxUsesPerCustomer;{publish_legend},published,validFrom,validTo',
        'percent' => '{title_legend},title,code;{effect_legend},effect,percent,reduced;{condition_legend},shops,currency,minimumOrder,products,manufacturers,productTypes,memberGroups;{limit_legend},maxUses,maxUsesPerCustomer;{publish_legend},published,validFrom,validTo',
        'amount' => '{title_legend},title,code;{effect_legend},effect,amount,reduced;{condition_legend},shops,currency,minimumOrder,products,manufacturers,productTypes,memberGroups;{limit_legend},maxUses,maxUsesPerCustomer;{publish_legend},published,validFrom,validTo',
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Stands on the invoice and in the basket as the discount's name.
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        // Empty: the rule applies by itself. Exact match, see above (W6).
        'code' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
            'save_callback' => [static fn (mixed $value): string => trim((string) $value)],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'effect' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'options' => ['percent', 'amount', 'freeShipping'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_cart_rule']['effects'],
            'eval' => ['mandatory' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'percent'],
        ],
        'percent' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'digit', 'minval' => 0.01, 'maxval' => 100, 'maxlength' => 6, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'decimal', 'precision' => 5, 'scale' => 2, 'default' => '0.00'],
        ],
        'amount' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['conshoMoney' => true, 'mandatory' => true, 'rgxp' => 'digit', 'minval' => 0.01, 'maxlength' => 13, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'reduced' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        // Empty: every shop.
        'shops' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_shop.title',
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        // Required where an amount is stated — the amount off or the minimum.
        'currency' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 3, 'rgxp' => 'alpha', 'tl_class' => 'w50'],
            'save_callback' => [static fn (mixed $value): string => strtoupper(trim((string) $value))],
            'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
        ],
        'minimumOrder' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['conshoMoney' => true, 'rgxp' => 'digit', 'maxlength' => 13, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
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
        'memberGroups' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_member_group.name',
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        // 0: no limit.
        'maxUses' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 10, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'maxUsesPerCustomer' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 10, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'published' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'toggle' => true,
            'filter' => true,
            'eval' => ['doNotCopy' => true, 'tl_class' => 'w50 m12 clr consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
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
