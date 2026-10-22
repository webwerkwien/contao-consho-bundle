<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_product_type']['title'] = ['Name', 'Name of the product type, e.g. "Paint" or "Fabric".'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['kind'] = ['Kind', 'Goods are shipped and fall under the EU product safety regulation. Digital content: file, key or access, taxed in the customer\'s country. Service: nothing is delivered, taxed in the shop\'s country; whether a service is supplied electronically (then digital content) is your decision. The kind is copied into the order at purchase.'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['kinds'] = ['physical' => 'Goods', 'digital' => 'Digital content', 'service' => 'Service', 'voucher' => 'Gift voucher'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['propertyGroups'] = ['Property groups', 'Which properties products of this type may carry. Properties are filterable.'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['customFields'] = ['Custom fields', 'Freely typed fields for data that is NOT filtered on.'];

$GLOBALS['TL_LANG']['tl_consho_product_type']['detailPartial'] = ['Partial on the product page', 'Shows what only this type has — vintage and grape for a wine, for instance. Offered are the templates below templates/consho_product_type/. Leave empty if the type shows nothing of its own. A template that exists only in a theme is not listed here.'];

$GLOBALS['TL_LANG']['tl_consho_product_type']['title_legend'] = 'Name';
$GLOBALS['TL_LANG']['tl_consho_product_type']['template_legend'] = 'Product page';
$GLOBALS['TL_LANG']['tl_consho_product_type']['property_legend'] = 'Properties';
$GLOBALS['TL_LANG']['tl_consho_product_type']['custom_legend'] = 'Custom fields';

$GLOBALS['TL_LANG']['tl_consho_product_type']['new'] = ['New product type', 'Create a product type'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['edit'] = ['Edit', 'Edit product type ID %s'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['copy'] = ['Duplicate', 'Duplicate product type ID %s'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['delete'] = ['Delete', 'Delete product type ID %s'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['show'] = ['Details', 'Show details of product type ID %s'];

$GLOBALS['TL_LANG']['tl_consho_product_type']['alias'] = ['Alias', 'Address segment, used when the product address contains the product type. Leave empty to derive it from the name. It does NOT follow a later correction of the name - that is the point of it.'];
