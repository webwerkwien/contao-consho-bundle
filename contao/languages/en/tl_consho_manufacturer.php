<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_manufacturer']['title'] = ['Name', 'Name of the manufacturer as it should appear with the product.'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['logo'] = ['Logo', 'Optional. Shown with the manufacturer block on the product page.'];

$GLOBALS['TL_LANG']['tl_consho_manufacturer']['street'] = ['Street', 'Mandatory. The address must be complete enough for a letter to arrive.'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['postal'] = ['Postcode', 'Mandatory.'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['city'] = ['City', 'Mandatory.'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['state'] = ['State / region', 'Optional, where it is part of the address.'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['country'] = ['Country', 'Where the manufacturer is based. Not limited to the delivery countries — a manufacturer may sit anywhere.'];

$GLOBALS['TL_LANG']['tl_consho_manufacturer']['email'] = ['E-mail address', 'Mandatory under the GPSR. An address the manufacturer can actually be reached at.'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['contactUrl'] = ['Contact page', 'Optional, in addition to the e-mail address. Must allow direct communication — a contact form, not an overview page.'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['website'] = ['Website', 'Informational only. Does NOT discharge the duty to provide contact details.'];

$GLOBALS['TL_LANG']['tl_consho_manufacturer']['euResponsible'] = ['Responsible person in the EU', 'Only needed when the manufacturer is based outside the EU. Almost always a company: usually the importer — whoever brings the goods in from the third country themselves is it themselves. Otherwise the wholesaler, an authorised representative or a fulfilment service provider in the EU. Create them as a manufacturer too, with a company name and an address that takes service; only entries based in the EU are offered.'];

$GLOBALS['TL_LANG']['tl_consho_manufacturer']['title_legend'] = 'Manufacturer';
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['address_legend'] = 'Address';
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['contact_legend'] = 'Electronic address';
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['eu_legend'] = 'Responsible person in the EU';

$GLOBALS['TL_LANG']['tl_consho_manufacturer']['new'] = ['New manufacturer', 'Create a manufacturer'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['edit'] = ['Edit', 'Edit manufacturer ID %s'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['copy'] = ['Duplicate', 'Duplicate manufacturer ID %s'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['delete'] = ['Delete', 'Delete manufacturer ID %s'];
$GLOBALS['TL_LANG']['tl_consho_manufacturer']['show'] = ['Details', 'Show details of manufacturer ID %s'];

$GLOBALS['TL_LANG']['tl_consho_manufacturer']['alias'] = ['Alias', 'Address segment, used when the product address contains the manufacturer. Leave empty to derive it from the name. It does NOT follow a later correction of the name - that is the point of it.'];
