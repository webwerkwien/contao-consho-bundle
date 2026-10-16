<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_order_status']['title'] = ['Bezeichnung', 'Wie dieser Status im Backend heißt. Frei wählbar — das System liest nur die Haken darunter, nie den Namen.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['colour'] = ['Signal in der Liste', 'Womit dieser Status in der Bestellübersicht angezeigt wird. Die Bezeichnung oben ist frei, deshalb muss das Signal gesetzt werden — Consho kann nicht wissen, ob „In Klärung" ein Problem ist oder Alltag.'];

$GLOBALS['TL_LANG']['tl_consho_order_status']['colours'] = [
    'open' => 'Offen (grau)',
    'progress' => 'In Arbeit (orange)',
    'attention' => 'Braucht Aufmerksamkeit (rot)',
    'done' => 'Abgeschlossen (grün)',
];

$GLOBALS['TL_LANG']['tl_consho_order_status']['isPaid'] = ['Gilt als bezahlt', 'Nur setzbar, wenn die Zahlung erledigt ist: nichts mehr offen und nichts zu erstatten. Sperrt den Wechsel der Zahlungsart.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['isCancelled'] = ['Gilt als storniert', 'Nur setzbar, solange nichts versandt ist — danach ist es ein Widerruf oder eine Retoure. Die Bestellung schuldet dann nichts mehr; bereits Gezahltes ist zu erstatten.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['isShipped'] = ['Gilt als versendet', 'Nur setzbar, wenn alle Waren als versandt gemeldet sind.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['isCompleted'] = ['Schließt die Bestellung ab', 'Nur setzbar, wenn Zahlung und Lieferung erledigt sind — bezahlt und geliefert, oder storniert, nichts versandt und Gezahltes erstattet.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['locksChanges'] = ['Sperrt Änderungen', 'Ab diesem Status ist die Bestellung eingefroren.'];

$GLOBALS['TL_LANG']['tl_consho_order_status']['title_legend'] = 'Bezeichnung';
$GLOBALS['TL_LANG']['tl_consho_order_status']['flag_legend'] = 'Systemverhalten';

$GLOBALS['TL_LANG']['tl_consho_order_status']['new'] = ['Neuer Status', 'Status anlegen'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['edit'] = ['Bearbeiten', 'Status ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['copy'] = ['Duplizieren', 'Status ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['delete'] = ['Löschen', 'Status ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['show'] = ['Details', 'Details des Status ID %s anzeigen'];
