<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_payment']['receivedAt'] = ['Received on', ''];
$GLOBALS['TL_LANG']['tl_consho_payment']['amount'] = ['Amount', 'The amount received. Money goes back through "Erstatten" on the payment, a withdrawal or a cancellation.'];
$GLOBALS['TL_LANG']['tl_consho_payment']['amountNotPositive'] = 'Only money received is entered here; enter an amount above 0. Money goes back through "Erstatten" on the payment, a withdrawal or a cancellation. A locked payment recorded too high is corrected through "Korrigieren".';
$GLOBALS['TL_LANG']['tl_consho_payment']['method'] = ['Method', 'How it was paid, e.g. "Bank transfer" or "PayPal".'];
$GLOBALS['TL_LANG']['tl_consho_payment']['reference'] = ['Reference', 'Payment reference, the provider\'s transaction id or similar.'];

$GLOBALS['TL_LANG']['tl_consho_payment']['payment_legend'] = 'Payment';

$GLOBALS['TL_LANG']['tl_consho_payment']['new'] = ['New payment', 'Record a payment'];
$GLOBALS['TL_LANG']['tl_consho_payment']['edit'] = ['Edit', 'Edit payment ID %s'];
$GLOBALS['TL_LANG']['tl_consho_payment']['delete'] = ['Delete', 'Delete payment ID %s'];
$GLOBALS['TL_LANG']['tl_consho_payment']['show'] = ['Details', 'Show details of payment ID %s'];

$GLOBALS['TL_LANG']['tl_consho_payment']['locked'] = 'This payment has already had consequences (invoice, status, mail or a refund) and is locked. If it was recorded too high, correct it through "Korrigieren" on the payment.';

// Refunds through the payment provider (block Zahlung Mollie, 4.4).
$GLOBALS['TL_LANG']['tl_consho_payment']['refundPayment'] = ['Refund', 'Pay back payment ID %s in full or in part'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundRetry'] = ['Pay out again', 'Request refund ID %s from the payment provider once more'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundManual'] = ['Settled by hand', 'Mark refund ID %s as settled by hand'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundBack'] = 'Back';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodRefund'] = 'Refund';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodChargeback'] = 'Chargeback';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodChargebackReversal'] = 'Chargeback reversed';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodCorrection'] = 'Correction';

// Correction of a locked payment (Geldflüsse E8).
$GLOBALS['TL_LANG']['tl_consho_payment']['correctPayment'] = ['Correct', 'Correct locked payment ID %s without moving money'];
// Replaces "ID %s" in the headline of these pages (Headline).
$GLOBALS['TL_LANG']['tl_consho_payment']['headlineOrder'] = 'of order %s';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctLegend'] = 'Correction for order';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctAmountHelp'] = 'At most %s. The amount the payment was recorded too high.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctReason'] = 'Reason';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctReasonHelp'] = 'Mandatory. Shown at the correction in the order; the customer does not see it.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctInvalid'] = 'Please enter an amount above 0 and at most %s, and a reason.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctNotPossible'] = 'This payment cannot be corrected: it is not locked yet (then simply edit it), it is paid back or corrected in full, or it is a voucher payment.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctConfirm'] = 'The payment of %s will then count as %s. Reason: %s. No money moves, the customer gets no mail, and no document is issued. The correction itself is locked afterwards.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctStateChanged'] = 'The payment has changed meanwhile. Please look at the list again.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctDone'] = 'The payment is corrected.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_correct'] = 'Continue';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_correctConfirm'] = 'Correct now';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundLegend'] = 'Refund for order';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundAmount'] = 'Amount';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundAmountHelp'] = 'At most %s — what of this payment is not paid back yet.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReference'] = 'Reference';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReferenceHelp'] = 'Shown at the refund in the order. It is not sent to the payment provider.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReferenceDefault'] = 'Refund %s';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundInvalidAmount'] = 'Please enter an amount above 0 and at most %s.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundNotPossible'] = 'Nothing is left to pay back from this payment: it is paid back or corrected in full. A voucher payment goes back onto the voucher through a withdrawal or a cancellation.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWaysInvalid'] = 'The amount cannot be split this way: the chosen refunds at the payment provider are more than the amount, or this payment has not that much left (at most %s).';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWays'] = 'Ways';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayProviderOption'] = 'Pay out through "%s"';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayForeignOption'] = '%s, already paid back at the payment provider (%s)';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWaysHelp'] = 'What none of these ways covers you pay back by hand. Do not have a refund already made at the payment provider paid out again.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWaysHandOnly'] = 'This payment method cannot refund through the payment provider. Consho books the refund; you pay it back by hand.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayForeign'] = '%s are already paid back at the payment provider (%s) and are recorded here.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayProvider'] = '%s will be paid back to the customer through "%s".';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayHand'] = '%s you pay back by hand.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundConfirm'] = 'This cannot be undone. Consho sends the customer no mail and issues no document — an invoice correction has to be created separately.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundRetryConfirm'] = 'The refund of %s will be requested from "%s" once more. The error log names why it was refused — fix that first, then pay out again.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundManualConfirm'] = 'The refund of %s ("%s") will be marked as settled and never requested from the payment provider again. Check the provider\'s account first whether the money went back — otherwise pay back by hand.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundPayoutReference'] = 'Provider\'s id: %s.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_refund'] = 'Continue';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_confirm'] = 'Pay back now';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_retry'] = 'Pay out again';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_manual'] = 'Mark as settled';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundResult'] = [
    'done' => 'The payment provider carried out the refund.',
    'requested' => 'The payment provider is still processing the refund; Consho asks again every hour.',
    'failed' => 'The payment provider refused the refund. It is booked, not paid out; the error log names the reason.',
    'manual' => 'What no payment provider can pay back is booked and has to be paid back by hand.',
    'sending' => 'The payment provider did not answer. Check in the provider\'s account whether the money went back.',
];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundStateChanged'] = 'The refund has changed meanwhile. Please look at the list again.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundManualDone'] = 'The refund is marked as settled.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderChoice'] = 'Refund at the payment provider';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderNone'] = 'The payment provider holds no refund of this amount that Consho does not know yet. The mark is made without an assignment.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderWithout'] = 'None of these — paid back otherwise';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderStates'] = ['processing' => 'processing', 'refunded' => 'refunded'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderUnavailable'] = 'The refunds at the payment provider cannot be chosen here: the module offers no list, or the provider did not answer. The mark is made without an assignment.';
$GLOBALS['TL_LANG']['tl_consho_payment']['payoutStates'] = [
    'requested' => 'processing',
    'sending' => 'no answer',
    'done' => 'paid out',
    'failed' => 'refused',
    'manual' => 'by hand',
];
