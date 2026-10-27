<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_purchase_list']['list_legend'] = 'Einkaufsliste';
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['number'] = ['Nummer', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['createdAt'] = ['Erstellt', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['user'] = ['Benutzer', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['suppliers'] = ['Lieferanten', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['lineCount'] = ['Positionen', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['state'] = ['Stand', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['states'] = ['open' => 'offen', 'partial' => 'teilweise', 'done' => 'erledigt'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['items'] = ['Positionen', 'Positionen der Einkaufsliste ID %s'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['receive'] = ['Eingang', 'Eingang der Einkaufsliste ID %s bestätigen'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['output'] = ['Ausgeben', 'Einkaufsliste ID %s erneut ausgeben'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['show'] = ['Details', 'Details der Einkaufsliste ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['purchaseOutput'] = ['Ausgeben', 'Einkaufsliste erneut ausgeben'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['purchaseReceive'] = ['Eingang', 'Eingang der Einkaufsliste bestätigen'];
// array_replace, not +=: Contao loads English first as the fallback, and +=
// keeps every key that is already there (G-8).
$GLOBALS['TL_LANG']['tl_consho_purchase_list'] = array_replace($GLOBALS['TL_LANG']['tl_consho_purchase_list'] ?? [], [
    'back' => 'Zurück',
    'colTake' => 'Übernehmen',
    'colArticle' => 'Artikel',
    'colSupplier' => 'Lieferant',
    'colSupplierSku' => 'Bestellnummer Lieferant',
    'colSku' => 'Artikelnummer',
    'colStock' => 'Bestand',
    'colOrdered' => 'bestellt',
    'colWarning' => 'Meldebestand',
    'colTarget' => 'Sollbestand',
    'colPieces' => 'Stück',
    'colPackUnit' => 'VPE',
    'colPacks' => 'Packungen',
    'colOrderedPieces' => 'bestellt',
    'colReceived' => 'erhalten',
    'colReceiveNow' => 'jetzt erhalten',
    'colUnavailable' => 'Rest nicht lieferbar',
    'perPack' => '%s je VPE',
    'noArticles' => 'Kein Artikel führt Bestand. „Bestand führen" am Produkt einschalten.',
    'outputHint' => 'Jede der drei Ausgaben vermerkt die Liste unter „Einkaufslisten"; die Artikel gelten dann als bestellt.',
    'toClipboard' => 'Zwischenablage',
    'toPrint' => 'Druckansicht',
    'toCsv' => 'CSV-Export',
    'printTitle' => 'Einkaufsliste',
    'saved' => 'Die Einkaufsliste %s ist gespeichert.',
    'copy' => 'In die Zwischenablage kopieren',
    'copied' => 'Kopiert.',
    'toLists' => 'Zu den Einkaufslisten',
    'receiveHint' => 'Vorbelegt ist, was noch offen ist. „Rest nicht lieferbar" schließt die Zeile; der Artikel erscheint im nächsten Einkauf wieder.',
    'received' => 'Eingang für %s gebucht.',
    'invalidQuantity' => 'Bitte gültige Mengen eingeben — Packungen ganzzahlig, nie mehr als noch offen.',
    'invalidItem' => 'Eine Position gehört nicht zu dieser Liste.',
    'receiveButton' => 'Eingang buchen',
    'unavailableWithRest' => 'Ist „Rest nicht lieferbar" angehakt, darf nicht zugleich der ganze Rest als erhalten eingetragen sein. Eintragen, was kam (auch 0), und das Häkchen setzen.',
    'noStock' => 'Ein Artikel der Liste führt keinen Bestand mehr; sein Eingang kann nicht gebucht werden.',
    'nothingToOrder' => 'Es ist nichts ausgewählt.',
]);
