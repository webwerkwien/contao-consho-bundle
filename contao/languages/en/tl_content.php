<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_content']['consho_filter_legend'] = 'Which products';
$GLOBALS['TL_LANG']['tl_content']['consho_display_legend'] = 'Presentation';
$GLOBALS['TL_LANG']['tl_content']['consho_panel_legend'] = 'Filter controls';

$GLOBALS['TL_LANG']['tl_content']['conshoFilter'] = [
    'Product selection',
    'Which property values a product must carry to appear on this page — this is the <strong>category</strong>. Several values of the <em>same</em> group act as OR, values of <em>different</em> groups as AND. Leave empty to show the whole range. A visitor can <strong>narrow this selection but never switch it off</strong>.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoFollowsAddress'] = [
    'Follows the address',
    'The listing takes its filters from the address (the <code>cq</code> parameter and landing pages) and answers "page not found" when the selection matches nothing. <strong>On</strong> for the category listing. <strong>Off</strong> for an additional listing on the same page, such as "new this week" — it always shows its own selection and can never set the page to "not found".',
];

$GLOBALS['TL_LANG']['tl_content']['conshoShowCounts'] = [
    'Counts beside the filters',
    'Shows how many products each filter value would leave. A group\'s numbers are counted without that group\'s own filter — otherwise "blue" would read zero as soon as "red" is chosen. <strong>Without counts</strong> they are still calculated, only not shown: values without a matching product still do not appear, or appear without a link.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoListElement'] = [
    'Product list',
    'Which listing the filter works on. <strong>Leaving it empty is the normal case:</strong> the first listing in the current page\'s articles that follows the address is used. That way one filter in a layout section serves every category page. Only a listing that stands in a layout section itself has to be chosen here.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoGroups'] = [
    'Offered filters',
    'Which property groups the filter shows, in this order. Leave empty to show every filterable group. Only groups marked as filterable and with a valid alias can be chosen.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoTileSize'] = [
    'Tile image size',
    'Applies to this listing. The same product appears elsewhere at another size, which is why the size sits on the element and not on the product.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoGallerySize'] = [
    'Product image size',
    'Applies to the images on the product page. The same product appears in a listing at another size, which is why the size sits on the element and not on the product.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoTileTemplate'] = [
    'Tile template',
    'Offered are the templates below templates/content_element/consho_product_tile/. Leave empty for the bundled tile. A template that exists only in a theme is not listed here.',
];

$GLOBALS['TL_LANG']['tl_content']['consho_pin_legend'] = 'Pinned product';

$GLOBALS['TL_LANG']['tl_content']['conshoProduct'] = [
    'Product',
    'This product stands at the start of the listing, in the order of the pins. It appears only if it is published, in this shop and priced — and if it matches a filter the visitor has chosen. It does not have to match the product selection of the listing.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoPinsOnly'] = [
    'Pins only',
    'The listing shows the pinned products and nothing else, in the dragged order — for recommendations or cross-selling. Unticked, every product of the product selection follows the pins.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoPinsExtra'] = [
    'Pins on top of page 1',
    'With pagination: unticked, the pins take places, every page holds the same number of products, and more pins than places continue on page 2. Ticked, all pins come on top of page 1. When the visitor sorts, the pins give way.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoUnavailableLast'] = ['Unavailable last', 'Puts products that are currently unavailable after all others — before any chosen order. "On backorder" counts as available. With a filter chosen, the matching variant counts: under "red" a shirt whose red variant is sold out goes last.'];
$GLOBALS['TL_LANG']['tl_content']['conshoAvailableFilter'] = ['Offer "available only"', 'Shows the tick box "Available only" above the filter groups. It hides what is currently unavailable; "on backorder" stays. With a variant chosen (say "red"), exactly that variant has to be available.'];
$GLOBALS['TL_LANG']['tl_content']['conshoTilePurchase'] = ['Buy button on the tile', 'Shows an "add to basket" button under every tile. Only for products without variants — those need a choice first and keep linking to their detail page. The minimum quantity is what gets added; any other quantity is chosen on the detail page or in the basket.'];
