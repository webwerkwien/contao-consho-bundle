<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_shipping_price']['currency'] = ['Währung', 'Nur Währungen, in denen ein Shop verkauft. Je Währung ein Betrag, bei einer Gewichtsstaffel je Währung und Stufe.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['inputCountry'] = ['Land', 'Mit dem Steuersatz dieses Heimatlands wird ein Bruttobetrag in netto umgerechnet. Es muss zur Währung passen.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['inputMode'] = ['Eingabe als', 'Gespeichert wird immer netto. Einen Bruttobetrag rechnet die Maske mit der Steuerklasse der Versandart um.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['inputModes'] = ['gross' => 'brutto', 'net' => 'netto'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['amount'] = ['Betrag', 'Beim Öffnen aus netto gerechnet, mit dem heutigen Steuersatz. Bei anteiliger Versandsteuer zahlt ein Warenkorb mit anderem Steuersatz einen anderen Bruttobetrag als hier eingegeben, mit dem Normalsatz als Bezug bei ermäßigt besteuerter Ware weniger.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['net'] = ['Netto', 'Betrag ohne Steuer, vier Nachkommastellen.'];

$GLOBALS['TL_LANG']['tl_consho_shipping_price']['maxWeight'] = ['Bis Gewicht (kg)', 'Obergrenze dieser Stufe, Versandgewicht aller Artikel zusammen. Es gilt die kleinste Stufe, in die das Paket passt; über der höchsten wird die Versandart nicht angeboten.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['rowLabel'] = '%s %s netto';
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['bracketLabel'] = '%s — bis %s kg: %s netto';
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['price_legend'] = 'Betrag';

$GLOBALS['TL_LANG']['tl_consho_shipping_price']['new'] = ['Neuer Betrag', 'Betrag in einer Währung anlegen'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['edit'] = ['Bearbeiten', 'Betrag ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['copy'] = ['Duplizieren', 'Betrag ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['delete'] = ['Löschen', 'Betrag ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_shipping_price']['show'] = ['Details', 'Details des Betrags ID %s anzeigen'];
