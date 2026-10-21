<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_delivery']['shippedAt'] = ['Shipped on', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['carrier'] = ['Carrier', 'e.g. Post AT, DPD, GLS.'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['trackingNumber'] = ['Tracking number', ''];

$GLOBALS['TL_LANG']['tl_consho_delivery']['delivery_legend'] = 'Delivery';

$GLOBALS['TL_LANG']['tl_consho_delivery']['new'] = ['New delivery', 'Record a delivery'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['items'] = ['Contents', 'Edit the contents of delivery ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['edit'] = ['Edit', 'Edit delivery ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['delete'] = ['Delete', 'Delete delivery ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['show'] = ['Details', 'Show details of delivery ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['report'] = ['Report as shipped', 'Report delivery ID %s as shipped: the customer gets a mail with the contents of the parcel, and payment methods that invoice on shipment issue the invoice, and a shop with a delivery note number range issues the delivery note. The delivery is locked afterwards.'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['deliveryNote'] = ['Delivery note', 'Open the delivery note of delivery ID %s as PDF'];

$GLOBALS['TL_LANG']['tl_consho_delivery']['notReported'] = 'not reported yet';
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportedOn'] = 'reported on %s';
$GLOBALS['TL_LANG']['tl_consho_delivery']['locked'] = 'This delivery is reported as shipped and locked: the customer has already had the mail about this parcel.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportResult'] = [
    'reported' => 'The delivery of order %s is reported; the customer has had the shipping mail.',
    'reportedNoMail' => 'The delivery of order %s is reported. The shipping mail did not go out — the reason is in the system log.',
    'tooMuch' => 'Not reported: these items exceed what is still open: %s.',
    'empty' => 'Not reported: the delivery carries no item with a quantity.',
    'alreadyReported' => 'This delivery is already reported.',
];

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['orderItem'] = ['Item', 'Which item of this order travelled.'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['quantity'] = ['Quantity', 'How much of it went with this delivery. Partial deliveries follow from this by themselves.'];

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['item_legend'] = 'Item quantity';

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['new'] = ['New item quantity', 'Record an item quantity'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['edit'] = ['Edit', 'Edit item quantity ID %s'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['delete'] = ['Delete', 'Delete item quantity ID %s'];
