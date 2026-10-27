<?php declare(strict_types=1);

/*
 * A document is a snapshot, not a view of the order.
 *
 * `payload` holds everything the document showed at the moment it was issued —
 * addresses, item names, prices, tax rates, totals. If the product is renamed
 * tomorrow, or the customer moves, or a tax rate changes, this document stays
 * as it was. That is the whole reason the order freezes its values in the
 * first place, and the document freezes them once more.
 *
 * Document numbers are unbroken per shop and type. Order numbers may have
 * gaps; these must not.
 */
$GLOBALS['TL_DCA']['tl_consho_document'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        /*
         * All four locks are needed, not three.
         *
         * notEditable, notDeletable and notCopyable take the row operations
         * away — but "Mehrere bearbeiten" still offered **Verschieben**, and
         * moving a document means hanging it off a DIFFERENT order. Which
         * order a document belongs to is part of what it states; a document
         * whose affiliation can be changed afterwards is not a document.
         *
         * With three of them (A-7), the row buttons were
         * correctly reduced to "Details" while act=select still let one move
         * an invoice. notSortable lives in `config`, not in `list.sorting`.
         */
        'notEditable' => true,
        'notDeletable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
                'shop,type,documentNumber' => 'unique',
                'pid,type' => 'index',
                // dev#2: the delivery list asks per row (DeliveryListener).
                'delivery' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => 4,

            // Tie-break: issuedAt has second resolution, so the order would
            // otherwise be whatever MySQL finds cheapest. Two documents issued
            // in the same second are ordinary — an invoice and its delivery
            // note come from one and the same shipment event.
            'fields' => ['issuedAt DESC', 'id DESC'],

            'panelLayout' => 'filter;limit',
            'headerFields' => ['orderNumber'],

            // Names the record in the breadcrumb. Without it the trail reads
            // "Beleg ID 4 bearbeiten" — see the note on defaultSearchField.
            'defaultSearchField' => 'documentNumber',
        ],
        'label' => ['fields' => ['documentNumber', 'type', 'issuedAt'], 'format' => '%s — %s — %s'],
        'operations' => [
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            // C-8: the invoice itself. The key actions are registered on the
            // consho_order module (config.php).
            'invoicePdf' => ['href' => 'key=invoicePdf', 'icon' => 'theme_export.svg', 'primary' => true],
            'invoiceHtml' => ['href' => 'key=invoiceHtml', 'icon' => 'preview.svg', 'primary' => true],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            // Without it Contao shows pid through the FIRST list field of
            // tl_consho_order, which is `signals` — no column, so "Info" was
            // a 500 (ParentLabelIsAColumnTest).
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'shop' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'type' => [
            'filter' => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_document']['types'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => 'invoice'],
        ],
        /*
         * NULL where Consho drew no number (block R, R16): a shop whose
         * invoices are issued elsewhere still keeps the invoice data, and an
         * issuing module's number arrives after the commit. NULL rather than
         * '', because the unique key shop,type,documentNumber admits any
         * number of NULLs but only one ''.
         */
        'documentNumber' => [
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 64, 'notnull' => false, 'default' => null],
        ],
        /*
         * Who issued it (R15, R20): 'consho', 'off' (the data only, issued
         * outside Consho) or the name of the invoicing module. A cancellation
         * or credit note follows its invoice, whatever the shop says today.
         */
        'issuer' => [
            'filter' => true,
            'sql' => ['type' => 'string', 'length' => 64, 'default' => 'consho'],
        ],
        // The module's id for the document, and where the handover stands:
        // '' (nothing to hand over), pending, submitting, issued or failed
        // (R18, ExternalInvoicing).
        'externalId' => [
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
        'externalState' => [
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],

        /*
         * `default 0` is safe HERE, unlike the date fields in A-3 and A-5: the
         * table is notEditable, so no form ever renders this value, offers
         * 01.01.1970 and submits it back as -3600. DocumentIssuer sets it on
         * insert and nothing ever touches it again.
         */
        'issuedAt' => [
            'sorting' => true,
            'flag' => 8,
            'eval' => ['rgxp' => 'datim'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // dev#2: the parcel a delivery note belongs to — one per delivery, 0 on
        // every other document.
        'delivery' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'relatedDocument' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'payload' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],

        /*
         * The archived PDF (8.3), when the shop keeps one.
         *
         * `archiveState` is '' when the shop did not archive at the time of
         * issue — a document issued before the option was switched on is not
         * a failed archive, and must not warn forever. `failed` is what
         * DbalInvoiceArchiveIssueSource counts.
         *
         * The path is relative to the upload path (files/), the way the
         * virtual file system addresses it. The hash is SHA-256 over the
         * bytes, not tl_files' MD5: it is the proof the delivered file is the
         * one written at issue, and that deserves a hash nobody can collide.
         */
        'archiveState' => [
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'archivedPath' => [
            'sql' => ['type' => 'string', 'length' => 1022, 'default' => ''],
        ],
        'archivedHash' => [
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
    ],
];
