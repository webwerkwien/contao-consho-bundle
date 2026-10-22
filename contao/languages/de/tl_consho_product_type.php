<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_product_type']['title'] = ['Bezeichnung', 'Name des Produkttyps, z. B. „Farbe" oder „Gewebe".'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['kind'] = ['Art', 'Ware wird versandt und fällt unter die EU-Produktsicherheitsverordnung. Digitales: Datei, Schlüssel oder Zugang, versteuert im Land des Kunden. Dienstleistung: ohne Auslieferung, versteuert im Land des Shops; ob eine Leistung elektronisch erbracht ist (dann Digitales), entscheiden Sie. Die Art wird beim Kauf in die Bestellung übernommen.'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['kinds'] = ['physical' => 'Ware', 'digital' => 'Digitales', 'service' => 'Dienstleistung', 'voucher' => 'Wertgutschein'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['propertyGroups'] = ['Eigenschaftsgruppen', 'Welche Eigenschaften Produkte dieses Typs haben können. Nach Eigenschaften kann gefiltert werden.'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['customFields'] = ['Zusatzfelder', 'Frei typisierte Felder für Angaben, nach denen NICHT gefiltert wird.'];

$GLOBALS['TL_LANG']['tl_consho_product_type']['detailPartial'] = ['Teilvorlage auf der Produktseite', 'Zeigt die Angaben, die nur dieser Typ hat — etwa Jahrgang und Rebsorte bei Wein. Zur Auswahl stehen die Vorlagen unter templates/consho_product_type/. Leer lassen, wenn der Typ keine eigenen Angaben zeigt. Eine Vorlage, die nur in einem Theme liegt, erscheint hier nicht.'];

$GLOBALS['TL_LANG']['tl_consho_product_type']['title_legend'] = 'Bezeichnung';
$GLOBALS['TL_LANG']['tl_consho_product_type']['template_legend'] = 'Produktseite';
$GLOBALS['TL_LANG']['tl_consho_product_type']['property_legend'] = 'Eigenschaften';
$GLOBALS['TL_LANG']['tl_consho_product_type']['custom_legend'] = 'Zusatzfelder';

$GLOBALS['TL_LANG']['tl_consho_product_type']['new'] = ['Neuer Produkttyp', 'Produkttyp anlegen'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['edit'] = ['Bearbeiten', 'Produkttyp ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['copy'] = ['Duplizieren', 'Produkttyp ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['delete'] = ['Löschen', 'Produkttyp ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_product_type']['show'] = ['Details', 'Details des Produkttyps ID %s anzeigen'];

$GLOBALS['TL_LANG']['tl_consho_product_type']['alias'] = ['Alias', 'Adressbestandteil, falls die Produktadresse den Produkttyp enthält ({type} in der Adressvorlage). Leer lassen erzeugt ihn aus dem Namen. Er ändert sich <strong>nicht</strong> mit, wenn der Name später korrigiert wird — genau das ist sein Zweck.'];
