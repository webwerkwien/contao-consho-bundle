<?php declare(strict_types=1);

/*
 * The price a guest was asked, over time (block H, H-2).
 *
 * § 9a PrAG and § 11 PAngV: whoever announces a price reduction names the
 * lowest price of the 30 days before it began. That price is in no price row
 * any more once the list price changed — only a history knows it.
 *
 * One entry per article, market and plan whenever the projected guest price
 * changes: written by ListPriceProjector, which already works that price out
 * on every save, at every validity boundary (ListPriceBoundaryCron) and on a
 * rebuild. `validFrom` is when it began to be asked; it is asked until the
 * next entry. `promotion` marks a promotion price, so the start of an
 * uninterrupted run of them is the start of the reduction.
 *
 * No back end module: it is written by the shop, never by hand — a corrected
 * history would be a made-up one.
 */
$GLOBALS['TL_DCA']['tl_consho_price_history'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'article,currency,country,validFrom' => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'article' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'currency' => ['sql' => ['type' => 'string', 'length' => 3, 'default' => '']],
        'country' => ['sql' => ['type' => 'string', 'length' => 2, 'default' => '']],
        'subscriptionRule' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'net' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        'gross' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        'promotion' => ['sql' => ['type' => 'boolean', 'default' => false]],
        'validFrom' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
