<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_delivery']['shippedAt'] = ['Shipping date', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['carrier'] = ['Carrier', 'e.g. Post AT, DPD, GLS.'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['trackingNumber'] = ['Tracking number', ''];

$GLOBALS['TL_LANG']['tl_consho_delivery']['delivery_legend'] = 'Delivery';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contents_legend'] = 'Contents';
$GLOBALS['TL_LANG']['tl_consho_delivery']['report_legend'] = 'Report shipment';

$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsComplete'] = 'Complete delivery';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsCompleteHelp'] = 'Everything still open of the order goes with this parcel. For a partial delivery, untick and enter the quantities; 0 means not in the parcel.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsItem'] = 'Item';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsOpen'] = 'open';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsInParcel'] = 'in this parcel';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsQuantityLabel'] = 'Quantity of %s in this parcel';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsNothingOpen'] = 'Nothing of this order is left to ship:';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsAccount'] = [
    'ordered' => 'ordered %s',
    'shipped' => 'shipped %s',
    'withdrawn' => 'withdrawn %s',
    'planned' => 'in another delivery not reported yet %s',
];
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsCancelled'] = 'The order is cancelled; there is nothing to ship.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsNoGoods'] = 'This order holds no goods that go into a parcel.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsInvalid'] = 'The contents were not taken over: each quantity must be a number between 0 and the open quantity.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notify'] = 'Report as shipped and notify the customer';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notifyHelp'] = 'On saving: the customer gets the shipping mail with the contents of the parcel, invoice and delivery note are issued depending on the payment method and settings, and the delivery is locked afterwards. Unticked, it stays a draft and is reported later in the list of deliveries.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notifyDenied'] = 'Not reported: you may not edit the order.';

// RK-5: a replacement delivery for a complaint.
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementFor'] = 'Replacement delivery for complaint %s: no invoice, and it does not count as a delivery of the order. Reporting it takes the goods out of stock.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementCompleteHelp'] = 'Everything still to be replaced for this complaint goes with this parcel. For a partial replacement, untick and enter the quantities; 0 means not in the parcel.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsToReplace'] = 'to replace';
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementNothingLeft'] = 'Nothing is left to replace for this complaint.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementInvalid'] = 'The contents were not taken over: each quantity must be a number between 0 and the quantity still to replace.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notifyReplacementHelp'] = 'On saving: the goods leave stock, the customer gets the replacement mail with the contents of the parcel, a shop with a delivery note number range issues the delivery note, and the delivery is locked afterwards. No invoice. Unticked, it stays a draft.';

$GLOBALS['TL_LANG']['tl_consho_delivery']['new'] = ['New delivery', 'Record a delivery'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['items'] = ['Contents', 'Edit the contents of delivery ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['edit'] = ['Edit', 'Edit delivery ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['delete'] = ['Delete', 'Delete delivery ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['show'] = ['Details', 'Show details of delivery ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['report'] = ['Report as shipped', 'Report delivery ID %s as shipped: the customer gets a mail with the contents of the parcel, and payment methods that invoice on shipment issue the invoice, and a shop with a delivery note number range issues the delivery note. The delivery is locked afterwards.'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['deliveryNote'] = ['Delivery note', 'Open the delivery note of delivery ID %s as PDF'];

$GLOBALS['TL_LANG']['tl_consho_delivery']['notReported'] = 'not reported yet';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notNotified'] = 'not sent';

$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotification'] = ['Resend notification', 'Send the mail about delivery ID %s to the customer once more'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationSent'] = 'The notification about the delivery of order %s was sent again to %s.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationFailed'] = 'The notification about the delivery of order %s could not be sent. The system log names the reason.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationAnonymized'] = 'The notification about the delivery of order %s was not sent: the customer data has been anonymised after the retention period.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationNothing'] = 'There is no notification for this delivery of order %s: it is not reported yet, or it is an electronic release with a mail of its own.';

// Column headings and the kind icon of the list.
$GLOBALS['TL_LANG']['tl_consho_delivery']['kind'] = ['Kind', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['notification'] = ['Notification', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportedAt'] = ['Reported on', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['notifiedAt'] = ['Notified on', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['lockedAt'] = ['Locked since', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['complaint'] = ['Complaint (ID)', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['kindDelivery'] = 'Delivery';

// Services in the delivery list (DeliveryServiceListener).
$GLOBALS['TL_LANG']['tl_consho_delivery']['servicePerformed'] = 'Service performed';
$GLOBALS['TL_LANG']['tl_consho_delivery']['servicePerformedTitle'] = 'Report "%s" as fully performed';
$GLOBALS['TL_LANG']['tl_consho_delivery']['servicePerformedConfirm'] = 'Report the service as fully performed? This cannot be undone. If the customer agreed to the early start, their right of withdrawal for this line ends.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['servicePerformedOn'] = '%s: performed on %s';
$GLOBALS['TL_LANG']['tl_consho_delivery']['releaseOn'] = '"%s" will be released on %s.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['releaseAfterPayment'] = '"%s" will be released once paid.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['releaseDue'] = '"%s" will be released with the next run.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['releaseThenPerformed'] = '"Service performed" then appears here at the delivery.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['locked'] = 'This delivery is reported as shipped and locked: the customer has already had the mail about this parcel.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementLabel'] = 'Replacement for %s';
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportResult'] = [
    'reported' => 'The delivery of order %s is reported; the customer has had the shipping mail.',
    'reportedNoMail' => 'The delivery of order %s is reported. The shipping mail did not go out — the reason is in the system log.',
    'reportedReplacement' => 'The replacement delivery of order %s is reported; the customer has received the replacement mail.',
    'reportedReplacementNoMail' => 'The replacement delivery of order %s is reported. The replacement mail did not go out — the reason is in the system log.',
    'tooMuch' => 'Not reported: these items exceed what is still open: %s.',
    'tooMuchForComplaint' => 'Not reported: these items exceed what the complaint still has to replace: %s.',
    'outOfStock' => 'Not reported: the stock does not cover these items, and they cannot be backordered: %s. Nothing was booked out.',
    'notReplacement' => 'Not reported: the complaint is no longer open as a replacement.',
    'empty' => 'Not reported: the delivery carries no item with a quantity.',
    'alreadyReported' => 'This delivery is already reported.',
    'unknown' => 'Not reported: the delivery no longer exists.',
];

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['orderItem'] = ['Item', 'Which item of this order travelled.'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['quantity'] = ['Quantity', 'How much of it went with this delivery. Partial deliveries follow from this by themselves.'];

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['item_legend'] = 'Item quantity';

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['new'] = ['New item quantity', 'Record an item quantity'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['edit'] = ['Edit', 'Edit item quantity ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['delete'] = ['Delete', 'Delete item quantity ID %s'];
