<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_tax_rate']['country'] = ['Land', 'Für welches Lieferland dieser Satz gilt.'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['rate'] = ['Satz in Prozent', 'Zum Beispiel 20 für 20 %. Nachkommastellen sind erlaubt, etwa 13,5. Hat ein Land keinen ermäßigten Satz — Dänemark etwa kennt nur 25 % —, dann tragen Sie hier den Normalsatz ein. Fehlt der Satz, scheitert eine Bestellung aus diesem Land an der Steuerberechnung.'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['validFrom'] = ['Gültig ab', 'Leer lassen für „schon immer".'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['validTo'] = ['Gültig bis', 'Der Satz gilt bis einschließlich dieses Tages — tragen Sie das Datum ein, wie es im Gesetz steht. Leer lassen für „bis auf Weiteres". Befristete Sätze wie die deutsche Senkung 2020 brauchen dieses Feld — ohne es zeigte jede alte Rechnung nachträglich den neuen Satz.'];

$GLOBALS['TL_LANG']['tl_consho_tax_rate']['rate_legend'] = 'Satz';
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['validity_legend'] = 'Gültigkeit';

$GLOBALS['TL_LANG']['tl_consho_tax_rate']['new'] = ['Neuer Satz', 'Steuersatz anlegen'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['edit'] = ['Bearbeiten', 'Satz ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['copy'] = ['Duplizieren', 'Satz ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['delete'] = ['Löschen', 'Satz ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_tax_rate']['show'] = ['Details', 'Details des Satzes ID %s anzeigen'];

$GLOBALS['TL_LANG']['tl_consho_tax_rate']['errors']['duplicate'] = 'Diese Steuerklasse hat für %s schon einen Satz mit demselben „Gültig ab". Welcher gilt, wäre dann Zufall — den vorhandenen bearbeiten oder ein anderes Datum wählen.';
