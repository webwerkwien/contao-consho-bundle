<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_payment']['receivedAt'] = ['Received on', ''];
$GLOBALS['TL_LANG']['tl_consho_payment']['amount'] = ['Amount', 'A refund is recorded as a negative amount — which is why there is no separate refund record type.'];
$GLOBALS['TL_LANG']['tl_consho_payment']['method'] = ['Method', 'How it was paid, e.g. "Bank transfer" or "PayPal".'];
$GLOBALS['TL_LANG']['tl_consho_payment']['reference'] = ['Reference', 'Payment reference, the provider\'s transaction id or similar.'];

$GLOBALS['TL_LANG']['tl_consho_payment']['payment_legend'] = 'Payment';

$GLOBALS['TL_LANG']['tl_consho_payment']['new'] = ['New payment', 'Record a payment'];
$GLOBALS['TL_LANG']['tl_consho_payment']['edit'] = ['Edit', 'Edit payment ID %s'];
$GLOBALS['TL_LANG']['tl_consho_payment']['delete'] = ['Delete', 'Delete payment ID %s'];
$GLOBALS['TL_LANG']['tl_consho_payment']['show'] = ['Details', 'Show details of payment ID %s'];

$GLOBALS['TL_LANG']['tl_consho_payment']['locked'] = 'This payment has already had consequences (invoice, status or mail) and is locked. Record a correction as a new payment with a negative amount.';
