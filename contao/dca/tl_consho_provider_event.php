<?php declare(strict_types=1);

/*
 * What a payment provider reported after a payment succeeded (spec 9):
 * chargebacks and refunds made at the provider rather than through Consho.
 *
 * Not editable in the back end. A row appears when the provider's
 * notification names an event Consho has not seen; the unique `reference`
 * (the provider's own ID) is what keeps a resent notification from adding it
 * twice. Matching is by that ID only, never by amount (N3).
 *
 * `state`: `open` until the operator resolves a foreign refund — `recorded`
 * (booked as a new refund row) or `assigned` (tied to an existing one). A
 * chargeback is booked as a payment row right away (ChargebackRow) and stays
 * `open` here; its finding closes once nothing is outstanding or somebody
 * takes note (P5).
 */
$GLOBALS['TL_DCA']['tl_consho_provider_event'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'reference' => 'unique',
                'pid' => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // The order.
        'pid' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // The payment row the event is about.
        'payment' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // chargeback | refund (ProviderEventKind)
        'kind' => ['sql' => ['type' => 'string', 'length' => 16, 'default' => '']],
        'reference' => ['sql' => ['type' => 'string', 'length' => 255, 'default' => '']],

        // Always positive; the direction follows from the kind.
        'amount' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        'currency' => ['sql' => ['type' => 'string', 'length' => 3, 'default' => '']],
        'reason' => ['sql' => ['type' => 'string', 'length' => 255, 'default' => '']],
        'occurredAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'reversedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        'state' => ['sql' => ['type' => 'string', 'length' => 16, 'default' => 'open']],

        // How a foreign refund was resolved: the refund row, when, by whom.
        'resolvedRow' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'resolvedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'resolvedBy' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
    ],
];
