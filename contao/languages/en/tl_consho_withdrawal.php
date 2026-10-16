<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['number'] = ['Withdrawal number', 'Derived from the order number.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['receivedAt'] = ['Received', 'Moment of receipt by the server clock — as stated in the confirmation.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['source'] = ['Channel', 'Through the order page (link from the mail) or the withdrawal form.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['name'] = ['Name', 'As given or confirmed by the customer.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['email'] = ['E-mail', 'The address the confirmation of receipt went to.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scope'] = ['Scope', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['part'] = ['Part withdrawn', 'In the customer\'s words.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['message'] = ['Message', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['confirmationSentAt'] = ['Confirmation sent', 'Empty: the confirmation of receipt did not go out — the system log names the reason. The withdrawal stands regardless.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['state'] = ['State', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['states'] = ['received' => 'received', 'accepted' => 'accepted', 'rejected' => 'rejected'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decidedAt'] = ['Decided', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['reason'] = ['Reason for rejection', 'Sent to the customer verbatim.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnReceivedAt'] = ['Return received', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundShipping'] = ['Delivery costs refunded', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['valueCompensation'] = ['Value compensation', 'Kept back for a service (§ 357a(2) BGB): proposed at acceptance, set with the refund.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundAmount'] = ['Refunded', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundedAt'] = ['Refunded on', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['process'] = ['Process', 'Process withdrawal ID %s: accept or reject, return, refund'];

// The page "Process withdrawal" (D-3).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['back'] = 'Back';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['labelDue'] = 'refund due by %s';
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
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['labelRefunded'] = 'refunded on %s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['pageTitle'] = 'Withdrawal %s from order %s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['headOrder'] = 'from order';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['declarationLegend'] = 'Declaration and dates';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['nextLegend'] = 'Next step';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['doneRejected'] = 'Rejected — the customer received the reason by mail.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['doneRefunded'] = 'Settled — the refund is booked, the customer received the document by mail.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['datesLegend'] = 'Dates';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['windowClosesAt'] = 'Withdrawal function open until';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['windowOpen'] = 'open while goods are outstanding';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDueAt'] = 'Refund due by';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['datesHelp'] = 'Hints, not a verdict: the function closes after last shipment + period + delivery allowance; whether the withdrawal was in time is for you to judge. The refund is due within 14 days (§ 357 Abs. 1 BGB; § 14 Abs. 1 FAGG: from receipt of the declaration).';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptLegend'] = 'Accept';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colItem'] = 'Item';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colOrdered'] = 'ordered';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colDeclared'] = 'declared';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colLeft'] = 'not yet withdrawn';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colAccept'] = 'accept';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colAccepted'] = 'accepted';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colReturned'] = 'returned';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptHelp'] = 'Prefilled with what the customer named; for "whole order" with everything outstanding, for a part in free text with nothing — read the text above. The declaration itself stays unchanged.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptSubmit'] = 'Accept';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectLegend'] = 'Reject';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectHelp'] = 'Sent to the customer verbatim by e-mail.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectSubmit'] = 'Reject and notify the customer';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnLegend'] = 'Return';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnSubmit'] = 'Save return';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundLegend'] = 'Refund';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['retentionHint'] = 'The goods are not recorded as returned yet. You may withhold the refund until they are back or the customer proves they were sent — unless you offered to collect them (§ 357 Abs. 4 BGB, § 14 Abs. 3 FAGG). You do not have to.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['processWithdrawal'] = ['Process withdrawal', 'Process withdrawal'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundGoods'] = 'Goods';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['shippingHelp'] = 'Delivery costs not yet refunded: %s. Proposed for a withdrawal of the whole order, otherwise 0 — § 357 Abs. 2 BGB and § 14 FAGG say nothing about a partial withdrawal. Extra costs of a delivery dearer than the cheapest standard delivery need not be refunded.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundSubmit'] = 'Book refund';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDocumentLabel'] = 'Document';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['subscriptionLabel'] = 'Subscription';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationHelp'] = 'Proposed value compensation for a service (§ 357a(2) BGB): line amount × days used / days of the paid period; every 24 hours begun from the release to the receipt of the withdrawal count as a day. You decide the amount; it is deducted from the refund, and the invoice is then corrected instead of cancelled.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationLine'] = '%1$s × %2$d / %3$d days = %4$s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundVouchers'] = ['Of which onto vouchers', 'Paid with gift vouchers: %s. The refund goes back there first (the same means of payment, § 14(1) FAGG, § 357(3) BGB), at most what this refund pays out; the rest to the payment method. An expired voucher becomes valid again without a time limit; a blocked one gets nothing back, its share goes to the payment method.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationReasons'] = [
    'noConsent' => 'no consent to a start within the period — no compensation',
    'notReleased' => 'not released — nothing rendered',
    'noPeriod' => 'no subscription period — please assess yourself',
];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['result'] = [
    'accepted' => 'Withdrawal %s accepted.',
    'rejected' => 'Withdrawal %s rejected, the customer has been notified.',
    'rejectedNoMail' => 'Withdrawal %s rejected. The mail to the customer did not go out — the system log names the reason.',
    'returnRecorded' => 'Return for %s saved.',
    'refunded' => 'Refund for %s booked, the customer has the document by mail.',
    'refundedNoMail' => 'Refund for %s booked. The mail to the customer did not go out — the system log names the reason.',
    'alreadyDecided' => 'This withdrawal has already been decided.',
    'notAccepted' => 'This is only possible once the withdrawal is accepted.',
    'alreadyRefunded' => 'This refund has already been booked.',
    'reasonMissing' => 'Please give a reason — it goes to the customer.',
    'invalidQuantity' => 'A quantity does not fit: numbers from 0 only, at most what was ordered or accepted.',
    'tooMuch' => 'At most %2$s of %1$s is not yet withdrawn.',
    'wholeOnly' => '%1$s is a subscription and is accepted only whole (%2$s) or not at all.',
    'nothingAccepted' => 'Please enter at least one quantity.',
    'invalidShipping' => 'The delivery share has to be between 0 and %s.',
    'invalidCompensation' => 'The value compensation must be between 0 and %s.',
    'invalidVoucherShare' => 'Between 0 and %s can go back onto vouchers.',
    'voucherRedeemed' => 'Withdrawn gift vouchers have already been redeemed: %s. What was bought with them has to go back first — withdraw or cancel the orders named; their refund fills the voucher up again. Nothing was booked.',
    'voucherBlocked' => 'Withdrawn gift vouchers were blocked by hand: %s. Whether they go back is your decision — for instance if a replacement was issued. Nothing was booked.',
    'voucherChangedByHand' => 'The balance of withdrawn gift vouchers was changed by hand: %s. Whether and how much goes back is your decision. Nothing was booked.',
    'numberRangeMissing' => 'The number range "%s" is missing in the shop configuration. Nothing was booked.',
    'unknown' => 'This withdrawal does not exist (any more).',
];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['sources'] = ['orderPage' => 'Order page', 'form' => 'Withdrawal form'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scopes'] = ['all' => 'whole order', 'items' => 'single items', 'part' => 'part (free text)'];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['withdrawal_legend'] = 'Declaration';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['confirmation_legend'] = 'Confirmation of receipt';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['state_legend'] = 'Handling';

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['items'] = ['Items', 'Items of withdrawal ID %s'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['show'] = ['Details', 'Show details of withdrawal ID %s'];

$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['orderItem'] = ['Item', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['quantity'] = ['Quantity declared', '0 on an item the operator added when accepting.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['acceptedQuantity'] = ['Accepted', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['returnedQuantity'] = ['Returned', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['item_legend'] = 'Item';
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['show'] = ['Details', 'Show details of item ID %s'];

// Block G (G9).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colRestock'] = 'back into stock';
