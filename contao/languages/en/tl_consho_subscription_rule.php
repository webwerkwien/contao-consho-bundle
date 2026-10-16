<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['title'] = ['Title', 'Back end only, e.g. "Monthly". The customer reads the terms Consho builds from the values.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['termLength'] = ['Minimum term', 'A number, the unit next to it. A yearly subscription: 1 year.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['termUnit'] = ['Unit', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['termUnits'] = ['month' => 'months', 'year' => 'years'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['billingInterval'] = ['Billing interval (months)', '0: one payment for the whole term. Otherwise a follow-up order every so many months; the interval must divide the term without remainder. The amount per payment is in the prices of the product.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['renewal'] = ['At the end of the minimum term', 'Decides how long the customer is bound afterwards. Example: a minimum term of 12 months, from month 13: "ends" — over, the customer is reminded and can buy again; "full minimum term" — another 12 months fixed, cancellable at the end of each round; "cancellable at any time" — continues, cancellable with at most 28 days\' notice. Every renewal is preceded by a reminder to the customer.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['renewals'] = [
    'ends' => 'ends — the customer is reminded and can buy again',
    'sameTerm' => 'renews for the full minimum term each time — bound again (for business customers: restrict under "Customer groups")',
    'indefinite' => 'continues, cancellable at any time — with at most 28 days\' notice',
];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['noticeDays'] = ['Notice period (days)', 'Before the end of the minimum term; with "cancellable at any time" also afterwards. There at most 28 days.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['reminderDays'] = ['Reminder (days before)', 'This many days before the end of the minimum term the reminder is sent. Must lie before the notice period, so the customer can still cancel.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['graceDays'] = ['Grace period (days)', 'If a follow-up order is still open this long after its due date, access is suspended until the payment arrives.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['pauseMonths'] = ['Pause (months per contract year)', 'This many months per contract year the customer may pause the subscription under "My subscriptions", in whole periods from the next period on. The term moves by the pause. 0: no pause.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['groups'] = ['Customer groups', 'Empty: every customer with an account. A subscription always needs an account.'];

$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['errors'] = [
    'intervalDividesTerm' => 'The billing interval must divide the minimum term without remainder (for 12 months: 1, 2, 3, 4, 6 or 12), or 0 for one payment.',
    'noticeTooLong' => 'With "continues, cancellable at any time" the notice period may be at most 28 days.',
    'indefiniteMonthlyOnly' => '"Continues, cancellable at any time" requires monthly payment (billing interval 1) — otherwise every cancellation would owe a partial refund. For a yearly plan choose "renews for the full minimum term" or "ends".',
    'reminderBeforeNotice' => 'The reminder has to come before the notice period starts — otherwise the customer can no longer cancel after it.',
    'pauseShorterThanPeriod' => 'A pause is made of whole periods: it has to be at least one billing interval long.',
];

$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['title_legend'] = 'Title';
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['term_legend'] = 'Term and payment';
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['renewal_legend'] = 'Renewal, cancellation, arrears';
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['scope_legend'] = 'Applies to';

$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['new'] = ['New subscription rule', 'Create a subscription rule'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['edit'] = ['Edit', 'Edit subscription rule ID %s'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['copy'] = ['Duplicate', 'Duplicate subscription rule ID %s'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['delete'] = ['Delete', 'Delete subscription rule ID %s'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['show'] = ['Details', 'Show the details of subscription rule ID %s'];
