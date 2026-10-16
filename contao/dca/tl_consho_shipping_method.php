<?php declare(strict_types=1);

/*
 * Shipping methods, created once and selected per shop (C2). The code behind
 * a method is its type (ShippingMethodTypeInterface); module settings are
 * virtual fields in jsonData, added by the module with a palette per type
 * (C3). jsonData is declared below although the flat rate has no such field.
 *
 * A method that occurs in an order cannot be deleted (ShippingMethodDeleteVoter).
 * See docs/block-c-warenkorb-checkout.md, sections 5.1 and 6.1. "select multiple"
 * → delete skips a method in use silently: DC_Table::deleteAll() catches the
 * AccessDeniedException per id and continues with the next one.
 */
// One palette for the method without a type and for the flat rate; a module
// appends its own legend to a copy of it, like tl_content.
// Countries and limits for every type, modules included (E-5): they are the
// core's rules, asked before the type.
$conshoShippingPalette = '{title_legend},title,type,description;{tax_legend},taxMode,taxClass;{rules_legend},countries,maxLength,maxGirth,maxPieceWeight;{publish_legend},published';

$GLOBALS['TL_DCA']['tl_consho_shipping_method'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // Amounts first: the default child operation and the breadcrumb take ctable[0].
        // Translations as child rows too: deleting a method takes them into tl_undo.
        'ctable' => ['tl_consho_shipping_price', 'tl_consho_translation'],
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'published' => 'index',
                // TaxClassDeleteVoter, see tl_consho_product for the reason.
                'taxClass' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            // Tie-break: titles are free text; id is autoincrement and never ties.
            'fields' => ['title', 'id'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title', 'type'],
            'format' => '%s <span class="tl_gray">[%s]</span>',
        ],
        'operations' => [
            // Own operation, pencil keeps editing the method — see tl_consho_product.
            // `primary` keeps the jump in the row — see tl_consho_product.
            'prices' => ['href' => 'table=tl_consho_shipping_price', 'icon' => 'header.svg', 'primary' => true],
            'edit',
            '!copy',
            // Core default operation (name-only, not an array): DefaultOperationsListener
            // attaches the DeleteAction permission check to it, so MethodInUseVoter greys
            // out the button for a method used in an order. An array operation here would
            // be copied unchanged and skip that check.
            //
            // The '!' sets `primary`, which keeps it in the ROW. A greyed-out button only
            // says anything where it can be seen: disable() strips the href, and the
            // template draws an operation without one as a plain icon in the row
            // (`elseif icon and not menu`) but renders NOTHING for it in the ⋯ menu.
            '!delete',
            'toggle',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        '__selector__' => ['type'],
        'default' => $conshoShippingPalette,
        // Cash on delivery is a matter of the contract with the carrier, so a
        // tick at the flat rate (E-3, Z2); a carrier module declares it in code.
        'flat_rate' => str_replace(';{publish_legend}', ';{cod_legend},cashOnDelivery;{publish_legend}', $conshoShippingPalette),
        // Gewichtsstaffel (E-4, V2): the brackets are its price rows.
        'weight_rate' => str_replace(';{publish_legend}', ';{cod_legend},cashOnDelivery;{publish_legend}', $conshoShippingPalette),
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
        // Module settings (C3). Declared rather than left to
        // VirtualFieldsMappingListener: that creates the column only once a
        // field targets it, and the flat rate has none — while
        // DbalShippingMethodRepository reads it for every method.
        // doNotShow: with no field targeting it, it is no virtual target, and
        // "show" would list it as an empty row.
        'jsonData' => [
            'eval' => ['doNotShow' => true],
            'sql' => ['type' => 'json', 'length' => 16777215, 'notnull' => false],
        ],
        // Options: the installed types (MethodTypeOptionsListener). The key is
        // stored, never the class name (research, conclusion 1).
        'type' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        'description' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'eval' => ['rte' => 'tinyMCE', 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'taxMode' => [
            'inputType' => 'radio',
            'exclude' => true,
            'options' => ['proportional', 'fixed'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_shipping_method']['taxModes'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'proportional'],
        ],
        /*
         * Mandatory in both modes (decision 2026-09-15). Fixed: shipping is
         * taxed with it. Proportional: the tax follows the goods, and this
         * class only turns a gross amount typed in the price rows into net —
         * a cart taxed at another rate then pays another gross than typed:
         * less for reduced-rate goods against a standard-rate class (C22).
         */
        'taxClass' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_tax_class.title',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        /*
         * Where this method delivers (E-5, V3). Empty: every delivery country
         * of the shop. No zones of their own — a second country table next to
         * the shop's whitelist would drift from it; different prices for EU
         * and elsewhere are two methods.
         */
        'countries' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        // Per piece (E-5, V4), 0 for no limit; decimal(10,4) like the product.
        'maxLength' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'maxlength' => 12, 'tl_class' => 'w33 clr'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'maxGirth' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'maxlength' => 12, 'tl_class' => 'w33'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'maxPieceWeight' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'maxlength' => 12, 'tl_class' => 'w33'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        // Virtual (jsonData), read by FlatRateShipping::capabilities().
        'cashOnDelivery' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12'],
        ],
        'published' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'toggle' => true,
            'filter' => true,
            'eval' => ['doNotCopy' => true, 'tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
    ],
];

unset($conshoShippingPalette);
