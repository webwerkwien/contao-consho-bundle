<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['number'] = ['Withdrawal number', 'Derived from the order number.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['receivedAt'] = ['Received', 'Moment of receipt by the server clock — as stated in the confirmation.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['source'] = ['Channel', 'Through the order page (link from the mail), the withdrawal form, or recorded by hand (e-mail, letter, phone).'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['name'] = ['Name', 'As given or confirmed by the customer.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['orderReference'] = ['Order number given', 'As the customer typed it into the withdrawal form — even where it matched no order.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['afterWindow'] = ['Received after the window', 'Received after "last shipment + period + grace". The window counts from shipping, the period from receipt — whether the withdrawal was in time is your decision.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['email'] = ['E-mail', 'The address the confirmation of receipt went to.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scope'] = ['Scope', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['part'] = ['Part withdrawn', 'In the customer\'s words.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['message'] = ['Message', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['confirmationSentAt'] = ['Confirmation sent', 'Empty: the confirmation of receipt did not go out — the system log names the reason — or it was not wanted when the withdrawal was recorded by hand. The withdrawal stands regardless.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['state'] = ['State', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['states'] = ['received' => 'received', 'accepted' => 'accepted', 'rejected' => 'rejected'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decidedAt'] = ['Decided', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['reason'] = ['Reason for rejection', 'When rejected on an order, it went to the customer verbatim; when dismissed without an order, it stayed internal.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnReceivedAt'] = ['Return received', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundShipping'] = ['Delivery costs refunded', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['valueCompensation'] = ['Value compensation', 'Kept back for a service (§ 357a(2) BGB): proposed at acceptance, set with the refund.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundAmount'] = ['Refunded', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundedAt'] = ['Refunded on', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['process'] = ['Process', 'Decide withdrawal ID %s, record the return and book the refund'];

// The page "Decide withdrawal" (D-3).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['back'] = 'Back';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['pid'] = ['Order', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['signalsColumn'] = 'Status';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['customerColumn'] = 'Customer';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDueColumn'] = 'Refund due by';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['amountColumn'] = 'Refunded';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['signals'] = [
    'state' => [
        'received' => 'Withdrawal received — to be decided',
        'accepted' => 'Withdrawal accepted — refund open',
        'overdue' => 'Refund overdue',
        'rejected' => 'Withdrawal rejected',
        'done' => 'Withdrawal settled',
    ],
    'return' => [
        'none' => 'Return: nothing back yet',
        'partial' => 'Return: partly back',
        'complete' => 'Return: fully back',
        'pending' => 'Return: after the decision',
        'na' => 'Return: not required',
    ],
    'refund' => [
        'open' => 'Refund: open',
        'done' => 'Refund: booked',
        'na' => 'Refund: none',
    ],
];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['pageTitle'] = 'Withdrawal %s from order %s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['headOrder'] = 'from order';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['declarationLegend'] = 'Withdrawal';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['nextLegend'] = 'Next step';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['doneRejected'] = 'Rejected — the customer received the reason by mail.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['doneRefunded'] = 'Settled — the refund is booked, the customer received the document by mail.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['datesLegend'] = 'Dates';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['windowClosesAt'] = 'End of window';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['windowOpen'] = 'delivery outstanding';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDueAt'] = 'Refund due by';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['datesHelp'] = 'Note: the end of the window is last shipment + period + delivery allowance — an estimate, since the period starts when the goods are received. A withdrawal after it is still taken and marked "received after the window".';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptLegend'] = 'Accept';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colItem'] = 'Item';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colOrdered'] = 'ordered';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colDeclared'] = 'declared';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colLeft'] = 'not yet withdrawn';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colAccept'] = 'accept';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colAccepted'] = 'accepted';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colReturned'] = 'returned';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptHelp'] = 'Prefilled with what the customer named; for "whole order" with everything outstanding, for a part in free text with nothing — read the text above. The declaration itself stays unchanged.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectLegend'] = 'Reject';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectHelp'] = 'Sent to the customer verbatim by e-mail.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decideLegend'] = 'Decision';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decideTiles'] = ['accept' => 'goods back, money back', 'reject' => 'with a reason to the customer'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['chooseDecision'] = 'Choose the decision';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['chooseHint'] = 'Choose the decision';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decidedAs'] = 'Decided';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['completesWith'] = 'The withdrawal is settled with the refund. The return can be recorded before or after it.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colDelivered'] = 'Delivery information';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['shippedOn'] = 'shipped';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['notDelivered'] = 'not shipped yet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['releasedOn'] = 'released';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['notReleased'] = 'not released yet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['issuedOn'] = 'issued';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['notIssued'] = 'not issued yet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['deliveriesLink'] = 'Deliveries of the order';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['deliveredGap'] = ['same' => 'on the day of receipt', 'oneBefore' => '1 day before receipt', 'before' => '%d days before receipt', 'oneAfter' => '1 day after receipt', 'after' => '%d days after receipt'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnLegend'] = 'Return';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnSubmit'] = 'Save return';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundLegend'] = 'Refund';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['retentionHint'] = 'The goods are not recorded as returned yet.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['processWithdrawal'] = ['Decide withdrawal', 'Decide withdrawal'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundGoods'] = 'Goods';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['shippingHelp'] = 'Delivery costs not yet refunded: %s. Proposed for a withdrawal of the whole order, otherwise 0.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundSubmit'] = 'Book refund';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDocumentLabel'] = 'Document';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['subscriptionLabel'] = 'Subscription';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationHelp'] = 'Proposed value compensation for a service (§ 357a(2) BGB): line amount × days used / days of the paid period; every 24 hours begun from the release to the receipt of the withdrawal count as a day. You decide the amount; it is deducted from the refund, and the invoice is then corrected instead of cancelled.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationLine'] = '%1$s × %2$d / %3$d days = %4$s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['throughProvider'] = ['Pay back through the payment method (%s)', 'Once booked, the payment method\'s part goes back to the customer through the payment provider, at most %s; you confirm the amount first. Anything beyond that remains to be paid back by hand. Unticked, the refund is only booked, as before.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirm'] = '%s will be paid back to the customer through "%s". This cannot be undone. The refund, the document and the mail are booked as usual.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirmManual'] = '%s remain to be paid back by hand.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirmRecorded'] = '%s are already paid back at the payment provider and are recorded with it.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundForeign'] = ['Already paid back at the payment provider', 'Refunds made directly at the payment provider that are still open in Consho. Ticked, they are part of this refund: they are booked first, and the payment provider pays only the rest. While one is open, "Pay back through the payment method" is not preselected.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirmSubmit'] = 'Pay back now';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirmCancel'] = 'Cancel';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundVouchers'] = ['Paid with gift vouchers', 'The refund goes back onto the vouchers first (the same means of payment, § 14(1) FAGG, § 357(3) BGB), at most what this refund pays out; the rest to the payment method. An expired voucher becomes valid again without a time limit; a blocked one gets nothing back, its share goes to the payment method.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationReasons'] = [
    'noConsent' => 'no consent to a start within the period — no compensation',
    'notReleased' => 'not released — nothing rendered',
    'noPeriod' => 'no subscription period — please assess yourself',
];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['result'] = [
    'choiceMissing' => 'Please choose Accept or Reject first.',
    'accepted' => 'Withdrawal %s accepted.',
    'rejected' => 'Withdrawal %s rejected, the customer has been notified.',
    'rejectedNoMail' => 'Withdrawal %s rejected. The mail to the customer did not go out — the system log names the reason.',
    'returnRecorded' => 'Return for %s saved.',
    'refunded' => 'Refund for %s booked, the customer has the document by mail.',
    'refundedNoMail' => 'Refund for %s booked. The mail to the customer did not go out — the system log names the reason.',
    'alreadyDecided' => 'This withdrawal has already been decided.',
    'notAccepted' => 'This is only possible once the withdrawal is accepted.',
    'alreadyRefunded' => 'This refund has already been booked.',
    'payoutChanged' => 'The amount through the payment provider has changed meanwhile. Nothing was booked — please check and book again.',
    'foreignInvalid' => 'The ticked refunds at the payment provider do not fit this refund: together they are more than the payment method\'s part, or they were settled otherwise meanwhile. Nothing was booked.',
    'reasonMissing' => 'Please give a reason — it goes to the customer.',
    'invalidQuantity' => 'A quantity does not fit: numbers from 0 only, at most what was ordered or accepted.',
    'tooMuch' => 'At most %2$s of %1$s is not yet withdrawn.',
    'wholeOnly' => '%1$s is a subscription and is accepted only whole (%2$s) or not at all.',
    'nothingAccepted' => 'Please enter at least one quantity.',
    'invalidShipping' => 'The delivery share has to be between 0 and %s.',
    'invalidCompensation' => 'The value compensation must be between 0 and %s.',
    'voucherRedeemed' => 'Withdrawn gift vouchers have already been redeemed: %s. What was bought with them has to go back first — withdraw or cancel the orders named; their refund fills the voucher up again. Nothing was booked.',
    'voucherBlocked' => 'Withdrawn gift vouchers were blocked by hand: %s. Whether they go back is your decision — for instance if a replacement was issued. Nothing was booked.',
    'voucherChangedByHand' => 'The balance of withdrawn gift vouchers was changed by hand: %s. Whether and how much goes back is your decision. Nothing was booked.',
    'numberRangeMissing' => 'The number range "%s" is missing in the shop configuration. Nothing was booked.',
    'unknown' => 'This withdrawal does not exist (any more).',
    // WA, A4: "Assign to an order".
    'assigned' => 'Withdrawal %s is assigned to order %s and can be processed now.',
    'assignedLate' => 'Withdrawal %s is assigned to order %s. It arrived after the end of that order\'s withdrawal window — whether it was in time is your decision.',
    // WA: one message for "does not exist" and "not yours".
    'assignNotFound' => 'There is no order with this number in this shop that you may edit.',
    'assignAlready' => 'This withdrawal is already assigned to an order.',
    // WA: "Dismiss without an order".
    'dismissed' => 'Withdrawal %s dismissed without an order. The customer was not notified.',
    'dismissReasonMissing' => 'Please give a reason — it stays internal.',
    // "Record withdrawal".
    'captured' => 'Withdrawal %s recorded. No confirmation of receipt was sent.',
    'capturedConfirmed' => 'Withdrawal %s recorded, the confirmation of receipt went to %s.',
    'capturedNotConfirmed' => 'Withdrawal %s recorded. The confirmation of receipt did not go out — the system log names the reason.',
    'captureChanged' => 'Nothing recorded: a withdrawal was added to order %s since the summary was shown. Please check the list whether it is already recorded.',
    'captureAnonymized' => 'No withdrawal can be recorded for order %s: the customer data has been anonymised after the retention period.',
];

