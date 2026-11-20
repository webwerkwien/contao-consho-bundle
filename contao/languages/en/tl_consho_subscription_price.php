<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_subscription_price']['offer_legend'] = 'Price offer';
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['answer_legend'] = 'Answer';
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['mail_legend'] = 'E-mails';
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['createdAt'] = ['Created', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['effectiveAt'] = ['Effective from', 'The new price applies from this day if the customer agrees.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['deadlineAt'] = ['Answer by', 'The effective day minus the customer\'s notice period.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['oldUnitNet'] = ['Previous (net per unit)', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['newUnitNet'] = ['New (net per unit)', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['currency'] = ['Currency', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['quantity'] = ['Quantity', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['decrease'] = ['Price decrease', 'Applied without asking.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['state'] = ['Status', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['states'] = [
    'open' => 'open',
    'accepted' => 'accepted',
    'declined' => 'declined',
    'lapsed' => 'deadline passed',
    'void' => 'void',
];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['answeredAt'] = ['Answered', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['answeredBy'] = ['Answered via', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['answers'] = ['account' => 'Customer ("My subscriptions")', 'silence' => 'Silence (business customer)'];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['cancellation'] = ['Cancellation', 'The cancellation from a refusal or a consumer\'s silence.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['appliedAt'] = ['Applied', 'The new price applies from this billing on.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['mailSentAt'] = ['Offer e-mailed', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['reminderSentAt'] = ['Reminder e-mailed', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['answerMailSentAt'] = ['Answer or deadline e-mailed', 'Empty while one is due: it did not go out, the finding names the subscription.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_price']['show'] = ['Details', 'Show the details of price offer ID %s'];
