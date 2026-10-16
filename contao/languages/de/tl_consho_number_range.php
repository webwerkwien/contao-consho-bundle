<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_number_range']['type'] = ['Art', 'Wofür dieser Zähler gilt. Je Shop und Art genau einer.'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['prefix'] = ['Präfix', 'Wird der Nummer vorangestellt, z. B. „RE-".'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['padding'] = ['Mindestlänge', 'Mit Nullen aufgefüllt, z. B. 5 für 00042. Wird die Nummer länger, bleibt sie länger.'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['current'] = ['Zuletzt vergeben', 'Die nächste Nummer ist diese plus eins. Beim Umstieg aus einem anderen System hier den letzten Stand eintragen.'];

$GLOBALS['TL_LANG']['tl_consho_number_range']['range_legend'] = 'Nummernkreis';

$GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['issued'] = 'Die Nummer %s ist bereits vergeben. Der Zähler darf nicht darunter liegen, sonst entstünde eine Nummer doppelt. Für einen Neubeginn bei 0 ein neues Präfix wählen, z. B. mit der Jahreszahl.';
$GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['shared'] = 'Das Präfix „%s" verwendet schon der Nummernkreis für %s. Zwei Belegarten mit einem Präfix vergeben früher oder später dieselbe Nummer — und eine Gutschrift oder ein Storno ist steuerlich auch eine Rechnung. Ein eigenes Präfix wählen, z. B. „GS-".';

$GLOBALS['TL_LANG']['tl_consho_number_range']['types'] = [
    'order' => 'Bestellnummer (Lücken erlaubt)',
    'invoice' => 'Rechnungsnummer (lückenlos)',
    'deliveryNote' => 'Lieferscheinnummer',
    'creditNote' => 'Rechnungskorrektur-Nummer (lückenlos)',
    'cancellation' => 'Stornonummer (lückenlos)',
];

$GLOBALS['TL_LANG']['tl_consho_number_range']['new'] = ['Neuer Nummernkreis', 'Nummernkreis anlegen'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['edit'] = ['Bearbeiten', 'Nummernkreis ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_number_range']['show'] = ['Details', 'Details des Nummernkreises ID %s anzeigen'];
