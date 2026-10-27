<?php declare(strict_types=1);

/*
 * All prices, normalised. One row per product, customer group, currency and
 * quantity threshold. An empty customerGroup means "everyone".
 *
 * Only net is stored (C22). The mask takes an amount "gross | net" and turns it
 * into net on save, with the rate of the product's tax class in the reference
 * country; on load it shows the amount from net again — see
 * PriceInputListener. Block A, section 3.1, and
 * Block C, section 5.3.
 */
$GLOBALS['TL_DCA']['tl_consho_price'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_product',
        'enableVersioning' => true,

        // A price belongs to its article; moving it would leave the old
        // article's list price stale, see PriceRefreshTriggerListener.
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
                'pid,subscriptionRule,currency,customerGroup,minQuantity' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            // Tie-break: same currency and quantity, different validity, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            // The rule second, not first (Abo-1): the parent view groups by the
            // first field, and a group header of the rule would ask its options
            // with the product's id. The row names its rule instead (label).
            'fields' => ['currency', 'subscriptionRule', 'minQuantity', 'id'],
            'panelLayout' => 'search,limit',
            'headerFields' => ['title'],

            // Names the record in the breadcrumb; see tl_consho_product.
            'defaultSearchField' => 'currency',
        ],
        'label' => ['fields' => ['currency', 'net', 'minQuantity'], 'format' => '%s %s netto <span class="tl_gray">ab %s</span>'],
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
        'default' => '{price_legend},subscriptionRule,currency,inputCountry,inputMode,amount;{scope_legend},customerGroup,minQuantity;{validity_legend},validFrom,validTo',
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_product'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'currency' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'search' => true,
            // Options: the shops' currencies (PriceInputListener). No
            // submitOnChange: an auto-submit stores the row, so a saved price
            // would go live in the new currency with the old net before anyone
            // clicks Save. The country is checked against
            // the currency on save. A stored currency outside the options would
            // be offered as "(unknown)" and accepted — saveCurrency() rejects it.
            //
            // The column default is empty, not 'EUR' :
            // loud rather than silent, and more international. The mask has
            // preselected the right currency since C-1b — CoupledPriceInputListener
            // overwrites this default per request — but that only reaches the mask.
            // A row written past it, by a model, the CLI or a migration, used to
            // become a euro price in a shop that may not sell in euro. Empty is
            // refused loudly instead.
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
        ],
        // Stored, never typed: PriceInputListener writes it from `amount`.
        'net' => [
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        // Every home country is offered: without an auto-submit the list cannot
        // follow a currency just chosen in the form. PriceInputListener checks
        // on save: a country that does not fit the currency is set to the only
        // home country, otherwise cleared — a message names the allowed
        // countries, but only for a gross amount; a net amount stores '' for it
        // silently. Hidden when no currency has more than one
        // (PriceInputListener::onLoad()).
        // Never give it a filter flag: the filter panel calls the options
        // callback with the parent product's id. In "edit multiple → override"
        // it stays selectable even when hidden.
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
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_price']['inputModes'],
            // New rows start as gross; rows from before C-1b get the column
            // default net and open with the net they were calculated with.
            // alwaysSave also makes every real save set tstamp (DC_Table::submit()
            // only does so for non-empty values): a duplicated single price has
            // tstamp 0, and saved unchanged it would otherwise stay invisible to
            // every price reader (tstamp > 0, Task 2b).
            'default' => 'gross',
            // consho-switch: the two radios drawn as one switch (backend.css).
            'eval' => ['mandatory' => true, 'alwaysSave' => true, 'tl_class' => 'w50 clr consho-switch'],
            'sql' => ['type' => 'string', 'length' => 5, 'default' => 'net'],
        ],
        /*
         * 🔴 'sql' => null, not a missing key. Without the key Contao's
         * VirtualFieldsMappingListener maps the field into a jsonData column
         * and stores it after all. The amount is shown from net on load.
         *
         * alwaysSave on all three input fields: DC_Table hands only changed
         * values to onbeforesubmit, and switching "gross | net" alone must
         * still reach the conversion with the amount.
         *
         * In "edit multiple → override" the amount has to be overridden
         * together with inputMode; PriceInputListener rejects it otherwise.
         */
        'amount' => [
            'inputType' => 'text',
            'exclude' => true,
            // No maxval/minval: maxval runs before Contao turns a decimal comma
            // into a dot, and a minval of 0 is skipped. PriceInputListener checks
            // sign and size of the resulting net.
            'eval' => ['mandatory' => true, 'rgxp' => 'digit', 'alwaysSave' => true, 'maxlength' => 16, 'tl_class' => 'w50'],
            'sql' => null,
        ],
        'customerGroup' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_member_group.name',
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'minQuantity' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '1.0000'],
        ],

        /*
         * 🔴 NULL-able, not `default 0` — the plan had the latter and A-3 paid
         * for it: the field renders as 01.01.1970 rather than empty, the back
         * end submits that date back, CET turns it into -3600, and the unsigned
         * column rejects it with SQLSTATE[22003]. NULL means "no boundary".
         *
         * Date AND time, and the same rule as Contao's start/stop: from
         * inclusive, to exclusive. With a date alone, "valid to 30.09." ended
         * at 00:00 that day and nobody could see that the last day was
         * missing; with the time shown the end is stated, not implied. See
         * DbalPriceRepository.
         */
        'validFrom' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        'validTo' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        /*
         * Which contract this price is for (Abo, A2): 0 is the one-time sale,
         * otherwise a tl_consho_subscription_rule. Every price query of the
         * catalogue and the cart reads 0 only — see DbalPriceRepository.
         * Options and the check on save: PriceSubscriptionRuleListener.
         *
         * isAssociative: the options [0 => Einmalkauf, 1 => …, 2 => …] happen
         * to be a list, and Contao then takes the LABELS as values
         * (Widget::getAttributesFromDca(), ArrayUtil::isAssoc()). The mask
         * sent "Monatsabo", and the price was saved as a one-time sale
         * (Abo-1).
         */
        'subscriptionRule' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['isAssociative' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
