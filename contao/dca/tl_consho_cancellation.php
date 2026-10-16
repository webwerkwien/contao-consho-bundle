<?php declare(strict_types=1);

/*
 * A cancellation as the customer declared it (Abo-7; A7, § 312k BGB,
 * P53–P65).
 *
 * The record is the proof of receipt, like a withdrawal (tl_consho_withdrawal):
 * when it arrived (server clock), what it said, and whether the confirmation
 * went out. Written by "Verträge hier kündigen" and by "Meine Abos", never
 * typed in the back end.
 *
 * The declaration is read-only. The one thing the operator does here is decide
 * an EXTRAORDINARY cancellation (P57): accept with a date or reject with a
 * reason. Those three fields are editable while it waits, and only then
 * (CancellationListener); CancellationDecision applies and mails it on submit.
 *
 * `number` is derived from the subscription number (P59): no number range
 * that could be missing.
 */
$GLOBALS['TL_DCA']['tl_consho_cancellation'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_subscription',
        'closed' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'notDeletable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid,number' => 'unique', 'number' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['receivedAt', 'id'],
            'panelLayout' => 'limit',
            'headerFields' => ['subscriptionNumber', 'state', 'endsAt'],
            'defaultSearchField' => 'number',
        ],
        'label' => ['fields' => ['number', 'receivedAt', 'kind', 'state'], 'format' => '%s — %s — %s — %s'],
        'operations' => [
            'edit',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],

            // E10. POST like resendConfirmation at the order; shown only for a
            // decision whose mail did not leave (DecisionResendButtonListener).
            'resendDecision' => ['href' => 'key=resendDecision', 'icon' => 'resend.svg', 'method' => 'POST', 'primary' => true],
        ],
    ],

    'palettes' => ['default' => '{cancellation_legend},number,receivedAt,source,kind,reason,name,email,requestedAt;{effect_legend},effectiveAt,earliestAt,earliestApplied,confirmationSentAt;{decision_legend},state,decidedAt,decisionSentAt,rejectReason'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'foreignKey' => 'tl_consho_subscription.subscriptionNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_subscription'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        // "WEB-00084-1-K1" — the subscription number and a count (P59).
        'number' => [
            'inputType' => 'text',
            'search' => true,
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 80, 'default' => ''],
        ],

        // When "jetzt kündigen" was pressed, server clock — receipt is presumed
        // right after (§ 312k Abs. 4 Satz 2).
        'receivedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        // `form` ("Verträge hier kündigen") | `account` ("Meine Abos").
        'source' => [
            'inputType' => 'select',
            'options' => ['form', 'account'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_cancellation']['sources'],
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],

        // `ordinary` | `extraordinary` (§ 312k Abs. 2 Nr. 1 a).
        'kind' => [
            'inputType' => 'select',
            'options' => ['ordinary', 'extraordinary'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_cancellation']['kinds'],
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'ordinary'],
        ],

        // The customer's reason for an extraordinary cancellation.
        'reason' => [
            'inputType' => 'textarea',
            'eval' => ['readonly' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],

        'name' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        // Where the confirmation went — the subscription's address, never a
        // typed one (P58).
        'email' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],

        // The date the customer asked for; NULL = the earliest possible
        // (§ 312k Abs. 5).
        'requestedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // When the contract ends by it: worked out for an ordinary one (P54),
        // the operator's date for an accepted extraordinary one (P57).
        'effectiveAt' => [
            'inputType' => 'text',
            'eval' => ['rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // The earliest possible end of an ordinary cancellation (P54). Later than
        // this, periods beginning before the end are billed in full (review S1).
        'earliestAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // The requested date was earlier than possible; the confirmation says so (P54).
        'earliestApplied' => [
            'inputType' => 'checkbox',
            'eval' => ['readonly' => true, 'tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],

        'confirmationSentAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // `effective` (ordinary, worked out) | `received` (extraordinary,
        // waiting) | `accepted` | `rejected` (the operator's decision, P57).
        'state' => [
            'inputType' => 'select',
            'options' => ['effective', 'received', 'accepted', 'rejected'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_cancellation']['states'],
            'eval' => ['tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'received'],
        ],

        'decidedAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // When the decision mail left (E10), like confirmationSentAt: NULL
        // after a decision is the finding DecisionNotSent with "Entscheidung
        // erneut senden" beside it; 0 is a decision from before the field
        // (DecisionSentMigration) — nothing recorded either way.
        'decisionSentAt' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'rgxp' => 'datim', 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        // Why an extraordinary cancellation was rejected — goes to the customer.
        'rejectReason' => [
            'inputType' => 'textarea',
            'eval' => ['tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
