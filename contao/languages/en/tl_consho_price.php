<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_price']['currency'] = ['Currency', 'Only currencies a shop sells in.'];
$GLOBALS['TL_LANG']['tl_consho_price']['inputCountry'] = ['Country', 'The tax rate of this home country turns a gross amount into net. It has to fit the currency.'];
$GLOBALS['TL_LANG']['tl_consho_price']['inputMode'] = ['Enter as', 'Net is always what is stored. A gross amount is converted on save with the rate of the product\'s tax class. Switching does not convert: the number in "Amount" then counts as the chosen kind.'];
$GLOBALS['TL_LANG']['tl_consho_price']['inputModes'] = ['gross' => 'gross', 'net' => 'net'];
$GLOBALS['TL_LANG']['tl_consho_price']['amount'] = ['Amount', 'Shown from net on load, with the tax rate at the price\'s start, today at the earliest. Empty when the rate, the tax class or the country is missing.'];
$GLOBALS['TL_LANG']['tl_consho_price']['net'] = ['Net', 'Price without tax, four decimal places.'];
$GLOBALS['TL_LANG']['tl_consho_price']['customerGroup'] = ['Customer group', 'Leave empty to apply the price to everyone.'];
$GLOBALS['TL_LANG']['tl_consho_price']['minQuantity'] = ['From quantity', 'The quantity this price starts to apply at. 1 for the base case.'];
$GLOBALS['TL_LANG']['tl_consho_price']['validFrom'] = ['Valid from', 'Leave empty for "always".'];
$GLOBALS['TL_LANG']['tl_consho_price']['validTo'] = ['Valid until', 'From this moment on the price no longer applies — like "Show until" on content elements. Leave empty for "until further notice". For time-limited promotions this field is what keeps an old invoice reproducible.'];

$GLOBALS['TL_LANG']['tl_consho_price']['subscriptionRule'] = ['Sold as', 'One-time sale or one of the subscription rules ticked at the product. For a subscription the amount is per payment — the monthly amount for a monthly subscription.'];
$GLOBALS['TL_LANG']['tl_consho_price']['oneTimeSale'] = 'One-time sale';
$GLOBALS['TL_LANG']['tl_consho_price']['ruleNotOffered'] = 'The product is not offered this way. Tick the sale mode or rule at the product under "Delivery and validity".';

$GLOBALS['TL_LANG']['tl_consho_price']['price_legend'] = 'Price';
$GLOBALS['TL_LANG']['tl_consho_price']['scope_legend'] = 'Scope';
$GLOBALS['TL_LANG']['tl_consho_price']['validity_legend'] = 'Validity';

$GLOBALS['TL_LANG']['tl_consho_price']['new'] = ['New price', 'Create a price'];
$GLOBALS['TL_LANG']['tl_consho_price']['edit'] = ['Edit', 'Edit price ID %s'];
$GLOBALS['TL_LANG']['tl_consho_price']['copy'] = ['Duplicate', 'Duplicate price ID %s'];
$GLOBALS['TL_LANG']['tl_consho_price']['delete'] = ['Delete', 'Delete price ID %s'];
$GLOBALS['TL_LANG']['tl_consho_price']['show'] = ['Details', 'Show details of price ID %s'];
