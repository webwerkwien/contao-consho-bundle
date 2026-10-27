<?php declare(strict_types=1);

/*
 * Delivery RECORDS. Partial delivery falls out of this — the very thing that
 * is missing from Isotope entirely and awkward in the other systems.
 *
 * One delivery can carry part of several items; which quantities travelled is
 * in tl_consho_delivery_item, not here.
 */
$GLOBALS['TL_DCA']['tl_consho_delivery'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'ctable' => ['tl_consho_delivery_item'],
        'enableVersioning' => true,

        // A copied delivery would carry reportedAt along: a parcel counted
        // twice, locked and undeletable (D-1).
        'notCopyable' => true,
        'doNotCopyRecords' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            // Tie-break: shippedAt has second resolution, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['shippedAt', 'id'],
            'panelLayout' => 'limit',
            'headerFields' => ['orderNumber', 'lastname'],
            'defaultSearchField' => 'trackingNumber',
        ],
        'label' => ['fields' => ['shippedAt', 'trackingNumber'], 'format' => '%s — %s'],
        'operations' => [
            // `primary` keeps the jump in the row — see tl_consho_product.
            'items' => ['href' => 'table=tl_consho_delivery_item', 'icon' => 'header.svg', 'primary' => true],

            // D-1. POST like "Erneut senden": it mails the customer. Hidden
            // once reported (DeliveryListener).
            'report' => ['href' => 'key=reportDelivery', 'icon' => 'ok.svg', 'method' => 'POST', 'primary' => true],

            // dev#2: the parcel's delivery note, shown only where there is one
            // (DeliveryListener).
            'deliveryNote' => ['href' => 'key=deliveryNotePdf', 'icon' => 'theme_export.svg', 'primary' => true],
            'edit',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => ['default' => '{delivery_legend},shippedAt,carrier,trackingNumber'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            // Without it Contao shows pid through the FIRST list field of
            // tl_consho_order, which is `signals` — no column, so "Info" was
            // a 500 (ParentLabelIsAColumnTest).
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'shippedAt' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'carrier' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'trackingNumber' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],

        /*
         * When the operator pressed "Als versandt melden" (D-1). NULL until
         * then: a delivery being filled in has left nothing, and only reported
         * deliveries count in the balance (OrderBalanceReader). Not in the
         * palette — written by the action, never typed.
         */
        'reportedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        // Set with reportedAt (D-1, A2): a reported delivery and its items
        // are read-only, and neither can be deleted — the mail about this
        // parcel is out.
        'lockedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],
    ],
];
