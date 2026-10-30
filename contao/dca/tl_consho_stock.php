<?php declare(strict_types=1);

/*
 * The stock of an article (Block G, G1, G2, G13): one row per article with a
 * limited stock, over all shops.
 *
 * A table of its own rather than a column of tl_consho_product: Contao copies
 * a product with all its columns, and a copied stock would be goods nobody
 * has. For the same reason it is not a ctable of the product — Contao copies
 * ctable children too (the reasoning of tl_consho_key, F-6).
 *
 * Rows come and go with the article (StockRows, from the product mask's
 * callbacks), never by hand. A row whose article stops tracking stock, or is
 * deleted, stays with `active = 0`, so its movements keep their meaning and an
 * undo finds it again.
 *
 * `quantity` changes only through StockBooking: an atomic update plus a row in
 * tl_consho_stock_movement. Never written from a form (G3).
 *
 * The back end page "Lagerstand" (G-7): this list, with "Einkauf" and
 * "Einkaufslisten" above it (StockListListener, PurchaseBackendController).
 * `supplier` is a copy of the main product's, kept by StockRows — so the list
 * can be filtered by it (E5).
 */
$GLOBALS['TL_DCA']['tl_consho_stock'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ctable' => ['tl_consho_stock_movement'],
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'article' => 'unique', 'main' => 'index', 'supplier' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 2,
            'fields' => ['quantity', 'main', 'article'],
            'panelLayout' => 'filter;sort,search,limit',
            // Only what keeps stock now; a row switched off keeps its history.
            'filter' => [['active = ?', 1]],
        ],
        'label' => [
            // The columns StockListListener fills; headers from the language file.
            'fields' => ['article', 'skuColumn', 'quantity', 'orderedColumn', 'stateColumn', 'availableFrom', 'noticesColumn', 'supplier'],
            'showColumns' => true,
        ],
        // `primary`: in the header, not behind ⋯ — they are the module's work.
        'global_operations' => [
            'purchase' => ['href' => 'key=purchase', 'icon' => 'new.svg', 'primary' => true],
            'purchaseLists' => ['href' => 'table=tl_consho_purchase_list', 'icon' => 'folderC.svg', 'primary' => true],
        ],
        'operations' => [
            // To the article's mask, where stock is booked (G3).
            'product' => ['icon' => 'edit.svg', 'primary' => true],
            'movements' => ['href' => 'table=tl_consho_stock_movement', 'icon' => 'bundles/contaoconsho/icons/history.svg', 'primary' => true],
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
        ],
    ],

    'palettes' => ['default' => '{stock_legend},article,main,source,quantity,availableFrom,active'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Allowed to dangle once the article is deleted, like an order line's product.
        'article' => [
            'foreignKey' => 'tl_consho_product.title',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'main' => [
            'foreignKey' => 'tl_consho_product.title',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // `manual`: kept in stock. `keys`: the article's key pool (G10, G-5).
        'source' => [
            'inputType' => 'text',
            'filter' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'manual'],
        ],
        // Signed: selling without stock may take it below zero (G6).
        'quantity' => [
            'inputType' => 'text',
            'sorting' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        // "wieder lieferbar ab" (G12); NULL when not announced.
        'availableFrom' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'date', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
        // A copy of the main product's supplier (E5), for filter and search.
        'supplier' => [
            'inputType' => 'text',
            'filter' => true,
            'search' => true,
            'sorting' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        // Column headers only — filled by StockListListener, never stored.
        'skuColumn' => [],
        'orderedColumn' => [],
        'stateColumn' => [],
        'noticesColumn' => [],
        'active' => [
            'inputType' => 'checkbox',
            'eval' => ['disabled' => true, 'tl_class' => 'w50 m12 consho-toggle'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
    ],
];
