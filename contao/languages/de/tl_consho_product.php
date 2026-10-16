<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_product']['title'] = ['Bezeichnung', 'Name des Produkts.'];
$GLOBALS['TL_LANG']['tl_consho_product']['alias'] = ['Alias', 'Eindeutige Adresse zum Aufruf des Produkts.'];
$GLOBALS['TL_LANG']['tl_consho_product']['sku'] = ['Artikelnummer', 'Die eigene Nummer des Shops.'];
$GLOBALS['TL_LANG']['tl_consho_product']['gtin'] = ['GTIN / EAN', 'Identifiziert DIESEN Artikel weltweit. Jede Variante hat ihre eigene — sie wird nicht vom Hauptprodukt geerbt. Leerzeichen und Bindestriche dürfen mitgetippt werden; die Prüfziffer wird nachgerechnet.'];
$GLOBALS['TL_LANG']['tl_consho_product']['mpn'] = ['Herstellernummer', 'Die Nummer, unter der DER HERSTELLER den Artikel führt. Ebenfalls artikelgenau, nicht vererbt.'];
$GLOBALS['TL_LANG']['tl_consho_product']['minimumAge'] = ['Altersfreigabe', 'Ab welchem Alter der Artikel abgegeben werden darf. Wirkt sich später auf Checkout und Versand aus.'];

