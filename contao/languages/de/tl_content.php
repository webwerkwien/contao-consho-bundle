<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_content']['consho_filter_legend'] = 'Welche Produkte';
$GLOBALS['TL_LANG']['tl_content']['consho_display_legend'] = 'Darstellung';
$GLOBALS['TL_LANG']['tl_content']['consho_panel_legend'] = 'Filterbedienung';

$GLOBALS['TL_LANG']['tl_content']['conshoFilter'] = [
    'Produktauswahl',
    'Welche Eigenschaftswerte ein Produkt tragen muss, um auf dieser Seite zu erscheinen — das ist die <strong>Kategorie</strong>. Mehrere Werte <em>derselben</em> Gruppe wirken als ODER, Werte <em>verschiedener</em> Gruppen als UND. Leer lassen zeigt das ganze Sortiment. Ein Besucher kann diese Auswahl <strong>eingrenzen, aber nicht abwählen</strong>.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoFollowsAddress'] = [
    'Folgt der Adresse',
    'Die Liste übernimmt die Filter aus der Adresse (Parameter <code>cq</code> und Landingpages) und antwortet mit „Seite nicht gefunden", wenn die Auswahl nichts ergibt. <strong>An</strong> für die Kategorieliste. <strong>Aus</strong> für eine zusätzliche Liste auf derselben Seite, etwa „Neu diese Woche" — sie zeigt immer ihre eigene Auswahl und kann die Seite nie auf „nicht gefunden" setzen.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoShowCounts'] = [
    'Trefferzahlen an den Filtern',
    'Zeigt neben jedem Filterwert, wie viele Produkte er übrig ließe. Die Zahlen einer Gruppe werden ohne deren eigenen Filter gerechnet — sonst stünde neben „blau" eine Null, sobald „rot" gewählt ist. <strong>Ohne Zahlen</strong> wird trotzdem gerechnet, nur nicht angezeigt: Werte ohne passendes Produkt erscheinen weiterhin nicht oder ohne Link.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoListElement'] = [
    'Produktliste',
    'Auf welche Liste sich der Filter bezieht. <strong>Leer lassen ist der Normalfall:</strong> Dann gilt die erste Liste in den Artikeln der aktuellen Seite, die der Adresse folgt. So bedient ein Filter im Layout-Bereich alle Kategorieseiten. Wählen müssen Sie nur eine Liste, die selbst in einem Layout-Bereich steht.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoGroups'] = [
    'Angebotene Filter',
    'Welche Eigenschaftsgruppen der Filter zeigt, in dieser Reihenfolge. Leer lassen zeigt alle filterbaren Gruppen. Zur Auswahl stehen nur Gruppen, die als filterbar markiert sind und einen gültigen Alias haben.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoTileSize'] = [
    'Bildgröße der Kachel',
    'Gilt für diese Liste. Dasselbe Produkt erscheint an anderer Stelle in anderer Größe, deshalb steht die Größe am Element und nicht am Produkt.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoGallerySize'] = [
    'Bildgröße der Produktbilder',
    'Gilt für die Bilder auf der Produktseite. Dasselbe Produkt erscheint in der Liste in anderer Größe, deshalb steht die Größe am Element und nicht am Produkt.',
];

$GLOBALS['TL_LANG']['tl_content']['consho_pin_legend'] = 'Angeheftetes Produkt';

$GLOBALS['TL_LANG']['tl_content']['conshoProduct'] = [
    'Produkt',
    'Dieses Produkt steht am Anfang der Liste, in der Reihenfolge der Anheftungen. Es erscheint nur, wenn es veröffentlicht, in diesem Shop und mit Preis sichtbar ist — und wenn es zu einem Filter passt, den der Besucher gewählt hat. Die Produktauswahl der Liste muss es nicht erfüllen.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoPinsOnly'] = [
    'Nur Anheftungen',
    'Die Liste zeigt ausschließlich die angehefteten Produkte, in der gezogenen Reihenfolge — etwa für Empfehlungen oder Cross-Selling. Ohne Häkchen folgen nach den Anheftungen alle Produkte der Produktauswahl.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoPinsExtra'] = [
    'Anheftungen zusätzlich auf Seite 1',
    'Mit Blätterung: Ohne Häkchen belegen die Anheftungen Plätze, jede Seite hat gleich viele Produkte, und mehr Anheftungen als Plätze laufen auf Seite 2 weiter. Mit Häkchen stehen alle Anheftungen zusätzlich auf Seite 1. Sortiert der Besucher selbst, entfallen die Anheftungen.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoTileTemplate'] = [
    'Vorlage der Kachel',
    'Zur Auswahl stehen die Vorlagen unter templates/content_element/consho_product_tile/. Leer lassen für die mitgelieferte Kachel. Eine Vorlage, die nur in einem Theme liegt, erscheint hier nicht.',
];

$GLOBALS['TL_LANG']['tl_content']['conshoUnavailableLast'] = ['Nicht Verfügbares ans Ende', 'Stellt Produkte, die derzeit nicht verfügbar sind, hinter alle anderen — vor jeder gewählten Sortierung. „Auf Bestellung" zählt als verfügbar. Mit gewähltem Filter zählt die passende Variante: Unter „rot" steht ein Shirt hinten, dessen rote Variante ausverkauft ist.'];
$GLOBALS['TL_LANG']['tl_content']['conshoAvailableFilter'] = ['„Nur verfügbare" anbieten', 'Zeigt über den Filtergruppen das Häkchen „Nur verfügbare". Es blendet aus, was derzeit nicht verfügbar ist; „Auf Bestellung" bleibt. Mit gewählter Variante (etwa „rot") muss genau diese verfügbar sein.'];
$GLOBALS['TL_LANG']['tl_content']['conshoSort'] = ['Sortierung', 'In dieser Reihenfolge zeigt die Liste ihre Produkte beim ersten Aufruf. Besucher können sie umstellen; eine umsortierte Ansicht wird nie eine eigene Landingpage.'];
$GLOBALS['TL_LANG']['tl_content']['conshoPerPage'] = ['Produkte je Seite', 'Ab dieser Anzahl wird geblättert. 0 zeigt alle Produkte auf einer Seite.'];
$GLOBALS['TL_LANG']['tl_content']['conshoSteps'] = ['Anzahl der Schritte', '1: alles auf einer Seite. 2: Adresse, Versand und Zahlung, dann die Übersicht. 3: Adresse, dann Versand und Zahlung, dann die Übersicht. 4: jeder Teil auf einer eigenen Seite. Ab zwei Schritten steht die Übersicht mit dem Kaufknopf immer allein.'];
$GLOBALS['TL_LANG']['tl_content']['conshoTilePurchase'] = ['Kaufknopf auf der Kachel', 'Zeigt unter jeder Kachel einen Knopf „In den Warenkorb". Nur bei Produkten ohne Varianten — die brauchen erst eine Auswahl und verlinken weiter auf die Detailseite. Gekauft wird die Mindestmenge; eine andere Menge wählt der Kunde auf der Detailseite oder im Warenkorb.'];
