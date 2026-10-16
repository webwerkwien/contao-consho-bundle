<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_shipping_method']['title'] = ['Bezeichnung', 'So erscheint die Versandart im Checkout und auf der Rechnung.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['type'] = ['Typ', 'Der Code hinter der Versandart. Ein Versandmodul bringt weitere Typen mit.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['types'] = ['weight_rate' => 'Gewichtsstaffel', 'flat_rate' => 'Versandpauschale'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['description'] = ['Beschreibung', 'Erscheint im Checkout unter der Bezeichnung, etwa die Lieferzeit.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['taxMode'] = ['Steuer auf den Versand', 'Anteilig: Die Steuer teilt sich nach dem Warenwert auf die Steuersätze im Warenkorb auf — Buch und Tasse ergeben zwei Versandzeilen. Fest: Der Versand wird immer mit der Steuerklasse dieser Versandart versteuert.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['taxModes'] = ['proportional' => 'anteilig nach Warenwert', 'fixed' => 'feste Steuerklasse'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['taxClass'] = ['Steuerklasse', 'Bei „fest" die Steuer des Versands. Bei „anteilig" rechnet sie nur einen brutto eingegebenen Versandbetrag in netto um — ein Warenkorb mit anderem Steuersatz zahlt dann einen anderen Versand brutto als eingegeben, mit dem Normalsatz als Bezug bei ermäßigt besteuerter Ware weniger.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['cashOnDelivery'] = ['Nachnahme möglich', 'Ankreuzen, wenn der Paketdienst dieser Versandart per Nachnahme kassiert. Nur dann wird die Zahlungsart „Nachnahme" angeboten.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['countries'] = ['Liefert nach', 'Leer: in alle Lieferländer des Shops. Unterschiedliche Preise für EU und Drittland: zwei Versandarten. Der Checkout bietet nur Länder an, in die mindestens eine Versandart liefert.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['maxLength'] = ['Längste Seite höchstens (cm)', 'Je Stück. 0: ohne Grenze. Ein Artikel darüber — und die Versandart wird nicht angeboten.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['maxGirth'] = ['Gurtmaß höchstens (cm)', 'Länge + 2 × Breite + 2 × Höhe, die längste Seite als Länge, je Stück. 0: ohne Grenze.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['maxPieceWeight'] = ['Gewicht je Stück höchstens (kg)', 'Versandgewicht eines Stücks. 0: ohne Grenze. Für Sperrgut eine eigene Versandart anlegen.'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['published'] = ['Veröffentlicht', 'Nur veröffentlichte Versandarten werden im Checkout angeboten, auch wenn ein Shop sie ausgewählt hat.'];

$GLOBALS['TL_LANG']['tl_consho_shipping_method']['title_legend'] = 'Bezeichnung und Typ';
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['tax_legend'] = 'Umsatzsteuer';
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['cod_legend'] = 'Nachnahme';
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['rules_legend'] = 'Länder und Grenzen';
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['publish_legend'] = 'Veröffentlichung';

$GLOBALS['TL_LANG']['tl_consho_shipping_method']['new'] = ['Neue Versandart', 'Versandart anlegen'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['prices'] = ['Beträge', 'Beträge der Versandart ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['edit'] = ['Bearbeiten', 'Versandart ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['copy'] = ['Duplizieren', 'Versandart ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['delete'] = ['Löschen', 'Versandart ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['toggle'] = ['Veröffentlichen', 'Versandart ID %s veröffentlichen oder verbergen'];
$GLOBALS['TL_LANG']['tl_consho_shipping_method']['show'] = ['Details', 'Details der Versandart ID %s anzeigen'];
