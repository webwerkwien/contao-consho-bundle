<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_price']['currency'] = ['Währung', 'Nur Währungen, in denen ein Shop verkauft.'];
$GLOBALS['TL_LANG']['tl_consho_price']['inputCountry'] = ['Land', 'Mit dem Steuersatz dieses Heimatlands wird ein Bruttobetrag in netto umgerechnet. Es muss zur Währung passen.'];
$GLOBALS['TL_LANG']['tl_consho_price']['inputMode'] = ['Eingabe als', 'Gespeichert wird immer netto. Einen Bruttobetrag rechnet die Maske beim Speichern mit dem Steuersatz der Steuerklasse des Produkts um. Umschalten rechnet nicht um: die Zahl im Feld „Betrag" gilt danach als das Gewählte.'];
$GLOBALS['TL_LANG']['tl_consho_price']['inputModes'] = ['gross' => 'brutto', 'net' => 'netto'];
$GLOBALS['TL_LANG']['tl_consho_price']['amount'] = ['Betrag', 'Beim Öffnen aus netto gerechnet, mit dem Steuersatz zum Beginn des Preises, frühestens heute. Fehlt der Steuersatz, die Steuerklasse oder das Land, bleibt das Feld leer.'];
$GLOBALS['TL_LANG']['tl_consho_price']['net'] = ['Netto', 'Preis ohne Steuer, vier Nachkommastellen.'];
$GLOBALS['TL_LANG']['tl_consho_price']['customerGroup'] = ['Kundengruppe', 'Leer lassen, damit der Preis für alle gilt.'];
$GLOBALS['TL_LANG']['tl_consho_price']['minQuantity'] = ['Ab Menge', 'Ab welcher Menge dieser Preis gilt. 1 für den Grundfall.'];
$GLOBALS['TL_LANG']['tl_consho_price']['validFrom'] = ['Gültig ab', 'Leer lassen für „schon immer".'];
$GLOBALS['TL_LANG']['tl_consho_price']['validTo'] = ['Gültig bis', 'Ab diesem Zeitpunkt gilt der Preis nicht mehr — wie „Anzeigen bis" bei Inhaltselementen. Leer lassen für „bis auf Weiteres". Für befristete Aktionspreise ist dieses Feld der Grund, warum eine alte Rechnung nachvollziehbar bleibt.'];

$GLOBALS['TL_LANG']['tl_consho_price']['subscriptionRule'] = ['Verkauft als', 'Einmalkauf oder eine der Aboregeln, die am Produkt angehakt sind. Beim Abo ist der Betrag der je Zahlung — beim Monatsabo der Monatsbetrag.'];
$GLOBALS['TL_LANG']['tl_consho_price']['oneTimeSale'] = 'Einmalkauf';
$GLOBALS['TL_LANG']['tl_consho_price']['ruleNotOffered'] = 'Das Produkt wird so nicht angeboten. Am Produkt unter „Auslieferung und Gültigkeit" die Verkaufsart oder Aboregel anhaken.';

$GLOBALS['TL_LANG']['tl_consho_price']['price_legend'] = 'Preis';
$GLOBALS['TL_LANG']['tl_consho_price']['scope_legend'] = 'Geltungsbereich';
$GLOBALS['TL_LANG']['tl_consho_price']['validity_legend'] = 'Gültigkeit';

$GLOBALS['TL_LANG']['tl_consho_price']['new'] = ['Neuer Preis', 'Preis anlegen'];
$GLOBALS['TL_LANG']['tl_consho_price']['edit'] = ['Bearbeiten', 'Preis ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_price']['copy'] = ['Duplizieren', 'Preis ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_price']['delete'] = ['Löschen', 'Preis ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_price']['show'] = ['Details', 'Details des Preises ID %s anzeigen'];
