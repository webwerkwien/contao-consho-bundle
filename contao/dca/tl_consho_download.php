<?php declare(strict_types=1);

/*
 * One row per download of a file entitlement (F-5, F18): when, nothing else.
 * No IP address and no user agent — the log answers "was it downloaded, and
 * when", which is what a dispute about a delivery needs, without holding
 * personal data of its own. No back end module: read through the
 * entitlement, deleted with the order's data.
 */
$GLOBALS['TL_DCA']['tl_consho_download'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_entitlement',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index']],
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'downloadedAt' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
