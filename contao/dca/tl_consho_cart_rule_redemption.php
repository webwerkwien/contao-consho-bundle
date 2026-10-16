<?php declare(strict_types=1);

/*
 * One use of a cart rule by an order (block H, H-4) — what "Einlösungen
 * insgesamt und je Kunde" count (W5: a member by account, a guest by e-mail).
 *
 * Written at the purchase click, in the order's transaction, after the rule
 * row was locked: two customers taking the last use at the same moment get
 * one order and one refusal, not two orders. A cancellation gives the use
 * back (`cancelled`, H-5); the row stays, for the order's history.
 *
 * No back end module: written by the shop.
 */
$GLOBALS['TL_DCA']['tl_consho_cart_rule_redemption'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'rule,cancelled' => 'index',
                'orderId' => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'rule' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'orderId' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'member' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Lower case — an address is one address however it is typed.
        'email' => ['sql' => ['type' => 'string', 'length' => 255, 'default' => '']],
        'code' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        'cancelled' => ['sql' => ['type' => 'boolean', 'default' => false]],
    ],
];
