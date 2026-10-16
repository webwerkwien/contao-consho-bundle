<?php declare(strict_types=1);

use Contao\DC_Table;

/*
 * One line of a basket — the article and how much of it. NO prices: every
 * amount is calculated on every request (specification 5.1, 7.2), so a price
 * can never go stale in here.
 *
 * pid + product + subscriptionRule is unique: adding the same article in the
 * same plan twice adds up the quantity rather than making a second line; the
 * same article in another plan IS another line (Abo-2, P9). A renewal
 * (Abo-7b) is a line of its own as well: `renews` is in the key.
 */
$GLOBALS['TL_DCA']['tl_consho_cart_item'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_consho_cart',
        'notCreatable' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
                'pid,product,subscriptionRule,renews' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_cart'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // Deliberately no relation, like tl_consho_order_item.product: a
        // product can be deleted while a basket still points at it. The
        // purchase check answers "gone" the same way it answers "switched
        // off", and the basket page offers to remove the line.
        'product' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // decimal(10,4) exactly like tl_consho_order_item.quantity and
        // tl_consho_product.minPurchase: a basket line must be able to hold
        // what an order line will hold, no more and no less.
        'quantity' => ['sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000']],

        'addedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // The chosen plan (Abo-2, P9): 0 is the one-time sale, like
        // tl_consho_price.subscriptionRule. No relation for the same reason as
        // `product` — a rule may go while a basket names it, and the pricer
        // then answers "not sellable" (CartPricer).
        'subscriptionRule' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // "Lizenz verlängern" (Abo-7b, P67): the subscription this line
        // renews, 0 for every other line. Its quantity is the old one's and
        // cannot be changed; adding the article again never lands on it.
        'renews' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
