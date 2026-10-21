<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_document']['documentNumber'] = ['Belegnummer', 'Lückenlos je Shop und Belegart.'];
$GLOBALS['TL_LANG']['tl_consho_document']['type'] = ['Belegart', ''];
$GLOBALS['TL_LANG']['tl_consho_document']['issuedAt'] = ['Ausgestellt am', ''];
$GLOBALS['TL_LANG']['tl_consho_document']['relatedDocument'] = ['Bezieht sich auf', 'Bei Rechnungskorrektur und Storno der Beleg, der korrigiert wird.'];
$GLOBALS['TL_LANG']['tl_consho_document']['payload'] = ['Inhalt', 'Momentaufnahme zum Zeitpunkt der Ausstellung. Bewusst unveränderlich.'];

$GLOBALS['TL_LANG']['tl_consho_document']['types'] = [
    'invoice' => 'Rechnung',
    'deliveryNote' => 'Lieferschein',
    'creditNote' => 'Rechnungskorrektur',
    'cancellation' => 'Stornorechnung',
];

$GLOBALS['TL_LANG']['tl_consho_document']['show'] = ['Details', 'Details des Belegs ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_consho_document']['invoicePdf'] = ['PDF', 'Beleg ID %s als PDF öffnen'];
$GLOBALS['TL_LANG']['tl_consho_document']['invoiceHtml'] = ['Barrierefreie Fassung', 'Beleg ID %s als HTML öffnen'];

$GLOBALS['TL_LANG']['tl_consho_document']['archiveProblem'] = 'Beleg %s: Die abgelegte Datei fehlt oder stimmt nicht mehr mit ihrer Prüfsumme überein. Ausgeliefert wurde ein neu erzeugtes PDF, auf dem das vermerkt ist.';
$GLOBALS['TL_LANG']['tl_consho_document']['logoMissing'] = 'Beleg %s: Das Logo, das beim Ausstellen galt, gibt es nicht mehr in dieser Form (gelöscht oder ausgetauscht). Die Rechnung wurde ohne Logo erzeugt.';
