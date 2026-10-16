<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_shipping_price']['currency'] = ['Currency', 'Only currencies a shop sells in. One amount per currency, for weight brackets one per currency and bracket.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['inputCountry'] = ['Country', 'A gross amount is converted into net with the rate of this home country. It has to match the currency.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['inputMode'] = ['Enter as', 'Net is always what is stored. A gross amount is converted with the tax class of the shipping method.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['inputModes'] = ['gross' => 'gross', 'net' => 'net'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['amount'] = ['Amount', 'Calculated from net on opening, with today\'s rate. With proportional shipping tax a cart taxed at a different rate pays a different gross than entered here, less for reduced-rate goods with the standard rate as reference.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['net'] = ['Net', 'Amount without tax, four decimal places.'];

$GLOBALS['TL_LANG']['tl_consho_shipping_price']['maxWeight'] = ['Up to weight (kg)', 'Upper limit of this bracket, the shipping weight of all articles together. The smallest bracket the parcel fits applies; above the highest the method is not offered.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['rowLabel'] = '%s %s net';
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['bracketLabel'] = '%s — up to %s kg: %s net';
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['price_legend'] = 'Amount';

$GLOBALS['TL_LANG']['tl_consho_shipping_price']['new'] = ['New amount', 'Create an amount in a currency'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['edit'] = ['Edit', 'Edit amount ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['copy'] = ['Duplicate', 'Duplicate amount ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['delete'] = ['Delete', 'Delete amount ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['show'] = ['Details', 'Show the details of amount ID %s'];
