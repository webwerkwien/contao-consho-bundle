<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_member']['consho_shipping_legend'] = 'Lieferadresse';
$GLOBALS['TL_LANG']['tl_member']['consho_tax_legend'] = 'Umsatzsteuer';

// Group headings in "Persönliche Daten" and the registration (feGroup + "Details").
$GLOBALS['TL_LANG']['tl_member']['consho_shippingDetails'] = 'Lieferadresse';
$GLOBALS['TL_LANG']['tl_member']['consho_taxDetails'] = 'Umsatzsteuer';

// "(Lieferadresse)" in every label: the field list of "Persönliche Daten" and the
// registration shows Contao's own address fields beside these, by label only.
$GLOBALS['TL_LANG']['tl_member']['consho_shippingCompany'] = ['Firma (Lieferadresse)', 'Leer lassen, wenn die Lieferadresse der Rechnungsadresse entspricht.'];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingSalutation'] = ['Anrede (Lieferadresse)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingFirstname'] = ['Vorname (Lieferadresse)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingLastname'] = ['Nachname (Lieferadresse)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingStreet'] = ['Straße und Hausnummer (Lieferadresse)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingPostal'] = ['PLZ (Lieferadresse)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingCity'] = ['Ort (Lieferadresse)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingState'] = ['Bundesland (Lieferadresse)', ''];
$GLOBALS['TL_LANG']['tl_member']['consho_shippingCountry'] = ['Land (Lieferadresse)', ''];

$GLOBALS['TL_LANG']['tl_member']['consho_vatId'] = ['UID-Nummer', 'Umsatzsteuer-Identifikationsnummer inklusive Ländercode, z. B. ATU12345678.'];
$GLOBALS['TL_LANG']['tl_member']['consho_vatIdStatus'] = ['Prüfergebnis', 'Ergebnis der letzten VIES-Abfrage. Wird vom System gesetzt.'];
$GLOBALS['TL_LANG']['tl_member']['consho_vatIdCheckedAt'] = ['Zuletzt geprüft', 'Leer bedeutet: noch nie geprüft.'];
$GLOBALS['TL_LANG']['tl_member']['consho_vatIdRequestIdentifier'] = ['VIES-Nachweiskennung', 'Beleg der Abfrage für eine Betriebsprüfung.'];
$GLOBALS['TL_LANG']['tl_member']['consho_vatIdRequester'] = ['Geprüft mit UID', 'Die UID des Shops, der angefragt hat. Die Nachweiskennung gilt nur für diesen.'];
$GLOBALS['TL_LANG']['tl_member']['consho_netGroupsAutoAt'] = ['Nettopreis-Gruppen automatisch vergeben', 'Wann eine geprüfte UID das Mitglied in Nettopreis-Gruppen eingetragen hat. Speichern im Backend bestätigt diese Gruppen als von Hand vergeben; das Feld wird dann geleert.'];

$GLOBALS['TL_LANG']['tl_member']['consho_vatIdStatuses'] = [
    'valid' => 'gültig',
    'invalid' => 'ungültig',
    'unavailable' => 'nicht prüfbar',
];
