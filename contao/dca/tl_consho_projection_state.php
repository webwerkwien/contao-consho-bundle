<?php declare(strict_types=1);

/*
 * Durable markers of the projections — one row per name.
 *
 * `visibility_boundaries`: every start/stop up to this moment has been applied
 * to tl_consho_product_visibility (VisibilityBoundaryCron). Not in the Symfony
 * cache: every deploy empties var/cache/prod, and a lost marker means a full
 * rebuild. Not in tl_cron_job either: Contao's purgeOldRecords() removes rows
 * it does not know. See Block B, section 5.7.
 */
$GLOBALS['TL_DCA']['tl_consho_projection_state'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'name' => 'unique',
            ],
        ],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'name' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        'value' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
