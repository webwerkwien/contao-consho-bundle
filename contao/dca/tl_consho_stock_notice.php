<?php declare(strict_types=1);

/*
 * "Benachrichtigen, wenn wieder da" (Block G, G12, G15): a member's wish to
 * hear once when an article is available again.
 *
 * Only members. No address is stored: the mail goes to the
 * account's address at the time it is sent. `root` and `language` are where
 * the wish was made, for the link and the language of the mail.
 *
 * Deleted once the mail is out, with the member, and by the erasure (D-5);
 * listed in the data export. One wish per member and article.
 */
$GLOBALS['TL_DCA']['tl_consho_stock_notice'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'article,member' => 'unique', 'member' => 'index']],
    ],

    'list' => [
        'sorting' => ['mode' => 1, 'fields' => ['createdAt DESC']],
        'label' => ['fields' => ['article', 'member', 'createdAt']],
    ],

    'palettes' => ['default' => '{notice_legend},article,main,member,shop,root,language,createdAt'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'article' => [
            'foreignKey' => 'tl_consho_product.title',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'main' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'member' => [
            'foreignKey' => 'tl_member.lastname',
            'inputType' => 'select',
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'shop' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'root' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'language' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        'createdAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
