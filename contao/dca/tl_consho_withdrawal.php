<?php declare(strict_types=1);

/*
 * A withdrawal as the customer declared it (D-2, § 356a BGB, § 13a FAGG).
 *
 * The record is the proof of receipt: when it arrived (server clock), what it
 * said, and whether the confirmation went out. It is written by the order page
 * and by the withdrawal form, never typed in the back end.
 *
 * ⚠️ Never editable in the mask: notEditable, notDeletable, notCopyable,
 * notSortable and closed, like the frozen legal texts. The handling (D-3:
 * accept, reject, return, refund) happens on a page of its own
 * (WithdrawalBackendController) and writes fields of its own — the
 * declaration itself stays what arrived. Not deletable ever: the
 * confirmation on a durable medium refers to it.
 *
 * `number` is derived from the order number (decision E3, 2026-09-27): a
 * missing number range must never make a withdrawal fail.
 *
 * ── Not assigned (WA, 2026-10-22) ─────────────────────────────────────────
 *
 * `pid` 0: a declaration from the form whose order number and e-mail matched
 * no order. It is stored anyway (§ 356a Abs. 2 BGB: the consumer gives
 * "Angaben zur Identifizierung des Vertrags", not a key the shop insists on)
 * and assigned by the operator later. `shop` says which shop it came in
 * through, `number` is "W-00042" and stays after the assignment — the
 * customer's confirmation names it.
 */
$GLOBALS['TL_DCA']['tl_consho_withdrawal'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'ctable' => ['tl_consho_withdrawal_item'],
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'notDeletable' => true,
        // Unique per ORDER, not globally: order numbers are unique per shop
        // only, and two shops may both have a WEB-00073.
        'sql' => ['keys' => ['id' => 'primary', 'pid,number' => 'unique', 'number' => 'index', 'shop' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['receivedAt', 'id'],
            'panelLayout' => 'limit',
            'headerFields' => ['orderNumber', 'lastname'],
            'defaultSearchField' => 'number',
        ],
        'label' => ['fields' => ['number', 'receivedAt', 'scope', 'state'], 'format' => '%s — %s — %s — %s'],
        'operations' => [
            // D-3: the operator's page — decide, return, refund.
            'process' => ['href' => 'key=processWithdrawal', 'icon' => 'edit.svg', 'primary' => true],
            'items' => ['href' => 'table=tl_consho_withdrawal_item', 'icon' => 'header.svg', 'primary' => true],
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
        ],
    ],

    'palettes' => ['default' => '{withdrawal_legend},number,receivedAt,afterWindow,source,name,orderReference,email,scope,part,message;{confirmation_legend},confirmationSentAt;{state_legend},state,decidedAt,reason,returnReceivedAt,refundShipping,valueCompensation,refundAmount,refundedAt'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            // Without it Contao shows pid through the FIRST list field of
            // tl_consho_order, which is `signals` — no column (ParentLabelIsAColumnTest).
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // The shop it came in through. Written for every new withdrawal; the
        // one way to place a declaration that has no order yet (pid 0).
        'shop' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * Virtual columns of the list "Widerrufe" (D-3, F12) — like the order
         * list's `signals` and `customer`: a label, no SQL, no inputType, so
         * they never appear in a palette. WithdrawalListListener fills them.
         */
        'signals' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_withdrawal']['signalsColumn']],
        'customer' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_withdrawal']['customerColumn']],
        'refundDue' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDueColumn']],
        'amount' => ['label' => &$GLOBALS['TL_LANG']['tl_consho_withdrawal']['amountColumn']],

        // "WEB-00073-W1" — the order number and a running count per order.
        'number' => [
            'inputType' => 'text',
            'search' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 80, 'default' => ''],
        ],

        // The moment of receipt, server clock — the one fact the law asks the
        // confirmation to state with date AND time.
        'receivedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        /*
         * WA, B1: received after "last shipment + period + grace". Stored and
         * confirmed like any other — the window counts from SHIPPING, the
         * period from RECEIPT, so the shop cannot know it was late; the
         * operator judges (D-3). A hint, never a refusal.
         */
        'afterWindow' => [
            'inputType' => 'checkbox',
            'eval' => ['disabled' => true, 'tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],

        // `orderPage` | `form` — through the token link or the withdrawal form.
        'source' => [
            'inputType' => 'select',
            'options' => ['orderPage', 'form'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_withdrawal']['sources'],
            // disabled, not readonly: a browser ignores readonly on a select or checkbox.
            'eval' => ['disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],

        'name' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        // WA: the order number as the consumer typed it on the form — their
        // "Angaben zur Identifizierung des Vertrags" (§ 356a Abs. 2 Nr. 2
        // BGB), kept even when it matched nothing.
        'orderReference' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 80, 'default' => ''],
        ],

        // Where the confirmation went. On the form it has to match the order
        // (W2); on the order page it is the order's address, confirmed.
        'email' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        // `all` | `items` (order page, positions in tl_consho_withdrawal_item)
        // | `part` (form, the part named in free text — decision E6).
        'scope' => [
            'inputType' => 'select',
            'options' => ['all', 'items', 'part'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scopes'],
            'eval' => ['disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 8, 'default' => 'all'],
        ],

        // Which part, in the customer's words (scope `part`).
        'part' => [
            'inputType' => 'textarea',
            'eval' => ['readonly' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],

        'message' => [
            'inputType' => 'textarea',
            'eval' => ['readonly' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],

        // NULL until the confirmation left. A withdrawal without it is one the
        // operator has to confirm by hand — the declaration stands either way.
        'confirmationSentAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // `received`, `accepted`, `rejected` (D-3, WithdrawalState). Changed
        // only by the actions on the page "Widerruf bearbeiten".
        'state' => [
            'inputType' => 'select',
            'options' => ['received', 'accepted', 'rejected'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_withdrawal']['states'],
            'eval' => ['disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'received'],
        ],

        /*
         * D-3. Everything below is the OPERATOR's side and written only by
         * WithdrawalProcessing — the declaration above stays what arrived.
         */
        'decidedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // Why it was rejected, in the operator's words — sent to the customer
        // verbatim (decision F6).
        'reason' => [
            'inputType' => 'textarea',
            'eval' => ['readonly' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],

        'returnReceivedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'date', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // The share of the delivery costs refunded with this withdrawal
        // (decision F1): proposed at acceptance, set by the operator.
        'refundShipping' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'notnull' => false],
        ],

        // The value compensation kept back for a service (Abo-8, P79):
        // proposed at acceptance (ValueCompensation), set by the operator
        // with the refund.
        'valueCompensation' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],

        // What was refunded, once booked — the negative payment and the
        // document that go with it (decision F4).
        'refundAmount' => [
            'inputType' => 'text',
            'eval' => ['conshoMoney' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'notnull' => false],
        ],
        'refundedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        'refundPayment' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'refundDocument' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
