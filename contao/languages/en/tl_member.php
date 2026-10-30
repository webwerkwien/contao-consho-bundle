<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_member']['consho_shipping_legend'] = 'Delivery address';
$GLOBALS['TL_LANG']['tl_member']['consho_tax_legend'] = 'VAT';

// Group headings in "Personal data" and the registration (feGroup + "Details").
$GLOBALS['TL_LANG']['tl_member']['consho_shippingDetails'] = 'Delivery address';
$GLOBALS['TL_LANG']['tl_member']['consho_taxDetails'] = 'VAT';

// "(delivery address)" in every label: the field list of "Personal data" and the
// registration shows Contao's own address fields beside these, by label only.
$GLOBALS['TL_LANG']['tl_member']['consho_shippingCompany'] = ['Company (delivery address)', 'Leave empty if the delivery address is the billing address.'];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingSalutation'] = ['Salutation (delivery address)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingFirstname'] = ['First name (delivery address)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingLastname'] = ['Last name (delivery address)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingStreet'] = ['Street and number (delivery address)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingPostal'] = ['Postal code (delivery address)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingCity'] = ['City (delivery address)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingState'] = ['State (delivery address)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingCountry'] = ['Country (delivery address)', ''];

$GLOBALS['TL_LANG']['tl_member']['consho_vatId'] = ['VAT ID', 'VAT identification number including country code, e.g. ATU12345678.'];
$GLOBALS['TL_LANG']['tl_member']['consho_vatIdStatus'] = ['Check result', 'Result of the last VIES query. Set by the system.'];
$GLOBALS['TL_LANG']['tl_member']['consho_vatIdCheckedAt'] = ['Last checked', 'Empty means never checked.'];
$GLOBALS['TL_LANG']['tl_member']['consho_vatIdRequestIdentifier'] = ['VIES consultation number', 'Evidence of the query for a tax audit.'];
$GLOBALS['TL_LANG']['tl_member']['consho_vatIdRequester'] = ['Checked with VAT ID', 'The VAT ID of the shop that asked. The consultation number only holds for that one.'];
$GLOBALS['TL_LANG']['tl_member']['consho_netGroupsAutoAt'] = ['Net price groups assigned automatically', 'When a verified VAT ID added the member to net price groups. Saving in the back end confirms these groups as assigned by hand; the field is then emptied.'];

$GLOBALS['TL_LANG']['tl_member']['consho_vatIdStatuses'] = [
    'valid' => 'valid',
    'invalid' => 'invalid',
    'unavailable' => 'not verifiable',
];
