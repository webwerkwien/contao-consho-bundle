<?php declare(strict_types=1);

/*
 * What a customer may use, from one order line (F-4; F1, F7). Written by the
 * release (DigitalRelease), never created by hand: an entitlement without a
 * reported delivery would hand out what was never delivered. The operator
 * may change the end date and revoke one (F10) — both leave the order alone.
 */
$GLOBALS['TL_DCA']['tl_consho_entitlement'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'ctable' => ['tl_consho_download'],
        'enableVersioning' => true,
        'closed' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index', 'orderItem' => 'index', 'member' => 'index', 'subscription' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['validFrom', 'id'],
            'panelLayout' => 'limit',
            'headerFields' => ['orderNumber', 'lastname'],
        ],
        'label' => ['fields' => ['product', 'quantity', 'validUntil'], 'format' => '%s × %s — %s'],
        'operations' => ['edit', '!show', '!versions'],
    ],

    'palettes' => ['default' => '{entitlement_legend},orderItem,product,quantity,deliveryType,subscription;{validity_legend},validFrom,validUntil,revoked'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => ['foreignKey' => 'tl_consho_order.orderNumber', 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0], 'relation' => ['type' => 'belongsTo', 'load' => 'lazy']],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'orderItem' => [
            'foreignKey' => 'tl_consho_order_item.productName',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // The customer's account at the purchase, 0 for a guest. Frozen:
        // "Meine Bestellungen" finds it through the order anyway.
        'member' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Allowed to dangle, like the order line's product: a deleted
        // article must not take the customer's entitlement with it.
        'product' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'quantity' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'deliveryType' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'validFrom' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // NULL: permanent (F1). What the end stops is the product's business.
        'validUntil' => [
            'inputType' => 'text',
            'eval' => ['rgxp' => 'datim', 'datepicker' => true, 'nullIfEmpty' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        'revoked' => [
            'inputType' => 'checkbox',
            'eval' => ['tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        /*
         * Abo-5 (P33): since when the entitlement rests — an invoice of its
         * subscription is past its term plus grace. NULL: not resting. Set
         * and cleared by SubscriptionDelinquency alone, from the orders, never
         * by hand (P39); apart from `revoked`, which is the operator's.
         *
         * Not in the palette: a read-only datim field goes back
         * into the row on every save, without its seconds. The list label
         * says "ruht seit …" (EntitlementListener).
         */
        'suspendedAt' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // Abo-3 (P21): the subscription of the line, 0 for a one-time
        // purchase. Its entitlement ends with the paid period, and Abo-4
        // extends it with every follow-up order.
        'subscription' => [
            'foreignKey' => 'tl_consho_subscription.subscriptionNumber',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
