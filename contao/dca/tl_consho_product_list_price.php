<?php declare(strict_types=1);

/*
 * Projection, not cache.
 *
 * It exists for one purpose: so filtering and sorting by price run in SQL. It
 * is never read to calculate a price. The difference to a cache table is that
 * it can be rebuilt completely from the source data at any time and checked by
 * machine — see consho:price:rebuild and consho:price:verify.
 *
 * A column on tl_consho_product would not do: currency belongs to the shop, and
 * one product can be visible in several shops with different currencies.
 * See Block A, section 3.1.
 *
 * Both net and gross are projected, which is a deviation from the plan — it had
 * one `listPrice` column, and contradicted itself about which of the two it
 * held. The reason for two: from A-5 a customer with a valid VAT ID is billed
 * under reverse charge and sees net prices, while everyone else sees gross —
 * in the SAME shop. A projection carrying only one of them could filter for
 * only half the customers.
 *
 * `country` since C-1a (C25): gross depends on the home country, and two euro
 * shops in Austria and Germany show two different gross amounts for one net.
 * One row per article, currency and country; see ListPriceProjector.
 *
 * `subscriptionRule` since Abo-2b: the figure is what the product page puts up
 * top — the one-time price, or for an article not sold once its first plan,
 * per billing period (P88); 0 is the one-time sale. `alsoSubscription` marks a
 * one-time price whose article is also sold as a plan open to everyone (P89).
 *
 * `compareNet`/`compareGross` since block H (H-2): the price struck through
 * next to a promotion — the lowest price of the 30 days before it began, from
 * tl_consho_price_history (§ 9a PrAG, § 11 PAngV). NULL when the figure is no
 * promotion, or the promotion is not below that price (B). Worked out here,
 * once, so the tile and the product page cannot name two different ones.
 */
$GLOBALS['TL_DCA']['tl_consho_product_list_price'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,currency,country' => 'unique',

                // These two indexes are the entire purpose of the table.
                // Without them it is a second copy of the same data.
                'currency,country,gross' => 'index',
                'currency,country,net' => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_product'],
        ],
        'currency' => ['sql' => ['type' => 'string', 'length' => 3, 'default' => '']],
        'country' => ['sql' => ['type' => 'string', 'length' => 2, 'default' => '']],
        'net' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        'gross' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        'subscriptionRule' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'alsoSubscription' => ['sql' => ['type' => 'boolean', 'default' => false]],
        'compareNet' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'notnull' => false]],
        'compareGross' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'notnull' => false]],
    ],
];
