<?php declare(strict_types=1);

/*
 * Custom field definitions.
 *
 * Freely typed product data that is deliberately NOT filterable. Values live
 * as JSON on the product, encoded by CustomFieldCodec — no value table,
 * because there is nothing to normalise for.
 *
 * Anyone wanting to filter creates a property instead. The editor never has to
 * make that choice as a data-structure question; the property mask asks
 * "should customers be able to filter by this?" and that settles it.
 */
$GLOBALS['TL_DCA']['tl_consho_custom_field'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // The title's translations go along into the undo bin (ZW-3).
        'ctable' => ['tl_consho_translation'],
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'alias' => 'unique',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            // Tie-break: titles are free text, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['title', 'id'],
            'flag' => 1,
            'panelLayout' => 'search,limit',

            // Names the record in the breadcrumb; see tl_consho_product.
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title', 'type'],
            'format' => '%s <span class="tl_gray">[%s]</span>',
        ],
        'operations' => [
            'edit',
            '!copy',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        'default' => '{title_legend},title,alias,type',
    ],

    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'alias' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'alnum', 'unique' => true, 'maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'type' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => ['text', 'number', 'date', 'boolean'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_custom_field']['types'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'text'],
        ],
    ],
];
