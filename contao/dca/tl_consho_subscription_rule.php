<?php declare(strict_types=1);

use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;

/*
 * Subscription rules (Abo, A1): how long a subscription runs, how often it is
 * paid and what happens at the end. Created once, like the shipping methods,
 * and chosen at the product. The AMOUNT is not here: it is a price row of the
 * product with this rule (A2), so currency, net/gross, tax, group price and
 * validity work as for every other price.
 *
 * SubscriptionRuleListener refuses what the rule must not say (P3–P5), and a
 * rule named by a price row cannot be deleted (SubscriptionRuleDeleteVoter).
 * docs/block-abo.md.
 */
$GLOBALS['TL_DCA']['tl_consho_subscription_rule'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            // Tie-break: titles are free text; id is autoincrement and never ties.
            'fields' => ['title', 'id'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title', 'renewal'],
            'format' => '%s <span class="tl_gray">[%s]</span>',
        ],
        'operations' => [
            'edit',
            '!copy',
            // Named, not an array: DefaultOperationsListener attaches the
            // DeleteAction check, so SubscriptionRuleDeleteVoter can grey it
            // out; `!` keeps it in the row — see tl_consho_shipping_method.
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        'default' => '{title_legend},title;{term_legend},termLength,termUnit,billingInterval;{renewal_legend},renewal,noticeDays,reminderDays,graceDays,pauseMonths;{scope_legend},groups',
    ],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Back end only: what the customer reads is built from the values
        // ("10 € / Monat · Mindestlaufzeit 1 Jahr …", A10), so no translation.
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        // Number and unit, "1 Jahr" as Michael put it (P6).
        'termLength' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'natural', 'minval' => 1, 'maxlength' => 3, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 1],
        ],
        'termUnit' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => ['month', 'year'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['termUnits'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 8, 'default' => 'year'],
        ],
        // In months; 0 is one payment for the whole term (Michael: "Interval 1 oder 0").
        'billingInterval' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        // Ends by default: the kind without legal risk (P7).
        'renewal' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'options' => array_map(static fn (RenewalKind $kind): string => $kind->value, RenewalKind::cases()),
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['renewals'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'ends'],
        ],
        'noticeDays' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 14],
        ],
        'reminderDays' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 30],
        ],
        // After the due date of a follow-up order (A6).
        'graceDays' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 14],
        ],
        // Abo pausieren (P102): months a customer may pause per contract year,
        // whole periods only (P103); 0 = not at all, the default.
        'pauseMonths' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 2, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        // Empty: every customer, guests excepted — a subscription needs an account (F9).
        'groups' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_member_group.name',
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
    ],
];
