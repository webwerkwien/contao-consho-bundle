<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_product']['title'] = ['Name', 'Name of the product.'];
$GLOBALS['TL_LANG']['tl_consho_product']['alias'] = ['Alias', 'Unique address for calling up the product.'];
$GLOBALS['TL_LANG']['tl_consho_product']['sku'] = ['SKU', "The shop's own number."];
$GLOBALS['TL_LANG']['tl_consho_product']['gtin'] = ['GTIN / EAN', 'Identifies THIS article worldwide. Every variant has its own — it is not inherited from the main product. Spaces and hyphens may be typed along; the check digit is recomputed.'];
$GLOBALS['TL_LANG']['tl_consho_product']['mpn'] = ['Manufacturer part number', 'The number THE MANUFACTURER lists the article under. Per article as well, not inherited.'];
$GLOBALS['TL_LANG']['tl_consho_product']['minimumAge'] = ['Age restriction', 'The age from which the article may be handed over. Affects checkout and delivery later on.'];

$GLOBALS['TL_LANG']['tl_consho_product']['minimumAges'] = [
    0 => 'No age restriction',
    6 => 'from 6 years',
    12 => 'from 12 years',
    14 => 'from 14 years',
    16 => 'from 16 years',
    18 => 'from 18 years',
];
$GLOBALS['TL_LANG']['tl_consho_product']['productType'] = ['Product type', 'Determines the available properties and custom fields. The kind (physical, digital, service, gift voucher) always comes from the main product\'s type: a variant with a type of another kind stays what the main product is.'];
$GLOBALS['TL_LANG']['tl_consho_product']['pid'] = ['Main product', 'Leave empty for a product in its own right. Pick one and this record becomes a variant of it — description, images and page then come from the main product.'];
$GLOBALS['TL_LANG']['tl_consho_product']['shops'] = ['Shops', 'Which shops this product is visible in. The catalogue is shared; visibility is set per shop here. On a variant, nothing ticked means "same as the main product". Ticking narrows — a variant can never reach further than its main product.'];
$GLOBALS['TL_LANG']['tl_consho_product']['taxClass'] = ['Tax class', 'The rate follows from class, delivery country and order date — it is not stored on the product.'];
$GLOBALS['TL_LANG']['tl_consho_product']['manufacturer'] = ['Manufacturer', 'Mandatory for physical goods. Leaving it empty on a variant means: the same as the main product.'];
$GLOBALS['TL_LANG']['tl_consho_product']['safetyWarnings'] = ['Warnings', 'Hazards and warnings, e.g. "Contains alcohol" or "Not for children under 3". Shown with the goods themselves, not in a tab.'];
$GLOBALS['TL_LANG']['tl_consho_product']['safetyInformation'] = ['Safety information', 'Notes on safe use, storage and disposal.'];
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityYears'] = ['Manufacturer\'s durability guarantee (years)', 'Only if the <strong>manufacturer</strong> gives a commercial durability guarantee at no extra cost for the whole product that runs <strong>longer than two years</strong>, and has told you so (Art. 6(1)(la) Directive 2011/83/EU). The GARAN label is then mandatory. Whole or half years (e.g. 2,5). Empty or 0: no such guarantee. Applies to all variants.'];
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityLabel'] = ['GARAN label', 'The completed label (duration, manufacturer, model identifier) as PNG or JPG, in colour. EU Commission template: "EU GARAN product label" at commission.europa.eu. Shown on the product page, before the buy button and in the order confirmation.'];
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityNested'] = ['Nested GARAN label', 'Optional: the Commission\'s compact version with only the years ("XX") filled in (PNG, JPG or SVG). Shown on the tiles of product listings; a click opens the full label. Only together with the full label.'];
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityYearsTooShort'] = 'A durability guarantee carries the GARAN label only if it runs longer than two years. For a shorter one, enter 0.';
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityYearsInvalid'] = 'Enter the duration in whole or half years, e.g. 3 or 2,5 — the GARAN label allows no other decimals.';
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityMainOnly'] = 'The durability guarantee is set at the main product; its variants inherit it.';
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityLabelNotPublic'] = 'The folder of the GARAN label is not public — the image would not show in the shop. Set the folder to "public" in the file manager or move the label to a public folder.';
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityNotGoods'] ='The GARAN label applies to goods only — not to files, services or gift vouchers.';
$GLOBALS['TL_LANG']['tl_consho_product']['teaser'] = ['Short description', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['metaDescription'] = ['Meta description', 'The text shown in search results. Leave empty to use the short description, shortened to about 160 characters. Only takes effect on the main product, not on variants.'];
$GLOBALS['TL_LANG']['tl_consho_product']['description'] = ['Description', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['images'] = ['Images', 'From the Contao file manager. Consho does not create image folders of its own. Drag to reorder — the first image appears in listings, in the cart and in search results.'];
$GLOBALS['TL_LANG']['tl_consho_product']['weight'] = ['Weight (kg)', 'Product weight without packaging.'];
$GLOBALS['TL_LANG']['tl_consho_product']['shippingWeight'] = ['Shipping weight (kg)', 'Including packaging. Leave empty when identical to the product weight.'];
$GLOBALS['TL_LANG']['tl_consho_product']['length'] = ['Length (cm)', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['width'] = ['Width (cm)', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['height'] = ['Height (cm)', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['unit'] = ['Unit of measure', 'Basis for the base price required by price indication rules.'];
$GLOBALS['TL_LANG']['tl_consho_product']['purchaseUnit'] = ['Content', 'How much of the unit one sales item contains, e.g. 0.25 for 250 ml.'];
$GLOBALS['TL_LANG']['tl_consho_product']['referenceUnit'] = ['Reference quantity', 'Quantity the base price refers to, e.g. 1 for "per litre".'];
$GLOBALS['TL_LANG']['tl_consho_product']['minPurchase'] = ['Minimum purchase', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['purchaseSteps'] = ['Purchase steps', 'For goods sold by length, e.g. 0.1 to allow selection in 10 cm increments.'];
$GLOBALS['TL_LANG']['tl_consho_product']['published'] = ['Published', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['start'] = ['Show from', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['stop'] = ['Show until', ''];

$GLOBALS['TL_LANG']['tl_consho_product']['product_legend'] = 'Product';
$GLOBALS['TL_LANG']['tl_consho_product']['identification_legend'] = 'Identification';
$GLOBALS['TL_LANG']['tl_consho_product']['sales_legend'] = 'Sales';
$GLOBALS['TL_LANG']['tl_consho_product']['description_legend'] = 'Description and images';
$GLOBALS['TL_LANG']['tl_consho_product']['safety_legend'] = 'Safety and guarantee';
$GLOBALS['TL_LANG']['tl_consho_product']['shipping_legend'] = 'Shipping';
$GLOBALS['TL_LANG']['tl_consho_product']['publish_legend'] = 'Publication';

$GLOBALS['TL_LANG']['tl_consho_product']['units'] = [
    'kg' => 'Kilogramme',
    'g' => 'Gram',
    'l' => 'Litre',
    'ml' => 'Millilitre',
    'm' => 'Metre',
    'cm' => 'Centimetre',
    'm2' => 'Square metre',
    'stk' => 'Piece',
];

$GLOBALS['TL_LANG']['tl_consho_product']['new'] = ['New product', 'Create a product'];
$GLOBALS['TL_LANG']['tl_consho_product']['prices'] = ['Prices', 'Edit the prices of product ID %s'];
$GLOBALS['TL_LANG']['tl_consho_product']['edit'] = ['Edit', 'Edit product ID %s'];
$GLOBALS['TL_LANG']['tl_consho_product']['copy'] = ['Duplicate', 'Duplicate product ID %s'];
$GLOBALS['TL_LANG']['tl_consho_product']['delete'] = ['Delete', 'Delete product ID %s'];
$GLOBALS['TL_LANG']['tl_consho_product']['toggle'] = ['Publish', 'Publish or hide product ID %s'];
$GLOBALS['TL_LANG']['tl_consho_product']['show'] = ['Details', 'Show details of product ID %s'];

$GLOBALS['TL_LANG']['tl_consho_product']['properties'] = ['Properties', 'What the article <strong>is</strong> — "organic", "herbal bitters". This is what customers filter by and what an editor builds category pages from. Several values of one group act as OR in a filter.'];
$GLOBALS['TL_LANG']['tl_consho_product']['variantOptions'] = ['Variant axes', 'What makes <strong>this row</strong> a particular variant — "250 ml". Meaningful on variants, not on the main product. A filter on a variant axis finds the main product as soon as one visible variant matches.'];

// F-4: digital delivery.
$GLOBALS['TL_LANG']['tl_consho_product']['digital_legend'] = 'Delivery and validity';
$GLOBALS['TL_LANG']['tl_consho_product']['digitalDelivery'] = ['Delivery', 'How the customer receives a digital good. Only for a product type of the kind "digital". Empty: the entitlement alone, without a file or key. A variant without its own setting takes the main product\'s.'];
$GLOBALS['TL_LANG']['tl_consho_product']['voucherValue'] = ['Value', 'What a voucher of this article is worth. Usually its price; empty: what was paid for it.'];
$GLOBALS['TL_LANG']['tl_consho_product']['voucherValidMonths'] = ['Validity (months)', '0: unlimited. Limiting it towards consumers needs a reason that holds (Austrian Supreme Court, 7 Ob 22/12d).'];
$GLOBALS['TL_LANG']['tl_consho_product']['entitlementMonths'] = ['Term (months)', 'How long the entitlement lasts from its release. 0: permanent. What stops at the end — updates only, or use — is the product\'s business, not Consho\'s.'];
$GLOBALS['TL_LANG']['tl_consho_product']['digitalFile'] = ['File', 'The file for the delivery "File". Only from a protected folder — in a public one anybody guessing its address could fetch it. A new version here goes to everyone with an active entitlement.'];
$GLOBALS['TL_LANG']['tl_consho_product']['digitalDeliveries'] = ['file' => 'File'];
$GLOBALS['TL_LANG']['tl_consho_product']['digitalFilePublic'] = 'The file is in the public folder "%s", where anybody could fetch it. Please choose a file from a protected folder.';
$GLOBALS['TL_LANG']['tl_consho_product']['keyImport'] = ['Add keys', 'For the delivery "Key": one code per line. Every piece sold gets one. Codes already in this article\'s pool are skipped. The pool is shown by the "Keys" button in the article list.'];
$GLOBALS['TL_LANG']['tl_consho_product']['customFieldValues'] = ['Custom fields', 'The details the product type provides for — vintage, grape variety. Not filterable; to filter, create a property.'];
$GLOBALS['TL_LANG']['tl_consho_product']['custom_legend'] = 'Custom fields';
$GLOBALS['TL_LANG']['tl_consho_product']['customFieldInherited'] = 'Left empty, the main product\'s value applies: %s';
$GLOBALS['TL_LANG']['tl_consho_product']['customFieldInheritedNone'] = 'Left empty, the main product\'s value applies (empty there).';
$GLOBALS['TL_LANG']['tl_consho_product']['customFieldBoolean'] = ['1' => 'Yes', '0' => 'No'];
$GLOBALS['TL_LANG']['tl_consho_product']['variantDuplicate'] = 'The variant with ID %s already carries exactly these variant values. The customer cannot tell two equal variants of one main product apart — please choose other values.';
$GLOBALS['TL_LANG']['tl_consho_product']['kindUnused'] = '⚠ Not used: according to its product type the product is %s. Empty the field and it disappears from the mask.';
$GLOBALS['TL_LANG']['tl_consho_product']['kindUnusedKeys'] = '⚠ Not used: according to its product type the product is %s. Delete the keys in the pool under "Keys" in the article list and the field disappears.';
$GLOBALS['TL_LANG']['tl_consho_product']['keysFree'] = '%d free';
$GLOBALS['TL_LANG']['tl_consho_product']['digitalDeliveries']['key'] = 'Key from the pool';
$GLOBALS['TL_LANG']['tl_consho_product']['keys'] = ['Keys', 'Key pool of article ID %s'];

// Abo-1: sale modes.
$GLOBALS['TL_LANG']['tl_consho_product']['saleOnce'] = ['One-time sale', 'Paid once — permanently or for the term below. Always on for physical goods, whatever it says here.'];
$GLOBALS['TL_LANG']['tl_consho_product']['saleSubscription'] = ['Subscription', 'By subscription, under one or more subscription rules. The amounts are in the prices, each price row with its rule.'];
$GLOBALS['TL_LANG']['tl_consho_product']['subscriptionRules'] = ['Subscription rules', 'The models appear on the product page in this order. Rules are created under "Subscription rules".'];

// Block G: stock.
$GLOBALS['TL_LANG']['tl_consho_product']['stockTracked'] = ['Keep stock', 'Consho counts the stock of each article: the purchase books it out, a cancellation books it back, at 0 the article is "currently unavailable". Off: always available.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockBackorder'] = ['Sell without stock', 'Keep selling at 0, with the note "on order, longer delivery time". The stock then goes negative.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockWarning'] = ['Only a few from', 'Up to this stock the article reads "only a few left" and the back end warns. For keys the free codes less the open orders count. 0: only once nothing is left.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockQuantity'] = ['Stock', 'The stock of this article. What is booked is the change against the figure when the form opened — a sale in between is not lost.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockAvailableFrom'] = ['Available again from', 'Shown with an unavailable article. Empty: no date.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockInvalid'] = 'Please enter a number for the stock.';

// Block G (G-7): purchasing.
$GLOBALS['TL_LANG']['tl_consho_product']['stockTarget'] = ['Target stock', 'A purchase fills up to this. Back end only. 0: one pack per purchase.'];
$GLOBALS['TL_LANG']['tl_consho_product']['packUnit'] = ['Pack size (purchasing)', 'Pieces per pack at the supplier. Purchases count in whole packs. 1: single pieces. Not the customer\'s purchase quantity.'];
$GLOBALS['TL_LANG']['tl_consho_product']['supplier'] = ['Supplier', 'Typing offers the suppliers already in use. Case and spaces are matched to a name in use.'];
$GLOBALS['TL_LANG']['tl_consho_product']['supplierSku'] = ['Supplier item number', 'The number the supplier lists exactly this article under. Shown on the purchase list.'];
