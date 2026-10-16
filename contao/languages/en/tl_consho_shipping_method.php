<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_shipping_method']['title'] = ['Title', 'How the shipping method appears at checkout and on the invoice.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['type'] = ['Type', 'The code behind the shipping method. A shipping module brings further types.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['types'] = ['weight_rate' => 'Weight brackets', 'flat_rate' => 'Flat rate'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['description'] = ['Description', 'Shown at checkout below the title, e.g. the delivery time.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['taxMode'] = ['Tax on shipping', 'Proportional: the tax is split over the rates in the cart by the value of the goods — a book and a mug give two shipping lines. Fixed: shipping is always taxed with this method\'s tax class.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['taxModes'] = ['proportional' => 'proportional to the goods', 'fixed' => 'fixed tax class'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['taxClass'] = ['Tax class', 'With "fixed", the tax on shipping. With "proportional", it only turns a shipping amount entered as gross into net — a cart taxed at a different rate then pays a different gross shipping than entered, less for reduced-rate goods with the standard rate as reference.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['cashOnDelivery'] = ['Cash on delivery possible', 'Tick if the parcel service of this shipping method collects cash on delivery. Only then is the payment method "cash on delivery" offered.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['countries'] = ['Delivers to', 'Empty: every delivery country of the shop. Different prices for the EU and elsewhere: two shipping methods. The checkout offers only countries at least one shipping method delivers to.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['maxLength'] = ['Longest side at most (cm)', 'Per piece. 0: no limit. One article beyond it, and the method is not offered.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['maxGirth'] = ['Girth at most (cm)', 'Length + 2 × width + 2 × height, the longest side as the length, per piece. 0: no limit.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['maxPieceWeight'] = ['Weight per piece at most (kg)', 'Shipping weight of one piece. 0: no limit. For bulky goods create a shipping method of its own.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['published'] = ['Published', 'Only published shipping methods are offered at checkout, even if a shop has selected them.'];

$GLOBALS['TL_LANG']['tl_consho_shipping_method']['title_legend'] = 'Title and type';
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['tax_legend'] = 'VAT';
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['cod_legend'] = 'Cash on delivery';
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['rules_legend'] = 'Countries and limits';
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['publish_legend'] = 'Publishing';

$GLOBALS['TL_LANG']['tl_consho_shipping_method']['new'] = ['New shipping method', 'Create a shipping method'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['prices'] = ['Amounts', 'Edit the amounts of shipping method ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['edit'] = ['Edit', 'Edit shipping method ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['copy'] = ['Duplicate', 'Duplicate shipping method ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['delete'] = ['Delete', 'Delete shipping method ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['toggle'] = ['Publish', 'Publish or hide shipping method ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['show'] = ['Details', 'Show the details of shipping method ID %s'];