// WA: a withdrawal without an order, and the late receipt.
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['unassigned'] = 'not assigned';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['unassignedTitle'] = 'Withdrawal %s — not assigned to an order';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['unassignedHelp'] = 'The order number and e-mail address from the withdrawal form match no order of this shop — or one that cannot be withdrawn from through the form (for instance without a right of withdrawal, or cancelled before shipping). The withdrawal has nevertheless been received and confirmed to the address given. Assign it to the order it is about — then you can accept it or reject it with a reason. The declaration itself does not change.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['assignLegend'] = 'Assign to an order';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['assignOrderNumber'] = 'Order number';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['assignSubmit'] = 'Assign';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissLegend'] = 'Dismiss without an order';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissReason'] = 'Reason (internal)';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissHelp'] = 'For declarations that belong to no order — nonsense, a duplicate. The withdrawal then counts as rejected and leaves the open work; the declaration stays stored. The customer is not notified: the address is not verified.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissSubmit'] = 'Dismiss';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissedLegend'] = 'Dismissed without an order';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['afterWindowHint'] = 'Received after the end of the withdrawal window. The window counts from shipping, the period from receipt — whether the withdrawal was in time is your decision.';

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['sources'] = ['orderPage' => 'Order page', 'form' => 'Withdrawal form', 'email' => 'E-mail', 'letter' => 'Letter', 'oral' => 'Phone/in person', 'other' => 'Other'];

