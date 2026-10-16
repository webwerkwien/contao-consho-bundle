<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_tax_rate']['country'] = ['Country', 'The delivery country this rate applies to.'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['rate'] = ['Rate in percent', 'For example 20 for 20%. Decimals are allowed, such as 13.5. Where a country has no reduced rate — Denmark only knows 25% — enter the standard rate here. Without it, an order from that country fails at the tax calculation.'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['validFrom'] = ['Valid from', 'Leave empty for "always".'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['validTo'] = ['Valid until', 'The rate applies up to and including this day — enter the date as the law states it. Leave empty for "until further notice". Temporary rates need this field — without it, every past invoice would retroactively show the new rate.'];

$GLOBALS['TL_LANG']['tl_consho_tax_rate']['rate_legend'] = 'Rate';
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['validity_legend'] = 'Validity';

$GLOBALS['TL_LANG']['tl_consho_tax_rate']['new'] = ['New rate', 'Create a tax rate'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['edit'] = ['Edit', 'Edit rate ID %s'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['copy'] = ['Duplicate', 'Duplicate rate ID %s'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['delete'] = ['Delete', 'Delete rate ID %s'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['show'] = ['Details', 'Show details of rate ID %s'];

$GLOBALS['TL_LANG']['tl_consho_tax_rate']['errors']['duplicate'] = 'This tax class already has a rate for %s with the same "valid from". Which one applies would be left to chance — edit the existing rate or choose another date.';
