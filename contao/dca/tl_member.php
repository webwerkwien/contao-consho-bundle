<?php declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

/*
 * Consho does not add an address table.
 *
 * Contao's existing member fields ARE the billing address; a second field set
 * holds the delivery address. Most customers never need more than two. Orders
 * carry a frozen COPY of both — an address on an order is a historical fact,
 * not a reference, or a customer moving house would rewrite last year's
 * invoice. See docs/block-a-fundament.md, section 2.
 *
 * Guest orders need no member at all: the address lives on the order anyway.
 *
 * Pickup stations are deliberately absent. A DHL Packstation needs a post
 * number and a station number and no street; the Austrian Post uses other
 * fields again. Which fields apply depends on the carrier, so it belongs to
 * the shipping method — block E.
 */
PaletteManipulator::create()
    ->addLegend('consho_shipping_legend', 'address_legend', PaletteManipulator::POSITION_AFTER)
    ->addField(
        [
            'consho_shippingCompany',
            'consho_shippingSalutation',
            'consho_shippingFirstname',
            'consho_shippingLastname',
            'consho_shippingStreet',
            'consho_shippingPostal',
            'consho_shippingCity',
            'consho_shippingState',
            'consho_shippingCountry',
        ],
        'consho_shipping_legend',
        PaletteManipulator::POSITION_APPEND,
    )
    ->addLegend('consho_tax_legend', 'consho_shipping_legend', PaletteManipulator::POSITION_AFTER)
    ->addField(
        ['consho_vatId', 'consho_vatIdStatus', 'consho_vatIdCheckedAt', 'consho_vatIdRequestIdentifier'],
        'consho_tax_legend',
        PaletteManipulator::POSITION_APPEND,
    )
    ->applyToPalette('default', 'tl_member')
;

$conshoShippingFields = [
    'consho_shippingCompany' => ['maxlength' => 255, 'class' => 'w50'],
    'consho_shippingSalutation' => ['maxlength' => 128, 'class' => 'w50'],
    'consho_shippingFirstname' => ['maxlength' => 255, 'class' => 'w50'],
    'consho_shippingLastname' => ['maxlength' => 255, 'class' => 'w50'],
    'consho_shippingStreet' => ['maxlength' => 255, 'class' => 'clr'],
    'consho_shippingPostal' => ['maxlength' => 32, 'class' => 'w50'],
    'consho_shippingCity' => ['maxlength' => 255, 'class' => 'w50'],
    'consho_shippingState' => ['maxlength' => 64, 'class' => 'w50'],
];

foreach ($conshoShippingFields as $name => $config) {
    $GLOBALS['TL_DCA']['tl_member']['fields'][$name] = [
        'inputType' => 'text',
        'exclude' => true,
        'eval' => ['maxlength' => $config['maxlength'], 'tl_class' => $config['class']],
        'sql' => ['type' => 'string', 'length' => $config['maxlength'], 'default' => ''],
    ];
}

unset($conshoShippingFields);

// Options come from ShopCountryOptions, the same source the tax rates and the
// shop whitelist use — one canonical list, ISO 3166-1 alpha-2 in upper case.
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_shippingCountry'] = [
    'inputType' => 'select',
    'exclude' => true,
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
];

$GLOBALS['TL_DCA']['tl_member']['fields']['consho_vatId'] = [
    'inputType' => 'text',
    'exclude' => true,
    'eval' => ['maxlength' => 32, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
];

/*
 * The next three are written by the check, never by hand — hence `readonly`.
 *
 * `consho_vatIdRequestIdentifier` is not decoration: VIES issues it as the
 * evidence of the query. In a tax audit it is what shows the VAT ID really was
 * verified at the time the invoice was written.
 */
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_vatIdStatus'] = [
    'inputType' => 'select',
    'exclude' => true,
    'options' => ['valid', 'invalid', 'unavailable'],
    'reference' => &$GLOBALS['TL_LANG']['tl_member']['consho_vatIdStatuses'],
    'eval' => ['includeBlankOption' => true, 'readonly' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
];

$GLOBALS['TL_DCA']['tl_member']['fields']['consho_vatIdCheckedAt'] = [
    'inputType' => 'text',
    'exclude' => true,
    'eval' => ['rgxp' => 'datim', 'readonly' => true, 'tl_class' => 'w50'],

    /*
     * NULL-able rather than `default 0`, for the third time in this bundle:
     * with a default of 0 the field reads 01.01.1970 instead of "never
     * checked", and the back end submits that date back into an unsigned
     * column. See tl_consho_tax_rate.
     */
    'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
];

$GLOBALS['TL_DCA']['tl_member']['fields']['consho_vatIdRequestIdentifier'] = [
    'inputType' => 'text',
    'exclude' => true,
    'eval' => ['readonly' => true, 'tl_class' => 'clr'],
    'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
];

/*
 * The address the member confirmed by mail (D-4, K3), in lower case — for
 * "Meine Bestellungen" to show guest orders under it (MemberOrders).
 *
 * The ADDRESS, not a flag: Contao's "Persönliche Daten" changes the e-mail
 * without a check, and a flag would go on vouching for the old one. Stored
 * only, in no palette — nobody edits a proof.
 */
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_confirmedEmail'] = [
    'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
];

$GLOBALS['TL_DCA']['tl_member']['fields']['consho_confirmedEmailAt'] = [
    'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
];
