<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_order_item']['lineType'] = ['Line type', 'Product, shipping, payment fee, payment discount or discount (cart rule).'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['productName'] = ['Name', 'What the product was called at the time of the order.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['sku'] = ['Article number', ''];
$GLOBALS['TL_LANG']['tl_consho_order_item']['quantity'] = ['Quantity', ''];
$GLOBALS['TL_LANG']['tl_consho_order_item']['unitPrice'] = ['Unit price', 'Frozen. A later price change does not touch this order.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['taxRate'] = ['Tax rate', 'In percent, frozen at the order date.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['taxCountry'] = ['Tax country', 'The country whose rate applies: the shop\'s for services, the delivery country otherwise — within the EU to consumers only with "Tax at destination", the shop\'s without it.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['destinationCountry'] = ['Destination country', 'Where the supply went (delivery or billing country at the purchase). Counts towards the One-Stop-Shop threshold.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['taxAmount'] = ['Tax amount', ''];
$GLOBALS['TL_LANG']['tl_consho_order_item']['lineTotal'] = ['Line total', ''];
$GLOBALS['TL_LANG']['tl_consho_order_item']['priceSource'] = ['Price source', 'Where the price came from — list, scale, customer group, promotion, personalised or reverse charge.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['customerGroup'] = ['Customer group', 'The group this line\'s price came from. Empty for a list price. A member can hold several groups, and lines may come from different ones.'];

$GLOBALS['TL_LANG']['tl_consho_order_item']['item_legend'] = 'Item';
$GLOBALS['TL_LANG']['tl_consho_order_item']['price_legend'] = 'Price and tax';

$GLOBALS['TL_LANG']['tl_consho_order_item']['edit'] = ['Edit', 'Edit item ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['show'] = ['Details', 'Show details of item ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['kind'] = ['Kind', 'Taken from the product type at the purchase: goods, digital content, service or gift voucher.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['subscriptionRule'] = ['Subscription rule', 'The subscription rule the line was bought in. Empty: one-time purchase.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['subscriptionTerms'] = ['Subscription terms', 'As the customer read them at the purchase, frozen. A later change of the rule does not touch this order.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['performedAt'] = ['Service performed on', 'Set with "Service performed". If the customer agreed to the early start, this ends the right of withdrawal for this line.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['performed'] = ['Service performed', 'Report line ID %s as fully performed'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['performedConfirm'] = 'Report the service as fully performed? This cannot be undone. If the customer agreed to the early start, their right of withdrawal for this line ends.';
$GLOBALS['TL_LANG']['tl_consho_order_item']['performedResult'] = [
    'performed' => 'Service "%s" reported as performed.',
    'notService' => 'Only a service can be reported as performed.',
    'notReleased' => 'The service has not been released yet.',
    'subscription' => 'A subscription keeps running — it is not reported as performed.',
    'alreadyPerformed' => 'The service has already been reported as performed.',
    'withdrawn' => 'The service has been withdrawn and revoked.',
];
$GLOBALS['TL_LANG']['tl_consho_order_item']['kinds'] = ['physical' => 'Goods', 'digital' => 'Digital content', 'service' => 'Service', 'voucher' => 'Gift voucher'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['performedOn'] = 'performed on %s';
