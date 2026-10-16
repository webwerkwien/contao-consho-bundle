<?php declare(strict_types=1);

/*
 * The key pool of an article (F-6, F19): codes the operator pasted in, one
 * per piece sold. Filled through the article's field "Schlüssel einfügen"
 * (KeyPoolListener), never created one by one; a code handed out belongs to
 * its entitlement and can no longer be deleted.
 */
$GLOBALS['TL_DCA']['tl_consho_key'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_product',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid,entitlement' => 'index', 'entitlement' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['entitlement', 'id'],
            'panelLayout' => 'limit',
            'headerFields' => ['title', 'sku'],
        ],
        'label' => ['fields' => ['code']],
        'operations' => ['!delete', '!show'],
    ],

    'palettes' => ['default' => '{key_legend},code,entitlement,assignedAt'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => ['foreignKey' => 'tl_consho_product.title', 'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0], 'relation' => ['type' => 'belongsTo', 'load' => 'lazy']],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'code' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        // 0: still in the pool.
        'entitlement' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'assignedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],
    ],
];