// "Record withdrawal": a withdrawal that came in outside the shop.
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['capture'] = ['Record withdrawal', 'Record a withdrawal received by e-mail, letter or phone'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureTitle'] = 'Record withdrawal';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureHelp'] = 'For a withdrawal that did not come through the shop but by e-mail, letter, phone or in person. It is stored like a withdrawal from the shop and then accepted or rejected on "Decide withdrawal". Once stored, it cannot be changed.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureReceivedAtHelp'] = 'When the declaration was received, not when you record it — for a letter, the day it arrived.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureItems'] = 'Items (for "single items")';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureColQuantity'] = 'withdrawn';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureWhole'] = 'Subscription: whole only (%s)';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureNoGoods'] = 'No item is open for a withdrawal any more. You can still record the declaration — as the whole order or as a part in words.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['capturePartHelp'] = 'For "part (free text)": which part, in the customer\'s words.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureMessage'] = ['Declaration verbatim', 'What the customer wrote or said — the text of the e-mail, for instance. For a call: who, when, what.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureConfirm'] = ['Send the confirmation of receipt to %s', 'The same mail as for a withdrawal from the shop, with the moment of receipt entered. Unticked, nothing is sent.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureConfirmTo'] = 'will be sent to %s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureConfirmNone'] = 'will not be sent';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureReview'] = 'Continue to review';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureReviewLegend'] = 'Review and record';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureReviewHelp'] = 'Please check the details. Once recorded, the withdrawal cannot be changed — a mistake is corrected by a rejection with a reason and a new record.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureRecord'] = 'Record withdrawal';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureEdit'] = 'Change';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureClosedHint'] = 'Through the shop this order could not be withdrawn from — for instance without a withdrawal period, cancelled before shipping or with released digital content. The withdrawal is recorded all the same; whether it holds is your decision.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureErrors'] = [
    'receivedAt' => 'Please enter the date and time of receipt.',
    'future' => 'The receipt cannot lie in the future.',
    'beforeOrder' => 'The receipt cannot lie before the order.',
    'source' => 'Please choose how the withdrawal was received.',
    'name' => 'Please enter the name.',
    'part' => 'Please describe the part withdrawn.',
    'items' => 'Please enter at least one quantity — at most what is not yet withdrawn; a subscription only whole.',
    'whole' => 'A subscription can only be withdrawn whole: %s. Please enter the full open quantity or leave the field empty.',
];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scopes'] = ['all' => 'whole order', 'items' => 'single items', 'part' => 'part (free text)'];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['withdrawal_legend'] = 'Declaration';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['confirmation_legend'] = 'Confirmation of receipt';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['state_legend'] = 'Handling';

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['items'] = ['Items', 'Items of withdrawal ID %s'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['show'] = ['Details', 'Show details of withdrawal ID %s'];


// Block G (G9).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colRestock'] = 'back into stock';
