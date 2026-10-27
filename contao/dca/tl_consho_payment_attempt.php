<?php declare(strict_types=1);

/*
 * One try to pay an order (spec 5.1, table from C-7).
 *
 * **An attempt is no money.** tl_consho_payment from block A holds what
 * actually arrived; this table holds what was attempted. A customer who starts
 * a card payment three times and succeeds once leaves three attempts and one
 * payment, and conflating them would either lose the history or book the money
 * three times.
 *
 * The token is stored HASHED, for the same reason as tl_consho_order_token: the
 * provider's notification URL carries it, and a database dump must not hand
 * anyone the ability to confirm a payment.
 *
 * ⚠️ Deliberately absent from every back end module's `tables` and from the
 * order's `ctable`. Written by the core only, under lock (spec 7.4); a handler
 * gets a read-only copy as a PaymentAttempt. There is nothing here a person
 * should edit — an attempt that says `succeeded` because someone typed it is
 * worse than no record at all.
 */
$GLOBALS['TL_DCA']['tl_consho_payment_attempt'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'notDeletable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',

                /*
                 * Unique on the hash, like the order tokens: the notification
                 * route looks an attempt up BY its hash, and a duplicate would
                 * make one provider callback resolve to two attempts. The
                 * database refuses that rather than leaving it to the
                 * generator.
                 */
                'tokenHash' => 'unique',
                'pid,state' => 'index',
            ],
        ],
    ],
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

        // Reference plus copy, the pattern the order head follows for its
        // methods and addresses: the type decides which handler answers a
        // notification, and it has to keep working after the method row is
        // reconfigured.
        'method' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'methodType' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],

        'state' => ['sql' => ['type' => 'string', 'length' => 16, 'default' => 'open']],

        // What the browser return said, as the handler verified it (C-11).
        // Its own column, not `state`: a return moves no money, and a verified
        // "succeeded" there must not read as a payment anywhere. The order page
        // shows it; nothing else does. Empty: no return yet.
        'returnState' => ['sql' => ['type' => 'string', 'length' => 16, 'default' => '']],

        // What this attempt is for, frozen: an order whose payment method is
        // later changed (C-10) leaves its cancelled attempts stating the amount
        // they were meant to cover.
        'amount' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        'currency' => ['sql' => ['type' => 'string', 'length' => 3, 'default' => '']],

        // 64 characters is exactly a sha256 in hex — a shorter column would
        // truncate silently and every lookup would fail.
        'tokenHash' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],

        // Whatever the provider calls this transaction. Empty for prepayment,
        // which has no provider.
        'providerReference' => ['sql' => ['type' => 'string', 'length' => 255, 'default' => '']],

        'createdAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'updatedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // Opened by the subscription cron to charge a follow-up order without
        // the customer (Abo-4b, P94). A notification of such an attempt sends
        // "Rechnung zum Abo" in its charged version (P97), and its failure
        // puts the order "on account" (P95).
        'charge' => ['sql' => ['type' => 'boolean', 'default' => false]],
    ],
];
