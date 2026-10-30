<?php declare(strict_types=1);

/*
 * Payment methods, created once and selected per shop (C2). The code behind a
 * method is its handler (PaymentHandlerInterface); module settings are virtual
 * fields in jsonData with a palette per type (C3) — prepayment uses the same
 * way for its bank connection.
 *
 * No free fee field (C5): a charge for using a payment instrument is unlawful
 * in AT (§ 56 Abs 3 ZaDiG 2018) and for most instruments in DE. A handler may
 * report an amount with its origin; the mask offers none.
 *
 * A method that occurs in an order cannot be deleted (PaymentMethodDeleteVoter).
 * See Block C, sections 5.1 and 6.2. "select multiple"
 * → delete skips a method in use silently: DC_Table::deleteAll() catches the
 * AccessDeniedException per id and continues with the next one.
 */
$GLOBALS['TL_DCA']['tl_consho_payment_method'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ctable' => ['tl_consho_translation'],
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
            'fields' => ['title', 'type'],
            'format' => '%s <span class="tl_gray">[%s]</span>',
        ],
        'operations' => [
            'edit',
            '!copy',
            // Core default operation (name-only, not an array): DefaultOperationsListener
            // attaches the DeleteAction permission check to it, so MethodInUseVoter greys
            // out the button for a method used in an order. An array operation here would
            // be copied unchanged and skip that check.
            //
            // The '!' sets `primary` and keeps it in the row — see tl_consho_shipping_method
            // for why a greyed-out button in the ⋯ menu would be no button at all.
            '!delete',
            'toggle',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        '__selector__' => ['type'],
        // A module's type falls back to this palette, and a module may charge
        // a fee through adjustment() — so the fee's tax is chosen here (E-1, Z3).
        'default' => '{title_legend},title,type,description,instructions;{fee_legend},feeTaxMode,feeTaxClass;{publish_legend},published',
        'prepayment' => '{title_legend},title,type,description,instructions;{bank_legend},accountHolder,iban,bic,bankName,giroCode;{publish_legend},published',
        // Nachnahme (E-3, Z2): the one core method with a fee (Z4), and so the
        // one core palette with the fee's tax (Z3).
        'cod' => '{title_legend},title,type,description,instructions;{cod_legend},fee,feeCurrency;{fee_legend},feeTaxMode,feeTaxClass;{publish_legend},published',
        // Auf Rechnung (E-2, Z1): no fee section on purpose (Z4).
        'invoice' => '{title_legend},title,type,description,instructions;{invoice_legend},dueDays,graceDays,allowedGroups,maxNet,maxNetCurrency,sameCountry,notHandedOutAtOnce;{bank_legend},accountHolder,iban,bic,bankName,giroCode;{publish_legend},published',
        // The test provider (C-11), offered only with CONSHO_TEST_PAYMENT=1.
        'test' => '{title_legend},title,type,description,instructions;{test_legend},testSecret,testFollowUpResult;{publish_legend},published',
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
        // Module settings (C3), declared explicitly for symmetry with
        // tl_consho_shipping_method and for the day the bank fields move into
        // a module of their own — they already target this column today.
        'jsonData' => [
            'sql' => ['type' => 'json', 'length' => 16777215, 'notnull' => false],
        ],
        // Options: the installed handlers (MethodTypeOptionsListener).
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
        // The payment notice after the order (prepayment: "transfer within 7
        // days"). A column, not a module setting: it is translated, and every
        // handler gets it under the reserved config key `instructions`.
        'instructions' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'eval' => ['rte' => 'tinyMCE', 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],

        /*
         * Prepayment settings — virtual fields (C3). No `sql`: Contao maps
         * them into jsonData. The same for every language, so not translated.
         * After a type switch, the old module's values stay in jsonData until
         * the method is deleted; a handler reads only its own keys.
         */
        // 70: the most a SEPA credit transfer carries for the beneficiary name
        // — EPC132-08 SCT C2PSP IG 2025 V1.0, element 2.117 Creditor/Name,
        // AT-E001, "SEPA Length 1 .. 70" (ISO 20022 would allow 140). The EPC
        // QR code has the same limit and marks the name as fixed (EPC069-12
        // v3.1, 2.2): refused here, never cut there.
        //
        // Since 9 October 2025 the payer's bank also compares this name with
        // the IBAN before every transfer in euro (verification of payee, Art. 5c
        // Regulation 260/2012 as amended by 2024/886). A name that differs from
        // the one the bank keeps shows the customer a warning — hence the help
        // text. Consho cannot check it: the query runs between the banks.
        'accountHolder' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 70, 'tl_class' => 'w50'],
        ],
        // targetColumn by hand: IbanListener adds a save_callback, and a field
        // with one is not mapped automatically (VirtualFieldsMappingListener).
        'iban' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 42, 'tl_class' => 'w50'],
            'targetColumn' => 'jsonData',
        ],
        'bic' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 11, 'tl_class' => 'w50'],
        ],
        'bankName' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
        ],
        // The Giro-Code on the invoice (C-8), switchable.
        // Part of the bank section, not of prepayment: any method with a bank
        // connection — a later purchase on account — takes it over with the
        // section. Frozen into the invoice as the finished payload (GiroCode).
        'giroCode' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'clr w50 m12 consho-toggle'],
        ],

        /*
         * Auf Rechnung (E-2, Z1) — virtual fields like the bank connection.
         * Read by InvoicePaymentHandler; `dueDays` also by the document
         * source for the date on the invoice.
         */
        // No `default`: Contao sets the default of EVERY field on create, of a
        // virtual one from another palette too — cash on delivery carried
        // `dueDays: 14` (E-7). Empty means the handler's 14 days
        // (InvoicePaymentHandler::DEFAULT_DUE_DAYS), shown as the placeholder.
        'dueDays' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'placeholder' => '14', 'tl_class' => 'w50'],
        ],
        'allowedGroups' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'foreignKey' => 'tl_member_group.name',
            'eval' => ['multiple' => true, 'tl_class' => 'clr'],
        ],
        'maxNet' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'maxlength' => 20, 'tl_class' => 'w50'],
        ],
        // One limit in one currency; in any other the method is not offered.
        'maxNetCurrency' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'alpha', 'minlength' => 3, 'maxlength' => 3, 'tl_class' => 'w50'],
        ],
        'sameCountry' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'clr w50 m12 consho-toggle'],
        ],
        // Nachlese E5b: not offered for digital content or vouchers.
        'notHandedOutAtOnce' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
        ],
        // Nachlese E5a: days after the term until the digital goods of an
        // unpaid one-time purchase rest. No `default`, like dueDays: empty
        // means InvoicePaymentHandler::DEFAULT_GRACE_DAYS, the placeholder.
        'graceDays' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'placeholder' => '14', 'tl_class' => 'w50'],
        ],

        /*
         * Nachnahme (E-3) — virtual fields. The fee is NET and in ONE
         * currency: in any other the method is not offered (a fee that cannot
         * be charged is not free delivery). Only here, never at every method
         * (Z4, § 56 Abs 3 ZaDiG 2018 — the help text quotes it, without a
         * verdict). Its tax is chosen below in feeTaxMode (Z3).
         */
        'fee' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'maxlength' => 20, 'tl_class' => 'w50'],
        ],
        'feeCurrency' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'alpha', 'minlength' => 3, 'maxlength' => 3, 'tl_class' => 'w50'],
        ],

        // The key that signs the test provider's messages (C-11) — virtual,
        // like the bank connection. Plain text in jsonData and in the
        // versions: it guards a provider that moves no money and is never
        // offered on a real shop (TestPaymentHandler). Still not shown where
        // it is only read: hideInput keeps it out of the detail view, the
        // version diff and contao-ai's read commands (core v1.8.1+).
        'testSecret' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'minlength' => 16, 'maxlength' => 255, 'hideInput' => true, 'tl_class' => 'w50'],
        ],
        // What a charge of a follow-up order answers (Abo-4b, P100) — a
        // test sets it before the cron runs. Virtual, like the secret.
        'testFollowUpResult' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => ['paid', 'pending', 'failed'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_payment_method']['testFollowUpResults'],
            'eval' => ['tl_class' => 'w50'],
        ],

        /*
         * How a FEE of this method is taxed (E-1, Z3). The operator's choice,
         * not Consho's: as an ancillary supply the fee follows the goods, as a
         * supply of its own it has one rate — both readings exist, neither is
         * settled in a primary text (Block E, 3).
         * No default on purpose: a preset would read as advice. Empty means
         * not chosen, and a fee without a choice is not offered at checkout.
         */
        'feeTaxMode' => [
            'inputType' => 'radio',
            'exclude' => true,
            'options' => ['proportional', 'fixed'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_payment_method']['feeTaxModes'],
            // consho-switch: as in tl_consho_price; with nothing chosen yet, no half is lit.
            'eval' => ['tl_class' => 'w50 consho-switch'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        // Used by `fixed` only; `proportional` takes the rates of the goods.
        'feeTaxClass' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_tax_class.title',
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],

        'published' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'toggle' => true,
            'filter' => true,
            'eval' => ['doNotCopy' => true, 'tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
    ],
];