$GLOBALS['TL_LANG']['tl_consho_product']['minimumAges'] = [
    0 => 'Ohne Altersbeschränkung',
    6 => 'ab 6 Jahren',
    12 => 'ab 12 Jahren',
    14 => 'ab 14 Jahren',
    16 => 'ab 16 Jahren',
    18 => 'ab 18 Jahren',
];
$GLOBALS['TL_LANG']['tl_consho_product']['productType'] = ['Produkttyp', 'Bestimmt die verfügbaren Eigenschaften und Zusatzfelder. Die Art (körperlich, digital, Dienstleistung, Wertgutschein) kommt immer vom Typ des Grundprodukts: Eine Variante mit eigenem Typ einer anderen Art bleibt, was das Grundprodukt ist.'];
$GLOBALS['TL_LANG']['tl_consho_product']['pid'] = ['Hauptprodukt', 'Leer lassen für ein eigenständiges Produkt. Ist hier eines gewählt, wird dieser Datensatz zu einer Variante davon — Beschreibung, Bilder und Seite kommen dann vom Hauptprodukt.'];
$GLOBALS['TL_LANG']['tl_consho_product']['shops'] = ['Shops', 'In welchen Shops dieses Produkt sichtbar ist. Der Katalog ist gemeinsam, die Sichtbarkeit wird hier je Shop gesetzt. Bei einer Variante bedeutet „nichts angehakt": wie das Hauptprodukt. Angehakt schränkt ein — mehr als das Hauptprodukt kann eine Variante nie erreichen.'];
$GLOBALS['TL_LANG']['tl_consho_product']['taxClass'] = ['Steuerklasse', 'Der Satz ergibt sich aus Klasse, Lieferland und Bestelldatum — er wird nicht am Produkt hinterlegt.'];
$GLOBALS['TL_LANG']['tl_consho_product']['manufacturer'] = ['Hersteller', 'Pflichtangabe für körperliche Ware. Bei einer Variante leer lassen heißt: derselbe wie beim Hauptprodukt.'];
$GLOBALS['TL_LANG']['tl_consho_product']['safetyWarnings'] = ['Warnhinweise', 'Gefahren und Warnungen, z. B. „Enthält Alkohol" oder „Nicht für Kinder unter 3 Jahren". Erscheinen direkt bei der Ware, nicht in einem Reiter.'];
$GLOBALS['TL_LANG']['tl_consho_product']['safetyInformation'] = ['Sicherheitsinformationen', 'Hinweise zur sicheren Verwendung, Lagerung und Entsorgung.'];
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityYears'] = ['Haltbarkeitsgarantie des Herstellers (Jahre)', 'Nur wenn der <strong>Hersteller</strong> eine gewerbliche Haltbarkeitsgarantie ohne Aufpreis für die ganze Ware gibt, die <strong>länger als zwei Jahre</strong> läuft, und sie Ihnen mitgeteilt hat (§ 4 Abs. 1 Z 12a FAGG). Dann ist das GARAN-Label Pflicht. Ganze oder halbe Jahre (z. B. 2,5). Leer oder 0: keine solche Garantie. Gilt für alle Varianten.'];
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityLabel'] = ['GARAN-Label', 'Das ausgefüllte Label (Dauer, Hersteller, Modellkennung) als PNG oder JPG, farbig. Vorlage der EU-Kommission: „EU GARAN product label" unter commission.europa.eu. Erscheint auf der Produktseite, vor dem Kaufknopf und in der Bestellbestätigung.'];
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityNested'] = ['GARAN-Label geschachtelt', 'Optional: die kompakte Fassung der Kommission, in der nur die Jahre („XX") eingetragen sind (PNG, JPG oder SVG). Erscheint auf den Kacheln der Produktlisten und öffnet beim Klick das volle Label. Nur zusammen mit dem vollen Label.'];
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityYearsTooShort'] = 'Eine Haltbarkeitsgarantie bekommt das GARAN-Label nur, wenn sie länger als zwei Jahre läuft. Bei kürzerer Dauer bitte 0 eintragen.';
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityYearsInvalid'] = 'Die Dauer in ganzen oder halben Jahren eintragen, zum Beispiel 3 oder 2,5 — andere Kommastellen sieht das GARAN-Label nicht vor.';
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityMainOnly'] = 'Die Haltbarkeitsgarantie wird am Hauptprodukt eingetragen; die Varianten übernehmen sie.';
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityLabelNotPublic'] = 'Der Ordner des GARAN-Labels ist nicht öffentlich — im Shop wäre das Bild nicht zu sehen. In der Dateiverwaltung beim Ordner „Öffentlich" setzen oder das Label in einen öffentlichen Ordner legen.';
$GLOBALS['TL_LANG']['tl_consho_product']['durabilityNotGoods'] ='Das GARAN-Label gilt nur für Waren — nicht für Dateien, Dienstleistungen oder Wertgutscheine.';
$GLOBALS['TL_LANG']['tl_consho_product']['teaser'] = ['Kurzbeschreibung', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['metaDescription'] = ['Meta-Beschreibung', 'Der Text in den Suchergebnissen. Leer lassen: Dann gilt die Kurzbeschreibung, auf rund 160 Zeichen gekürzt. Wirkt nur am Hauptprodukt, nicht an Varianten.'];
$GLOBALS['TL_LANG']['tl_consho_product']['description'] = ['Beschreibung', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['images'] = ['Bilder', 'Aus der Contao-Dateiverwaltung. Consho legt keine eigenen Bildordner an. Die Reihenfolge lässt sich durch Ziehen ändern — das erste Bild erscheint in der Liste, im Warenkorb und in den Suchergebnissen.'];
$GLOBALS['TL_LANG']['tl_consho_product']['weight'] = ['Gewicht (kg)', 'Produktgewicht ohne Verpackung.'];
$GLOBALS['TL_LANG']['tl_consho_product']['shippingWeight'] = ['Versandgewicht (kg)', 'Mit Verpackung. Leer lassen, wenn identisch mit dem Produktgewicht.'];
$GLOBALS['TL_LANG']['tl_consho_product']['length'] = ['Länge (cm)', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['width'] = ['Breite (cm)', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['height'] = ['Höhe (cm)', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['unit'] = ['Maßeinheit', 'Grundlage für den Grundpreis nach Preisangabenverordnung.'];
$GLOBALS['TL_LANG']['tl_consho_product']['purchaseUnit'] = ['Inhalt', 'Wie viel der Maßeinheit in einer Verkaufseinheit steckt, z. B. 0,25 bei 250 ml.'];
$GLOBALS['TL_LANG']['tl_consho_product']['referenceUnit'] = ['Bezugsgröße', 'Auf welche Menge sich der Grundpreis bezieht, z. B. 1 bei „pro Liter".'];
$GLOBALS['TL_LANG']['tl_consho_product']['minPurchase'] = ['Mindestabnahme', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['purchaseSteps'] = ['Schrittweite', 'Bei Meterware z. B. 0,1 für Auswahl auf 10 cm genau.'];
$GLOBALS['TL_LANG']['tl_consho_product']['published'] = ['Veröffentlicht', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['start'] = ['Anzeigen ab', ''];
$GLOBALS['TL_LANG']['tl_consho_product']['stop'] = ['Anzeigen bis', ''];

$GLOBALS['TL_LANG']['tl_consho_product']['product_legend'] = 'Produkt';
$GLOBALS['TL_LANG']['tl_consho_product']['identification_legend'] = 'Kennzeichnung';
$GLOBALS['TL_LANG']['tl_consho_product']['sales_legend'] = 'Verkauf';
$GLOBALS['TL_LANG']['tl_consho_product']['description_legend'] = 'Beschreibung und Bilder';
$GLOBALS['TL_LANG']['tl_consho_product']['safety_legend'] = 'Sicherheit und Garantie';
$GLOBALS['TL_LANG']['tl_consho_product']['shipping_legend'] = 'Versand';
$GLOBALS['TL_LANG']['tl_consho_product']['publish_legend'] = 'Veröffentlichung';

$GLOBALS['TL_LANG']['tl_consho_product']['units'] = [
    'kg' => 'Kilogramm',
    'g' => 'Gramm',
    'l' => 'Liter',
    'ml' => 'Milliliter',
    'm' => 'Meter',
    'cm' => 'Zentimeter',
    'm2' => 'Quadratmeter',
    'stk' => 'Stück',
];

$GLOBALS['TL_LANG']['tl_consho_product']['new'] = ['Neues Produkt', 'Produkt anlegen'];
$GLOBALS['TL_LANG']['tl_consho_product']['prices'] = ['Preise', 'Preise des Produkts ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_product']['edit'] = ['Bearbeiten', 'Produkt ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_product']['copy'] = ['Duplizieren', 'Produkt ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_product']['delete'] = ['Löschen', 'Produkt ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_product']['toggle'] = ['Veröffentlichen', 'Produkt ID %s veröffentlichen oder verbergen'];
$GLOBALS['TL_LANG']['tl_consho_product']['show'] = ['Details', 'Details des Produkts ID %s anzeigen'];

$GLOBALS['TL_LANG']['tl_consho_product']['properties'] = ['Eigenschaften', 'Was der Artikel <strong>ist</strong> — „Bio", „Kräuterbitter". Danach filtern Kunden, und daraus baut der Redakteur Kategorieseiten. Mehrere Werte derselben Gruppe wirken im Filter als ODER.'];
$GLOBALS['TL_LANG']['tl_consho_product']['variantOptions'] = ['Variantenachsen', 'Was <strong>diese Zeile</strong> zu einer bestimmten Variante macht — „250 ml". Nur an Varianten sinnvoll, nicht am Hauptprodukt. Ein Filter auf eine Variantenachse findet das Hauptprodukt, sobald eine sichtbare Variante passt.'];

// F-4: digitale Auslieferung.
$GLOBALS['TL_LANG']['tl_consho_product']['digital_legend'] = 'Auslieferung und Gültigkeit';
$GLOBALS['TL_LANG']['tl_consho_product']['digitalDelivery'] = ['Auslieferung', 'Wie der Kunde ein digitales Gut erhält. Gilt nur bei einem Produkttyp der Art „digital". Leer: nur die Berechtigung, ohne Datei oder Schlüssel. Eine Variante ohne eigene Angabe übernimmt die des Grundprodukts.'];
$GLOBALS['TL_LANG']['tl_consho_product']['voucherValue'] = ['Wert', 'Wie viel ein Gutschein dieses Artikels wert ist. Meist gleich dem Preis; leer: so viel, wie bezahlt wurde.'];
$GLOBALS['TL_LANG']['tl_consho_product']['voucherValidMonths'] = ['Gültigkeit (Monate)', '0: unbefristet. Eine Befristung gegenüber Verbrauchern braucht einen sachlichen Grund (OGH 7 Ob 22/12d).'];
$GLOBALS['TL_LANG']['tl_consho_product']['entitlementMonths'] = ['Laufzeit (Monate)', 'Wie lange die Berechtigung gilt, ab der Freigabe. 0: dauerhaft. Was nach dem Ende aufhört — nur Updates oder die Nutzung —, legt das Produkt fest, nicht Consho.'];
$GLOBALS['TL_LANG']['tl_consho_product']['digitalFile'] = ['Datei', 'Die Datei für die Auslieferung „Datei". Nur aus einem geschützten Ordner — in einem öffentlichen wäre sie für jeden abrufbar, der die Adresse errät. Eine neue Fassung hier bekommen alle mit gültiger Berechtigung.'];
$GLOBALS['TL_LANG']['tl_consho_product']['digitalDeliveries'] = ['file' => 'Datei'];
$GLOBALS['TL_LANG']['tl_consho_product']['digitalFilePublic'] = 'Die Datei liegt im öffentlichen Ordner „%s" und wäre dort für jeden abrufbar. Bitte eine Datei aus einem geschützten Ordner wählen.';
$GLOBALS['TL_LANG']['tl_consho_product']['keyImport'] = ['Schlüssel einfügen', 'Für die Auslieferung „Schlüssel": ein Code je Zeile. Jedes verkaufte Stück bekommt einen. Codes, die schon im Vorrat dieses Artikels stehen, werden übersprungen. Den Vorrat zeigt die Schaltfläche „Schlüssel" in der Artikelliste.'];
$GLOBALS['TL_LANG']['tl_consho_product']['customFieldValues'] = ['Zusatzfelder', 'Die Angaben, die der Produkttyp vorsieht — Jahrgang, Rebsorte. Nicht filterbar; wer filtern will, legt eine Eigenschaft an.'];
$GLOBALS['TL_LANG']['tl_consho_product']['custom_legend'] = 'Zusatzfelder';
$GLOBALS['TL_LANG']['tl_consho_product']['customFieldInherited'] = 'Leer lassen übernimmt den Wert des Hauptprodukts: %s';
$GLOBALS['TL_LANG']['tl_consho_product']['customFieldInheritedNone'] = 'Leer lassen übernimmt den Wert des Hauptprodukts (dort leer).';
$GLOBALS['TL_LANG']['tl_consho_product']['customFieldBoolean'] = ['1' => 'Ja', '0' => 'Nein'];
$GLOBALS['TL_LANG']['tl_consho_product']['variantDuplicate'] = 'Die Variante mit der ID %s trägt schon genau diese Variantenwerte. Zwei gleiche Varianten unter einem Hauptprodukt kann der Kunde nicht unterscheiden — bitte andere Werte wählen.';
$GLOBALS['TL_LANG']['tl_consho_product']['kindUnused'] = '⚠ Nicht verwendet: Das Produkt ist laut Produkttyp %s. Feld leeren, dann verschwindet es aus der Maske.';
$GLOBALS['TL_LANG']['tl_consho_product']['kindUnusedKeys'] = '⚠ Nicht verwendet: Das Produkt ist laut Produkttyp %s. Die Schlüssel im Vorrat in der Artikelliste unter „Schlüssel" löschen, dann verschwindet das Feld.';
$GLOBALS['TL_LANG']['tl_consho_product']['keysFree'] = '%d frei';
$GLOBALS['TL_LANG']['tl_consho_product']['digitalDeliveries']['key'] = 'Schlüssel aus dem Vorrat';
$GLOBALS['TL_LANG']['tl_consho_product']['keys'] = ['Schlüssel', 'Schlüsselvorrat von Artikel ID %s'];

// Abo-1: Verkaufsart.
$GLOBALS['TL_LANG']['tl_consho_product']['saleOnce'] = ['Einmalkauf', 'Einmal bezahlen — dauerhaft oder für die Laufzeit darunter. Bei körperlicher Ware immer an, gleich was hier steht.'];
$GLOBALS['TL_LANG']['tl_consho_product']['saleSubscription'] = ['Abo', 'Im Abo nach einer oder mehreren Aboregeln. Die Beträge stehen in den Preisen, je Preiszeile mit ihrer Aboregel.'];
$GLOBALS['TL_LANG']['tl_consho_product']['subscriptionRules'] = ['Aboregeln', 'In dieser Reihenfolge stehen die Modelle auf der Produktseite. Aboregeln legt man unter „Aboregeln" an.'];

// Block G: Bestand.
$GLOBALS['TL_LANG']['tl_consho_product']['stockTracked'] = ['Bestand führen', 'Consho zählt den Bestand jedes Artikels: Der Kaufklick bucht ab, ein Storno bucht zurück, bei Bestand 0 ist der Artikel „derzeit nicht verfügbar". Aus: unbegrenzt lieferbar.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockBackorder'] = ['Auch ohne Bestand verkaufen', 'Bei Bestand 0 weiter verkaufen, mit dem Hinweis „auf Bestellung, längere Lieferzeit". Der Bestand wird dann negativ.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockWarning'] = ['Nur noch wenige ab', 'Bis zu diesem Bestand steht beim Artikel „nur noch wenige", und das Backend warnt. Bei Schlüsseln zählen die freien Codes abzüglich der offenen Bestellungen. 0: erst, wenn nichts mehr da ist.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockQuantity'] = ['Bestand', 'Der Bestand dieses Artikels. Gebucht wird die Änderung gegenüber dem Wert beim Öffnen der Maske — ein Verkauf in der Zwischenzeit geht nicht verloren.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockAvailableFrom'] = ['Wieder lieferbar ab', 'Wird bei einem nicht verfügbaren Artikel angezeigt. Leer: kein Datum.'];
$GLOBALS['TL_LANG']['tl_consho_product']['stockInvalid'] = 'Bitte beim Bestand eine Zahl eingeben.';

// Block G (G-7): Einkauf.
$GLOBALS['TL_LANG']['tl_consho_product']['stockTarget'] = ['Sollbestand', 'Bis hierhin füllt der Einkauf auf. Nur im Backend. 0: eine Packung je Einkauf.'];
$GLOBALS['TL_LANG']['tl_consho_product']['packUnit'] = ['Verpackungseinheit (Einkauf)', 'Stück je Packung beim Lieferanten. Der Einkauf rechnet in ganzen Packungen. 1: Einzelstücke. Nicht die Kaufmenge des Kunden.'];
$GLOBALS['TL_LANG']['tl_consho_product']['supplier'] = ['Lieferant', 'Beim Tippen stehen die schon verwendeten Lieferanten zur Auswahl. Groß-/Kleinschreibung und Leerzeichen werden an einen vorhandenen Namen angeglichen.'];
$GLOBALS['TL_LANG']['tl_consho_product']['supplierSku'] = ['Bestellnummer beim Lieferanten', 'Die Nummer, unter der der Lieferant genau diesen Artikel führt. Steht in der Einkaufsliste.'];
