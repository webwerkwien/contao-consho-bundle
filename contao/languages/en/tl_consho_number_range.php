<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_number_range']['type'] = ['Type', 'What this counter is for. Exactly one per shop and type.'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['prefix'] = ['Prefix', 'Put in front of the number, e.g. "RE-".'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['padding'] = ['Minimum length', 'Padded with zeros, e.g. 5 for 00042. A longer number stays longer.'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['current'] = ['Last issued', 'The next number is this plus one. When migrating from another system, enter the last number it issued.'];

$GLOBALS['TL_LANG']['tl_consho_number_range']['range_legend'] = 'Number range';

$GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['issued'] = 'Number %s has already been issued. The counter must not be below it, or a number would exist twice. To start again at 0, choose a new prefix, e.g. with the year.';
$GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['shared'] = 'The prefix "%s" is already used by the number range for %s. Two document types on one prefix sooner or later issue the same number — and a credit note or a cancellation is an invoice for VAT too. Choose a prefix of its own, e.g. "CN-".';

$GLOBALS['TL_LANG']['tl_consho_number_range']['types'] = [
    'order' => 'Order number (gaps allowed)',
    'invoice' => 'Invoice number (unbroken)',
    'deliveryNote' => 'Delivery note number',
    'creditNote' => 'Invoice correction number (unbroken)',
    'cancellation' => 'Cancellation number (unbroken)',
];

$GLOBALS['TL_LANG']['tl_consho_number_range']['new'] = ['New number range', 'Create a number range'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['edit'] = ['Edit', 'Edit number range ID %s'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['show'] = ['Details', 'Show details of number range ID %s'];
