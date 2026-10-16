<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_order']['orderNumber'] = ['Order number', 'Issued on creation. Gaps are allowed here.'];
$GLOBALS['TL_LANG']['tl_consho_order']['orderStatus'] = ['Status', 'The only maintained state. Payment and delivery follow from the records themselves.'];
$GLOBALS['TL_LANG']['tl_consho_order']['shop'] = ['Shop', 'Which shop configuration this order belongs to.'];
$GLOBALS['TL_LANG']['tl_consho_order']['createdAt'] = ['Ordered on', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['language'] = ['Language', 'The language the order was placed in. Taken from the start page and never changed — not even when the mail is resent years later.'];
$GLOBALS['TL_LANG']['tl_consho_order']['member'] = ['Member', 'Empty for a guest order — the customer data is still complete here.'];

$GLOBALS['TL_LANG']['tl_consho_order']['salutation'] = ['Salutation', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['firstname'] = ['First name', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['lastname'] = ['Last name', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['email'] = ['Email', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['phone'] = ['Phone', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['company'] = ['Company', ''];

$GLOBALS['TL_LANG']['tl_consho_order']['billingStreet'] = ['Street and number', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['billingPostal'] = ['Postal code', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['billingCity'] = ['City', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['billingState'] = ['State', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['billingCountry'] = ['Country', ''];

$GLOBALS['TL_LANG']['tl_consho_order']['shippingCompany'] = ['Company', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingFirstname'] = ['First name', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingLastname'] = ['Last name', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingStreet'] = ['Street and number', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingPostal'] = ['Postal code', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingCity'] = ['City', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingState'] = ['State', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingPhone'] = ['Phone for the carrier', 'Only stored when the chosen shipping method needs it.'];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingCountry'] = ['Country', ''];

$GLOBALS['TL_LANG']['tl_consho_order']['vatId'] = ['VAT ID', 'As given for this order.'];
$GLOBALS['TL_LANG']['tl_consho_order']['vatIdStatus'] = ['Check result', 'The state at the time of the order — not today\'s.'];
$GLOBALS['TL_LANG']['tl_consho_order']['vatIdRequestIdentifier'] = ['VIES consultation number', 'Evidence of the query for a tax audit.'];

$GLOBALS['TL_LANG']['tl_consho_order']['currency'] = ['Currency', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['netTotal'] = ['Net total', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['taxTotal'] = ['Tax', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['grandTotal'] = ['Grand total', ''];

$GLOBALS['TL_LANG']['tl_consho_order']['shippingMethodTitle'] = ['Shipping method', 'What it was called at the time of the order.'];
$GLOBALS['TL_LANG']['tl_consho_order']['paymentInstructions'] = ['Payment instructions', 'As shown to the customer at the purchase, in the language of the order.'];
$GLOBALS['TL_LANG']['tl_consho_order']['paymentMethodTitle'] = ['Payment method', 'What it was called at the time of the order.'];
$GLOBALS['TL_LANG']['tl_consho_order']['termsAcceptedAt'] = ['Terms accepted', 'When the customer ticked the box. The wording they ticked it for is under "Legal texts".'];
$GLOBALS['TL_LANG']['tl_consho_order']['minimumAge'] = ['Minimum age', 'The limit that applied to this order. Stored because a limit can change.'];
$GLOBALS['TL_LANG']['tl_consho_order']['dateOfBirth'] = ['Date of birth', 'Kept as evidence for an age-restricted order, on the order rather than on the basket.'];

$GLOBALS['TL_LANG']['tl_consho_order']['order_legend'] = 'Order';
$GLOBALS['TL_LANG']['tl_consho_order']['customer_legend'] = 'Customer';
$GLOBALS['TL_LANG']['tl_consho_order']['billing_legend'] = 'Billing address';
$GLOBALS['TL_LANG']['tl_consho_order']['shipping_legend'] = 'Delivery address';
$GLOBALS['TL_LANG']['tl_consho_order']['method_legend'] = 'Shipping and payment';
$GLOBALS['TL_LANG']['tl_consho_order']['consent_legend'] = 'Consent and age';
$GLOBALS['TL_LANG']['tl_consho_order']['tax_legend'] = 'VAT';
$GLOBALS['TL_LANG']['tl_consho_order']['total_legend'] = 'Totals';

$GLOBALS['TL_LANG']['tl_consho_order']['items'] = ['Items', 'Edit the items of order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['legal'] = ['Legal texts', 'Legal texts of order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['withdrawals'] = ['Withdrawals', 'Withdrawals of order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['complaints'] = ['Complaints', 'Complaints of order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['complaintsNone'] = 'No complaint yet — record one here';
$GLOBALS['TL_LANG']['tl_consho_order']['payments'] = ['Payments', 'Edit the payments of order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['deliveries'] = ['Deliveries', 'Edit the deliveries of order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['edit'] = ['Edit', 'Edit order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['show'] = ['Details', 'Show details of order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['resendConfirmation'] = ['Resend confirmation', 'Send the order confirmation of order ID %s to the customer again'];
$GLOBALS['TL_LANG']['tl_consho_order']['resendSent'] = 'The confirmation of order %s was sent to %s again.';
$GLOBALS['TL_LANG']['tl_consho_order']['resendFailed'] = 'The confirmation of order %s could not be sent. The reason is in the system log.';

// E8: move the payment term
$GLOBALS['TL_LANG']['tl_consho_order']['deferPayment'] = ['Move payment term', 'Move the payment term of order ID %s to a later day'];
$GLOBALS['TL_LANG']['tl_consho_order']['deferBack'] = 'Back';
$GLOBALS['TL_LANG']['tl_consho_order']['deferLegend'] = 'Payment term';
$GLOBALS['TL_LANG']['tl_consho_order']['deferDate'] = 'New payment term';
$GLOBALS['TL_LANG']['tl_consho_order']['deferHelp'] = 'Until now by %s. Only a later day; the invoice keeps its date, the customer gets no mail. A subscription resting only for this order runs again at once.';
$GLOBALS['TL_LANG']['tl_consho_order']['deferSubmit'] = 'Move';
$GLOBALS['TL_LANG']['tl_consho_order']['deferDone'] = 'The payment term has been moved.';
$GLOBALS['TL_LANG']['tl_consho_order']['deferNotPossible'] = 'This order has no open payment term: it is paid, cancelled or has no term.';
$GLOBALS['TL_LANG']['tl_consho_order']['deferRefused'] = [
    'notDeferrable' => 'This order has no open payment term.',
    'invalidDate' => 'Please choose a valid date.',
    'notLater' => 'The new payment term must be after the current one.',
];

// D-1: a status must not claim what payments and deliveries do not bear out.
$GLOBALS['TL_LANG']['tl_consho_order']['statusRefused'] = 'The status "%s" cannot be set: %s.';
$GLOBALS['TL_LANG']['tl_consho_order']['digitalAddressUnconfirmed'] = 'The digital goods of this order went to %s — an address nobody has confirmed yet. Here the mail is the way of delivery: if the customer says nothing arrived, check the address first.';
$GLOBALS['TL_LANG']['tl_consho_order']['statusUnmet'] = [
    'paymentSettled' => '%s are still outstanding',
    'refundDue' => '%s have to be refunded',
    'deliverySettled' => 'not reported shipped: %s',
    'nothingShipped' => 'goods have already shipped — that is a withdrawal or a return, not a cancellation',
];
$GLOBALS['TL_LANG']['tl_consho_order']['resendAnonymized'] = 'The confirmation of order %s was not sent: the customer data has been anonymised after the retention period.';
$GLOBALS['TL_LANG']['tl_consho_order']['resendNoOrderPage'] = 'The confirmation of order %s was not sent: the start point of the shop has no order page, and the mail would link nowhere.';

/*
 * Labels for the three signals in the list row. They go into the title
 * attribute — colour alone does not carry the meaning, and red-green is the
 * most common colour blindness of all.
 */
$GLOBALS['TL_LANG']['tl_consho_order']['signalsColumn'] = 'Status';

$GLOBALS['TL_LANG']['tl_consho_order']['customerColumn'] = 'Customer';

$GLOBALS['TL_LANG']['tl_consho_order']['signals'] = [
    'status' => [
        'none' => 'No status set',
    ],
    'payment' => [
        'open' => 'Payment: open',
        'partial' => 'Payment: partly received',
        'paid' => 'Payment: settled',
        'overpaid' => 'Payment: overpaid — money has to go back',
        'refunded' => 'Payment: refunded',
        'nothingDue' => 'Payment: nothing due',
    ],
    'delivery' => [
        'open' => 'Delivery: open',
        'progress' => 'Delivery: partly shipped',
        'attention' => 'Delivery: check',
        'done' => 'Delivery: complete',
    ],
];
$GLOBALS['TL_LANG']['tl_consho_order']['remainderDeliveryDays'] = ['Remainder after a partial delivery (working days)', 'Empty: the shop default. Applies to the invoice issued with the first parcel.'];
$GLOBALS['TL_LANG']['tl_consho_order']['digitalConsentAt'] = ['Consented to early start', 'When the customer consented to digital content being supplied before the withdrawal period ends and confirmed losing the right of withdrawal by it. Empty: not given or not asked — digital lines are then released only after the period.'];
$GLOBALS['TL_LANG']['tl_consho_order']['servicePeriodFrom'] = ['Service period from', 'For a follow-up order: the start of the period billed.'];
$GLOBALS['TL_LANG']['tl_consho_order']['servicePeriodUntil'] = ['Service period until', 'For a follow-up order: the end of the period billed.'];
$GLOBALS['TL_LANG']['tl_consho_order']['subscription'] = ['Follow-up order of subscription', 'Empty for a purchase. Set when the order bills a period of a subscription.'];
$GLOBALS['TL_LANG']['tl_consho_order']['businessCustomer'] = ['Business customer', 'Counted as a business at the purchase: net price group or verified VAT ID. No consent is asked of a business customer.'];
$GLOBALS['TL_LANG']['tl_consho_order']['entitlements'] = ['Entitlements', 'Entitlements from the digital lines of order ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['addressesFrozen'] = 'Some customer and address details are read-only: the delivery address is on a recorded delivery or a delivery note, the customer and billing address on an invoice. An invoice is corrected by a credit note, not here.';
