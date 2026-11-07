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
        /*
         * A column list like the complaints, not the parent view, which
         * Contao draws without columns; with the ptable it still shows only
         * this order's deliveries, and "Neue Lieferung" still creates one
         * under it. The order is named in the headline.
         */
        'sorting' => [
            'mode' => 1,
            // Tie-break: shippedAt has second resolution, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['shippedAt', 'id'],
            'panelLayout' => 'limit',
            'defaultSearchField' => 'trackingNumber',
        ],
        // Cells from DeliveryListener::deliveryLabel(); `kind` is virtual.
        'label' => ['fields' => ['kind', 'shippedAt', 'carrier', 'trackingNumber', 'notification'], 'showColumns' => true],
        'operations' => [
            // `primary` keeps the jump in the row — see tl_consho_product.
            // Contao's child-records icon in blue, as at a complaint.
            'items' => ['href' => 'table=tl_consho_delivery_item', 'icon' => 'bundles/contaoconsho/icons/items.svg', 'primary' => true],

            // D-1. POST like "Erneut senden": it mails the customer. Hidden
            // once reported (DeliveryListener).
            'report' => ['href' => 'key=reportDelivery', 'icon' => 'ok.svg', 'method' => 'POST', 'primary' => true],

            // dev#2: the parcel's delivery note, shown only where there is one
            // (DeliveryListener).
            'deliveryNote' => ['href' => 'key=deliveryNotePdf', 'icon' => 'theme_export.svg', 'primary' => true],

            // The customer's mail about the parcel once more, like "Bestätigung
            // erneut senden" at the order: POST, on a reported parcel only
            // (DeliveryListener).
            'resendNotification' => ['href' => 'key=resendDeliveryNotification', 'icon' => 'resend.svg', 'method' => 'POST', 'primary' => true],
            'edit',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    // The last two legends only while the delivery is not reported (DeliveryMaskListener).
    'palettes' => ['default' => '{delivery_legend},shippedAt,carrier,trackingNumber;{contents_legend},contents;{report_legend},notify'],

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
        // The complaint a replacement delivery belongs to (block Reklamation,
        // 3.3); 0 for every ordinary delivery.
        'complaint' => [
            'label' => &$GLOBALS['TL_LANG']['tl_consho_delivery']['complaint'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'shippedAt' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w25 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'carrier' => [
            'inputType' => 'text',
            'exclude' => true,
            // 25 / 25 / 50: the tracking number is the long one.
            'eval' => ['maxlength' => 64, 'tl_class' => 'w25'],
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
        'reportedAt' => [
            // rgxp: "Info" shows the moment, not the timestamp.
            'label' => &$GLOBALS['TL_LANG']['tl_consho_delivery']['reportedAt'],
            'eval' => ['rgxp' => 'datim'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        /*
         * When the customer's mail about this parcel left — at the report or
         * from "Benachrichtigung erneut senden". NULL on a reported delivery
         * means it failed (DeliveryNotSentIssueSource); 0 that none was
         * recorded: an electronic release, whose notice is the release mail,
         * or a delivery reported before the field (DeliveryNotifiedMigration).
         */
        'notifiedAt' => [
            'label' => &$GLOBALS['TL_LANG']['tl_consho_delivery']['notifiedAt'],
            'eval' => ['rgxp' => 'datim'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // The list's first column: an ordinary delivery or a replacement.
        'kind' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_delivery']['kind']],

        // The list's last column, drawn from reportedAt and notifiedAt
        // (DeliveryListener::notification()). Its own name, so that "Info"
        // keeps "Gemeldet am" for reportedAt.
        'notification' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_delivery']['notification']],

        // Set with reportedAt (D-1, A2): a reported delivery and its items
        // are read-only, and neither can be deleted — the mail about this
        // parcel is out.
        'lockedAt' => [
            'label' => &$GLOBALS['TL_LANG']['tl_consho_delivery']['lockedAt'],
            'eval' => ['rgxp' => 'datim'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        /*
         * No columns: what is in the parcel lives in tl_consho_delivery_item,
         * and "notify" is an action, not a state. Both are drawn by an
         * input_field callback and read on submit (DeliveryMaskListener) — no
         * inputType, so DC_Table neither validates nor saves them.
         */
        'contents' => ['exclude' => true],
        'notify' => ['exclude' => true],
    ],
];
