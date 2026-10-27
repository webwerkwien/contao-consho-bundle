<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_document']['documentNumber'] = ['Document number', 'Unbroken per shop and document type.'];
$GLOBALS['TL_LANG']['tl_consho_document']['type'] = ['Document type', ''];
$GLOBALS['TL_LANG']['tl_consho_document']['issuer'] = ['Issued by', 'Consho, an accounting module or nobody ("Off": the invoice data only, the invoice is issued elsewhere).'];
$GLOBALS['TL_LANG']['tl_consho_document']['issuedAt'] = ['Issued at', ''];
$GLOBALS['TL_LANG']['tl_consho_document']['notIssued'] = 'Invoice data (not issued)';
$GLOBALS['TL_LANG']['tl_consho_document']['waitingFor'] = 'waiting for %s';
$GLOBALS['TL_LANG']['tl_consho_document']['handOverFailed'] = 'handover to %s failed';
$GLOBALS['TL_LANG']['tl_consho_document']['relatedDocument'] = ['Refers to', 'For invoice corrections and cancellations, the document being corrected.'];
$GLOBALS['TL_LANG']['tl_consho_document']['payload'] = ['Content', 'Snapshot taken when the document was issued. Deliberately immutable.'];

$GLOBALS['TL_LANG']['tl_consho_document']['types'] = [
    'invoice' => 'Invoice',
    'deliveryNote' => 'Delivery note',
    'creditNote' => 'Invoice correction',
    'cancellation' => 'Cancellation invoice',
];

$GLOBALS['TL_LANG']['tl_consho_document']['show'] = ['Details', 'Show details of document ID %s'];
$GLOBALS['TL_LANG']['tl_consho_document']['invoicePdf'] = ['PDF', 'Open document ID %s as PDF'];
$GLOBALS['TL_LANG']['tl_consho_document']['invoiceHtml'] = ['Accessible version', 'Open document ID %s as HTML'];

$GLOBALS['TL_LANG']['tl_consho_document']['archiveProblem'] = 'Document %s: the archived file is missing or no longer matches its checksum. A regenerated PDF was delivered, marked as such.';
$GLOBALS['TL_LANG']['tl_consho_document']['logoMissing'] = 'Document %s: the logo in force at issue no longer exists in that form (deleted or replaced). The invoice was rendered without a logo.';
