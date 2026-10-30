<?php declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

/*
 * Consho does not add an address table.
 *
 * Contao's existing member fields ARE the billing address; a second field set
 * holds the delivery address. Most customers never need more than two. Orders
 * carry a frozen COPY of both — an address on an order is a historical fact,
 * not a reference, or a customer moving house would rewrite last year's
 * invoice. See Block A, section 2.
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
        ['consho_vatId', 'consho_vatIdStatus', 'consho_vatIdCheckedAt', 'consho_vatIdRequestIdentifier', 'consho_vatIdRequester', 'consho_netGroupsAutoAt'],
        'consho_tax_legend',
        PaletteManipulator::POSITION_APPEND,
    )
    ->applyToPalette('default', 'tl_member')
;

$conshoShippingFields = [
    'consho_shippingCompany' => ['maxlength' => 255, 'class' => 'w50'],
    'consho_shippingFirstname' => ['maxlength' => 255, 'class' => 'w50 clr'],
    'consho_shippingLastname' => ['maxlength' => 255, 'class' => 'w50'],
    'consho_shippingStreet' => ['maxlength' => 255, 'class' => 'clr'],
    'consho_shippingPostal' => ['maxlength' => 32, 'class' => 'w50'],
    'consho_shippingCity' => ['maxlength' => 255, 'class' => 'w50'],
    'consho_shippingState' => ['maxlength' => 64, 'class' => 'w50'],
];

/*
 * feEditable: the member keeps the delivery address and the VAT ID in Contao's
 * "Persönliche Daten" (and on registration) like the billing address — the
 * operator picks the fields in the module. The checkout prefills from here and
 * checks the VAT ID with VIES on every purchase (CheckoutController), so a VAT
 * ID changed by the member carries no stale result along.
 */
foreach ($conshoShippingFields as $name => $config) {
    $GLOBALS['TL_DCA']['tl_member']['fields'][$name] = [
        'inputType' => 'text',
        'exclude' => true,
        'eval' => ['maxlength' => $config['maxlength'], 'feEditable' => true, 'feViewable' => true, 'feGroup' => 'consho_shipping', 'tl_class' => $config['class']],
        'sql' => ['type' => 'string', 'length' => $config['maxlength'], 'default' => ''],
    ];
}

unset($conshoShippingFields);

/*
 * No salutation in the delivery address: the checkout stopped asking for it
 * (C-10) and an order has no column for it. Stored only, in no
 * palette, so what members entered before stays in access and erasure.
 */
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_shippingSalutation'] = [
    'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
];

// Options come from ShopCountryOptions, the same source the tax rates and the
// shop whitelist use — one canonical list, ISO 3166-1 alpha-2 in upper case.
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_shippingCountry'] = [
    'inputType' => 'select',
    'exclude' => true,
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'feEditable' => true, 'feViewable' => true, 'feGroup' => 'consho_shipping', 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
];

$GLOBALS['TL_DCA']['tl_member']['fields']['consho_vatId'] = [
    'inputType' => 'text',
    'exclude' => true,
    'eval' => ['maxlength' => 32, 'feEditable' => true, 'feViewable' => true, 'feGroup' => 'consho_tax', 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
];

/*
 * The next three are written by the check, never by hand — hence `readonly`,
 * and `disabled` for the status: a browser ignores readonly on a select.
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
    'eval' => ['includeBlankOption' => true, 'disabled' => true, 'tl_class' => 'w50'],
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
    'eval' => ['readonly' => true, 'tl_class' => 'w50 clr'],
    'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
];

/*
 * The shop VAT ID the check named as requester. VIES issues the consultation
 * number for THAT business: a result is only reused for a shop with the same
 * VAT ID, or one company's invoice would carry another company's evidence
 * (MemberVatId).
 */
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_vatIdRequester'] = [
    'inputType' => 'text',
    'exclude' => true,
    'eval' => ['readonly' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
];

/*
 * The VAT ID the stored result was asked for, normalized. The member may
 * change consho_vatId at any time — in "Persönliche Daten", in the back end,
 * by import — and the result only holds for the ID it was given for.
 */
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_vatIdChecked'] = [
    'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
];

/*
 * The net price groups a verified VAT ID added (NetGroupAssigner), and when.
 * Only these count as automatic: a group the operator gave by hand is never
 * listed, and saving the member in the back end confirms the listed ones as
 * the operator's own — the list is emptied then (MemberVatIdBackendListener).
 * While it holds a group, a VAT ID gone invalid or removed shows on the
 * dashboard (NetGroupVatIdIssueSource); nothing takes the group away.
 */
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_netGroupsAuto'] = [
    'sql' => ['type' => 'blob', 'notnull' => false],
];

$GLOBALS['TL_DCA']['tl_member']['fields']['consho_netGroupsAutoAt'] = [
    'inputType' => 'text',
    'exclude' => true,
    'eval' => ['rgxp' => 'datim', 'readonly' => true, 'tl_class' => 'w50 clr'],
    // NULL-able for the reason given at consho_vatIdCheckedAt.
    'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
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

/*
 * An account created from a guest order and not yet opened (K1): when it was
 * created, 0 for every other account. Setting the password opens it
 * (AccountFromOrderActivation); an account never opened goes after the
 * password link has expired (AccountFromOrderCron). Stored only, in no
 * palette.
 */
$GLOBALS['TL_DCA']['tl_member']['fields']['consho_accountPending'] = [
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];
