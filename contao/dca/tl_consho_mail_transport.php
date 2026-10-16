<?php declare(strict_types=1);

/*
 * Mail configurations, editable in the back end.
 *
 * Contao defines transports in config/config.yaml and the back end only lets
 * you pick from what is already there. For a shop operator wanting to send from
 * their own mailbox that is a wall. Shopware has SMTP settings in its admin —
 * that is the yardstick Consho is measured against, not Contao.
 *
 * `native://` is deliberately absent. Symfony itself advises against it: with
 * `sendmail -t` there is no error reporting at all, and a transport whose test
 * function cannot check anything has no place in a shop.
 *
 * ── Why this is a table of its own and not a child of the shop ────────────
 *
 * A shop configuration POINTS AT one of these. Three things follow, and the
 * third is why it was rebuilt on 2026-09-09 rather than left as a child:
 *
 *  1. Reuse. Several shop configurations — an agency running several tenants
 *     from one mailbox — share a single configuration.
 *  2. It is unambiguous. As a child table nothing selected WHICH transport a
 *     shop used, so two of them left the answer undefined.
 *  3. One rule, not two. Anchoring it at the start page instead would have
 *     needed a fallback for everything without one: nightly runs, CLI, operator
 *     notices. Via the shop configuration every mail resolves the same way —
 *     and an order already stores its shop, so a mail resent a year later finds
 *     its sender without anything being frozen onto the order.
 *
 * If a shop ever needs a sender per language domain, the way in is ADDITIVE: an
 * optional field on the start page overriding the shop's choice. Adding a
 * special case to one rule is easy; removing a fallback chain is not.
 */
$GLOBALS['TL_DCA']['tl_consho_mail_transport'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'enableVersioning' => true,
        'sql' => ['keys' => ['id' => 'primary']],
    ],
    'list' => [
        'sorting' => [
            // Mode 1: a flat list of its own, no longer nested under a shop.
            'mode' => 1,

            // Tie-break: titles are free text, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['title', 'id'],

            'flag' => 1,
            'panelLayout' => 'search,limit',

            // Names the record in the breadcrumb; without it the trail reads
            // "Transport ID 2 bearbeiten".
            'defaultSearchField' => 'title',
        ],
        'label' => ['fields' => ['title', 'type'], 'format' => '%s <span class="tl_gray">[%s]</span>'],
        'operations' => [
            'edit',

            // Duplicating is useful now that these stand on their own: a second
            // configuration usually differs from the first in one field.
            '!copy',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],

            // C-9 (Michael, 2026-09-25: „Testmail-Funktion, wo man einen
            // Empfänger eintragen kann"). A link to a form page; the sending
            // itself is a POST from that page (MailTransportTestController).
            'testMail' => ['href' => 'key=testMail', 'icon' => 'resend.svg', 'primary' => true],
            '!versions',
        ],
    ],
    'palettes' => [
        '__selector__' => ['type'],
        'default' => '{title_legend},title,type;{sender_legend},senderAddress,senderName',
        'smtp' => '{title_legend},title,type;{server_legend},host,port,encryption,username,password;{sender_legend},senderAddress,senderName',
        'graph' => '{title_legend},title,type;{oauth_legend},tenantId,clientId,clientSecret;{sender_legend},senderAddress,senderName',
        'sendmail' => '{title_legend},title,type;{sender_legend},senderAddress,senderName',
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'type' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => ['smtp', 'graph', 'sendmail'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_mail_transport']['types'],
            'eval' => ['mandatory' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'smtp'],
        ],
        'host' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'port' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'tl_class' => 'w25'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 587],
        ],
        'encryption' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => ['tls', 'ssl', 'none'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_mail_transport']['encryptions'],
            'eval' => ['tl_class' => 'w25'],
            'sql' => ['type' => 'string', 'length' => 8, 'default' => 'tls'],
        ],
        'username' => [
            'inputType' => 'text',
            'exclude' => true,
            // Raw like the password (review 2026-09-25): o'brien@… is a legal
            // address and would otherwise be stored as o&#39;brien@….
            'eval' => ['maxlength' => 255, 'useRawRequestData' => true, 'tl_class' => 'clr w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        /*
         * 512 characters although no password is that long: the column holds
         * the ENCRYPTED value, and nonce plus authentication tag plus base64
         * make it considerably longer than the plaintext.
         *
         * Encryption happens in TransportSecretListener, not here — see the
         * note there on why the field also has a load_callback.
         *
         * 🔴 `useRawRequestData`, found at the acceptance of C-9 (2026-09-25):
         * without it the text widget reads through Input::post() in mode
         * encodeAll and turns `# < > ( ) \ = " '` into HTML entities BEFORE
         * the listener encrypts. A password containing a `#` or a `=` was
         * stored as something else and the server answered "535
         * authentication failed". A secret is not markup; it is taken raw.
         */
        'password' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['hideInput' => true, 'useRawRequestData' => true, 'maxlength' => 512, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 512, 'default' => ''],
        ],
        'tenantId' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
        'clientId' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
        'clientSecret' => [
            'inputType' => 'text',
            'exclude' => true,
            // Raw for the same reason as the password above.
            'eval' => ['hideInput' => true, 'useRawRequestData' => true, 'maxlength' => 512, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 512, 'default' => ''],
        ],
        'senderAddress' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'email', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'senderName' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
    ],
];
