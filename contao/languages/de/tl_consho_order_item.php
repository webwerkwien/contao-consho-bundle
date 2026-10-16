<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_order_item']['lineType'] = ['Zeilenart', 'Produkt, Versand, Zahlungsgebühr, Zahlungsnachlass oder Rabatt (Warenkorbregel).'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['productName'] = ['Bezeichnung', 'Wie das Produkt zum Zeitpunkt der Bestellung hieß.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['sku'] = ['Artikelnummer', ''];
$GLOBALS['TL_LANG']['tl_consho_order_item']['quantity'] = ['Menge', ''];
$GLOBALS['TL_LANG']['tl_consho_order_item']['unitPrice'] = ['Einzelpreis', 'Eingefroren. Eine spätere Preisänderung berührt diese Bestellung nicht.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['taxRate'] = ['Steuersatz', 'In Prozent, eingefroren zum Bestelldatum.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['taxCountry'] = ['Steuerland', 'Das Land, dessen Steuersatz gilt: bei Dienstleistungen das Land des Shops, sonst das Lieferland — innerhalb der EU an Privatkunden nur mit „Steuer im Bestimmungsland", ohne es das Land des Shops.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['destinationCountry'] = ['Bestimmungsland', 'Wohin die Leistung ging (Liefer- bzw. Rechnungsland beim Kauf). Zählt für die Lieferschwelle des One-Stop-Shops.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['taxAmount'] = ['Steuerbetrag', ''];
$GLOBALS['TL_LANG']['tl_consho_order_item']['lineTotal'] = ['Zeilensumme', ''];
$GLOBALS['TL_LANG']['tl_consho_order_item']['priceSource'] = ['Preisherkunft', 'Woher der Preis stammt — Liste, Staffel, Kundengruppe, Aktion, personalisiert oder Reverse Charge.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['customerGroup'] = ['Kundengruppe', 'Die Gruppe, aus der der Preis dieser Zeile stammt. Leer bei einem Listenpreis. Ein Mitglied kann mehreren Gruppen angehören, und Zeilen können aus verschiedenen stammen.'];

$GLOBALS['TL_LANG']['tl_consho_order_item']['item_legend'] = 'Position';
$GLOBALS['TL_LANG']['tl_consho_order_item']['price_legend'] = 'Preis und Steuer';

$GLOBALS['TL_LANG']['tl_consho_order_item']['edit'] = ['Bearbeiten', 'Position ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['show'] = ['Details', 'Details der Position ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['kind'] = ['Art', 'Beim Kauf aus dem Produkttyp übernommen: körperliche Ware, digital oder Dienstleistung.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['subscriptionRule'] = ['Aboregel', 'Die Aboregel, in der gekauft wurde. Leer: Einmalkauf.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['subscriptionTerms'] = ['Abo-Bedingungen', 'Wie der Kunde sie beim Kauf gelesen hat, eingefroren. Eine spätere Änderung der Aboregel berührt diese Bestellung nicht.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['performedAt'] = ['Leistung erbracht am', 'Gesetzt mit „Leistung erbracht". Hat der Kunde dem vorzeitigen Beginn zugestimmt, erlischt damit das Widerrufsrecht für diese Position.'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['performed'] = ['Leistung erbracht', 'Position ID %s als vollständig erbracht melden'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['performedConfirm'] = 'Leistung als vollständig erbracht melden? Das lässt sich nicht zurücknehmen. Hat der Kunde dem vorzeitigen Beginn zugestimmt, erlischt damit sein Widerrufsrecht für diese Position.';
$GLOBALS['TL_LANG']['tl_consho_order_item']['performedResult'] = [
    'performed' => 'Leistung „%s" als erbracht gemeldet.',
    'notService' => 'Nur eine Dienstleistung kann als erbracht gemeldet werden.',
    'notReleased' => 'Die Leistung ist noch nicht freigeschaltet.',
    'subscription' => 'Ein Abo läuft weiter — es wird nicht als erbracht gemeldet.',
    'alreadyPerformed' => 'Die Leistung ist bereits als erbracht gemeldet.',
    'withdrawn' => 'Die Leistung ist widerrufen und gesperrt.',
];
$GLOBALS['TL_LANG']['tl_consho_order_item']['kinds'] = ['physical' => 'körperliche Ware', 'digital' => 'digital', 'service' => 'Dienstleistung', 'voucher' => 'Wertgutschein'];
$GLOBALS['TL_LANG']['tl_consho_order_item']['performedOn'] = 'erbracht am %s';
