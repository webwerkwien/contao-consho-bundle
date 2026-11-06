<?php declare(strict_types=1);

/*
 * A complaint about a defect (block Reklamation, 3.1): a child of the order
 * with a number, a state and the lines it concerns. The operator records it
 * in the back end, under the order.
 *
 * `number` is derived from the order number ("SHOP-00073-R1"), like a
 * withdrawal's: no number range, and a gap after a deletion is fine — it is
 * no document number. ComplaintListener sets it on creation.
 *
 * `state`, `remedy`, `decidedAt`, `rejectReason` and `resolvedAt` belong to
 * the handling of the complaint on the page "Reklamation entscheiden"
 * (ComplaintBackendController) and are in no palette: a state set in the
 * mask could close a decided rescission without its refund, or reopen a
 * refunded complaint.
 *
 * The same table is listed flat in the menu item "Reklamationen"
 * (ComplaintListListener).
 */
$GLOBALS['TL_DCA']['tl_consho_complaint'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'ctable' => ['tl_consho_complaint_item'],
        'enableVersioning' => true,
        'notCopyable' => true,
        'doNotCopyRecords' => true,
        // Unique per ORDER, not globally: order numbers are unique per shop only.
        'sql' => ['keys' => ['id' => 'primary', 'pid,number' => 'unique', 'state' => 'index']],
    ],

    'list' => [
        /*
         * A column list, not the parent view, which Contao draws without
         * columns; with the ptable it still shows only this order's
         * complaints, and "Neue Reklamation" still creates one under it. The
         * order is named in the headline.
         *
         * `defaultSearchField` also names the record in the headline
         * ("WEB-00117-R1" instead of "Reklamation ID 1").
         */
        'sorting' => [
            'mode' => 1,
            'fields' => ['receivedAt', 'id'],
            'panelLayout' => 'limit',
            'defaultSearchField' => 'number',
        ],
        // Cells from ComplaintListListener; `lines` is virtual.
        'label' => ['fields' => ['state', 'number', 'receivedAt', 'note', 'lines', 'resolvedAt'], 'showColumns' => true],
        'operations' => [
            // RK-4: the page "Reklamation entscheiden" — decide, return, refund.
            // The cracked glass of the "Reklamationen" button, so it is not
            // mistaken for "edit"; orange, for working on it.
            'process' => ['href' => 'key=processComplaint', 'icon' => 'bundles/contaoconsho/icons/complaint-process.svg', 'primary' => true],
            // Before "edit": the items are what a complaint is about.
            // Contao's child-records icon in blue, as at a withdrawal.
            'items' => ['href' => 'table=tl_consho_complaint_item', 'icon' => 'bundles/contaoconsho/icons/items.svg', 'primary' => true],
            'edit',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    // The three facts in one row (25/25/25), what it is about below in full width.
    'palettes' => ['default' => '{complaint_legend},number,receivedAt,note'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            // The heading of the order column in the menu item.
            'label' => &$GLOBALS['TL_LANG']['tl_consho_complaint']['pid'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * Virtual columns of the list "Reklamationen": a label, no SQL, no
         * inputType, so they never appear in a palette. ComplaintListListener
         * fills them.
         */
        'customer' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_complaint']['customerColumn']],
        'lines' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_complaint']['linesColumn']],

        // "SHOP-00073-R1" — the order number and a running count per order.
        'number' => [
            'inputType' => 'text',
            'search' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w25'],
            'sql' => ['type' => 'string', 'length' => 80, 'default' => ''],
        ],
        'receivedAt' => [
            'inputType' => 'text',
            'exclude' => true,
            // Today, as for a payment (D-1) — not 01.01.1970.
            'default' => time(),
            'eval' => ['mandatory' => true, 'rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w25 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'note' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'state' => [
            'filter' => true,
            'options' => ['open', 'inProgress', 'resolved', 'rejected'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_complaint']['states'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'open'],
        ],
        // Empty until the operator chooses how the defect is remedied.
        'remedy' => [
            'filter' => true,
            'options' => ['repair', 'replacement', 'reduction', 'rescission'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_complaint']['remedies'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'decidedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],
        // Goes to the customer verbatim with a rejection.
        'rejectReason' => ['sql' => ['type' => 'text', 'notnull' => false]],
        // Set when the complaint is closed, refunded or rejected.
        'resolvedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],

        /*
         * The refund of a rescission or price reduction (RK-4), named as at a
         * withdrawal. The customer's return postage is no part of it: the
         * operator settles it outside the shop.
         */
        // When the goods of the complaint came back (3.4).
        'returnReceivedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],
        'refundShipping' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        'refundAmount' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'notnull' => false]],
        'refundedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],
        'refundPayment' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'refundDocument' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
