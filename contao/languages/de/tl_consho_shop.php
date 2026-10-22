<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_shop']['title'] = ['Bezeichnung', 'Name dieser Shop-Konfiguration im Backend.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['currencies'] = ['Erlaubte Währungen', 'In welchen Währungen dieser Shop abrechnen kann. Das hängt am Bankkonto, an der Zahlungsart und daran, ob eine Versandpauschale einen Betrag in dieser Währung hat. Welche Währung ein Besucher sieht, steht nicht hier, sondern am Startpunkt — dort wird aus dieser Liste ausgewählt.'];

// Aus der Palette gefallen (C-1c), der Eintrag bleibt: Contao zeigt die
// Beschriftung auch in der Listenansicht und unter „Details".
// Nur noch Liste und Detailansicht zeigen dieses Label — das Feld ist seit
// C-1c aus der Maske genommen und fällt nach der Abnahme ganz weg.
$GLOBALS['TL_LANG']['tl_consho_shop']['currency'] = ['Frühere Währung', 'Die Währung, die dieser Shop vor C-1c hatte. Sie wurde an seine Startpunkte übertragen und wird nirgends mehr gelesen; welche Währungen der Shop annimmt, steht unter „Währungen".'];
$GLOBALS['TL_LANG']['tl_consho_shop']['country'] = ['Heimatland', 'Das Land, in dem der Shop seinen Sitz hat. Mit dessen Steuersatz wird aus dem Nettopreis der Bruttopreis, den Besucher sehen, solange noch kein Lieferland feststeht. Ohne Heimatland zeigt der Shop keine Preise.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['fallbackLanguage'] = ['Standardsprache', 'Die Sprache, in der die Texte am Datensatz selbst stehen. Fehlt eine Übersetzung, greift diese Sprache.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['deliveryCountries'] = ['Lieferländer', 'Wohin dieser Shop liefert. Was hier angehakt ist, wird im Checkout angeboten. Fehlt für ein Land der Satz einer verwendeten Steuerklasse, verschwindet es nicht — es wird gemeldet, damit der Satz nachgetragen werden kann.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['mailTransport'] = ['Mail-Konfiguration', 'Postfach und Absender dieses Shops. Mehrere Shop-Konfigurationen dürfen dieselbe verwenden.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['operatorName'] = ['Betreibername', 'Empfänger der Betreiberkopien — nicht der Absender.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['operatorAddress'] = ['Betreiber-E-Mail', 'Hierhin gehen die Kopien für den Betreiber — eine E-Mail-Adresse, keine Anschrift. Die Anschrift für die Rechnung steht unter „Rechnungsabsender". Der Absender der Mails steht in der Mail-Konfiguration.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceCompany'] = ['Firmenname', 'Wer die Rechnung ausstellt, im Wortlaut des Firmenbuchs oder Handelsregisters.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceLegalForm'] = ['Rechtsform', 'Nur ausfüllen, wenn sie nicht schon im Firmennamen steckt. Freiwillig.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceStreet'] = ['Straße und Hausnummer', ''];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoicePostal'] = ['PLZ', ''];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceCity'] = ['Ort', ''];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceCountry'] = ['Land', ''];
$GLOBALS['TL_LANG']['tl_consho_shop']['termsRequired'] = ['AGB-Häkchen verlangen', 'Der Käufer muss den AGB vor dem Kauf ausdrücklich zustimmen. Ohne Häkchen werden die AGB nur verlinkt — was rechtlich genügt.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['buyButtonLabel'] = ['Beschriftung des Kaufknopfs', 'Nur zugelassene Formulierungen. Fehlt der Hinweis auf die Zahlungspflicht, kommt nach § 8 FAGG bzw. § 312j BGB kein Vertrag zustande.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['buyButtonLabelRef'] = [
    'paymentRequired' => 'Zahlungspflichtig bestellen',
    'chargeable' => 'Kostenpflichtig bestellen',
];

$GLOBALS['TL_LANG']['tl_consho_shop']['vatId'] = ['Eigene UID-Nummer', 'Die UID dieses Shops, inklusive Ländercode. Ohne sie liefert die VIES-Prüfung zwar ein Ergebnis, aber keine Nachweiskennung — und die ist der Beleg für eine Betriebsprüfung.'];

$GLOBALS['TL_LANG']['tl_consho_shop']['shippingMethods'] = ['Versandarten', 'Anhaken, was dieser Shop anbietet, und per Ziehen ordnen. Die Reihenfolge gilt im Checkout; die oberste verfügbare ist vorausgewählt. Eine gelöschte Versandart erscheint als unbekannte Option und bleibt einfach unangehakt.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['paymentMethods'] = ['Zahlungsarten', 'Anhaken, was dieser Shop anbietet, und per Ziehen ordnen. Die Reihenfolge gilt im Checkout; die oberste verfügbare ist vorausgewählt. Eine gelöschte Zahlungsart erscheint als unbekannte Option und bleibt einfach unangehakt.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['unpublishedMethod'] = 'unveröffentlicht';

$GLOBALS['TL_LANG']['tl_consho_shop']['afterAddToCart'] = ['Nach dem Hinzufügen', 'Wohin der Besucher kommt, nachdem er etwas in den Warenkorb gelegt hat. „Zurück zur Seite" braucht einen Mini-Warenkorb, sonst sieht er nicht, dass etwas passiert ist.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['afterAddToCartOptions'] = [
    'cart' => 'zum Warenkorb',
    'page' => 'zurück zur Seite',
];

$GLOBALS['TL_LANG']['tl_consho_shop']['netPriceGroups'] = ['Kundengruppen mit Nettopreisen', 'Mitglieder dieser Gruppen sehen Preise ohne Umsatzsteuer, mit dem Zusatz „zzgl. USt.". Gäste sehen immer Bruttopreise. Diese Auswahl entscheidet nur über die Anzeige — welcher Preis gilt, steht als Kundengruppen-Preis am Produkt.'];

$GLOBALS['TL_LANG']['tl_consho_shop']['taxRateMissing'] ='Für %s ist nicht hinterlegt, wie die Gruppe %s dort besteuert wird. Ohne Eintrag scheitert eine Bestellung aus diesem Land an der Steuerberechnung.';

$GLOBALS['TL_LANG']['tl_consho_shop']['title_legend'] = 'Bezeichnung';
$GLOBALS['TL_LANG']['tl_consho_shop']['currency_legend'] = 'Währungen';
$GLOBALS['TL_LANG']['tl_consho_shop']['language_legend'] = 'Sprache';
$GLOBALS['TL_LANG']['tl_consho_shop']['country_legend'] = 'Länder';
$GLOBALS['TL_LANG']['tl_consho_shop']['tax_legend'] = 'Umsatzsteuer';
$GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestination'] = ['Steuer im Bestimmungsland', 'Ware und digitale Leistungen an Privatkunden in einem anderen EU-Land mit der Umsatzsteuer des Kundenlandes berechnen (One-Stop-Shop). Einschalten, wenn die Lieferschwelle von 10.000 € überschritten ist oder Sie auf sie verzichtet haben. Ausgeschaltet gilt die Steuer des Shoplandes. Gilt für alle Shops mit derselben UID.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationShared'] = 'Steuer im Bestimmungsland gilt für das ganze Unternehmen — sie ist jetzt auch bei diesen Shops mit derselben UID %s: %s.';
$GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationOn'] = 'eingeschaltet';
$GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationOff'] = 'ausgeschaltet';
$GLOBALS['TL_LANG']['tl_consho_shop']['ageVerifiedGroups'] = ['Kundengruppen mit geprüftem Alter', 'Mitglieder dieser Gruppen dürfen Artikel ab 16 bzw. 18 bestellen. Das Alter prüft der Betreiber außerhalb des Shops und steht dafür ein — Consho prüft nichts selbst. Der zweite Weg ist eine Versandart, die bei der Übergabe prüft; einer der beiden genügt. Ist keiner eingerichtet, sind solche Artikel nicht bestellbar.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['guestCheckout'] = ['Bestellung ohne Konto', 'Erlaubt das Bestellen als Gast. Ist die Option aus, bleibt nur das Anmelden — dann muss auf der Checkout-Seite ein Anmelde-Element stehen.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['customergroup_legend'] = 'Kundengruppen';
$GLOBALS['TL_LANG']['tl_consho_shop']['customerGroup'] = ['Basisgruppe der Kunden', 'Konten ohne Bestellung erscheinen über diese Gruppe unter „Kunden"; Mitglieder mit Bestellung und Gäste kommen aus den Bestellungen. Legen Sie die Gruppe unter „Mitglieder → Gruppen" an und stellen Sie die Registrierung auf sie ein.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['methods_legend'] = 'Versand und Zahlung';
$GLOBALS['TL_LANG']['tl_consho_shop']['status_legend'] = 'Automatischer Bestellstatus';
$GLOBALS['TL_LANG']['tl_consho_shop']['statusAfterPayment'] = ['Status nach vollständiger Zahlung', 'Wird gesetzt, sobald die Zahlung erledigt ist. Leer: kein automatischer Wechsel.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['statusAfterDelivery'] = ['Status nach vollständiger Lieferung', 'Wird gesetzt, sobald alle Waren als versandt gemeldet sind.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['statusWhenSettled'] = ['Status, wenn alles erledigt ist', 'Wird gesetzt, sobald Zahlung und Lieferung erledigt sind. Hat Vorrang vor den beiden anderen. Gewechselt wird nie aus einem Status, der storniert, abschließt oder Änderungen sperrt.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['checkout_legend'] = 'Checkout';
$GLOBALS['TL_LANG']['tl_consho_shop']['withdrawal_legend'] = 'Widerruf';
$GLOBALS['TL_LANG']['tl_consho_shop']['privacy_legend'] = 'Aufbewahrung und Datenschutz';
$GLOBALS['TL_LANG']['tl_consho_shop']['retentionYears'] = ['Aufbewahrungsfrist (Jahre)', 'Wie lange Bestellungen und Belege mit Kundendaten aufbewahrt werden, gezählt ab Schluss des Kalenderjahres des jüngsten Belegs. Danach werden die Kundendaten anonymisiert; Nummern und Beträge bleiben. Die Frist legen Sie fest — zum Nachlesen: Österreich 7 Jahre (§ 132 Abs. 1 BAO, § 212 Abs. 1 UGB), Deutschland 8 Jahre für Buchungsbelege (§ 147 Abs. 1 Nr. 4 und Abs. 3 AO, § 257 Abs. 1 Nr. 4 und Abs. 4 HGB).'];
$GLOBALS['TL_LANG']['tl_consho_shop']['autoAnonymize'] = ['Nach Fristende automatisch anonymisieren', 'Anonymisiert die Kundendaten jeder Bestellung, deren Aufbewahrungsfrist abgelaufen ist, auch ohne Antrag (Speicherbegrenzung, Art. 5 Abs. 1 lit. e DSGVO). Aus: nur Bestellungen, für die eine Löschung verlangt wurde.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['withdrawalDays'] = ['Widerrufsfrist (Tage)', 'Mit ihr schätzt Consho, bis wann ein Widerruf rechtzeitig ist (Fensterende). Gesetzlich in der EU und im EWR: 14 Tage. 0 schaltet die Funktion ab — etwa für einen Shop, der nur außerhalb der EU liefert.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['withdrawalGraceDays'] = ['Zustellpuffer (Tage)', 'Die Frist beginnt mit dem Erhalt der Ware, Consho kennt nur das Versanddatum. Das Fensterende ist daher erst das Ende des Tages „letzter Versand + Frist + Puffer". Ein Widerruf danach wird trotzdem entgegengenommen und markiert.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceLogo'] = ['Logo (Rechnung und Mails)', 'PNG oder JPEG, oben auf der Rechnung und in jeder Mail an Kunden (eingebettet, höchstens 200 px breit; für Mails möglichst unter 100 KB). Jede Rechnung merkt sich die Datei samt Prüfsumme: Wird das Logo später ausgetauscht oder gelöscht, erscheint es auf neu erzeugten alten Rechnungen nicht mehr — lieber kein Logo als ein anderes.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['deliveryDaysMin'] = ['Lieferzeit ab (Werktage)', 'Frühester Liefertag, gezählt ab dem Rechnungsdatum in Werktagen (Mo–Fr, Feiertage werden nicht abgezogen). Eine Vorkasse-Rechnung entsteht vor dem Versand und nennt deshalb den voraussichtlichen Lieferzeitraum.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['deliveryDaysMax'] = ['Lieferzeit bis (Werktage)', 'Spätester Liefertag in Werktagen. Leer oder gleich „ab" ergibt einen einzelnen Tag.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['remainderDeliveryDays'] = ['Restlieferung nach Teillieferung (Werktage)', 'Grundwert: Nach einer Teillieferung nennt die Rechnung die erste Sendung und den Tag, an dem der Rest voraussichtlich folgt — so viele Werktage später. An der Bestellung überschreibbar. 0: kein Tag für den Rest.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceFooter'] = ['Fußzeile (Rechnung und Mails)', 'Steht unter jeder Rechnung (beim Ausstellen eingefroren) und unter jeder Mail an Kunden, nach Firma und Anschrift: Firmenbuch, Gericht, Bankverbindung, Hinweise. Consho setzt hier keinen Text vor — was eine Rechnung sagen muss, hängt an Rechtsform und Land.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceReverseChargeNote'] = ['Hinweis bei Reverse Charge', 'Erscheint nur auf Rechnungen mit Reverse Charge, eingefroren beim Ausstellen. Den Wortlaut verantwortet der Betreiber.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceContactEmail'] = ['Kontakt-E-Mail für Rechnungen', 'Steht in der E-Rechnung als Ansprechpartner des Rechnungsstellers. Pflicht für E-Rechnungen.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['eInvoice'] = ['E-Rechnung (ZUGFeRD)', 'Rechnungen, Stornos und Korrekturen an Geschäftskunden enthalten zusätzlich die maschinenlesbaren Daten nach EN 16931 (ZUGFeRD, Profil EN 16931) im PDF. Zwischen Unternehmen in Deutschland wird das schrittweise ab 1.1.2027 Pflicht (§ 14, § 27 Abs. 38 UStG); an Verbraucher geht nie eine E-Rechnung. Braucht Firmenname, Land, eigene UID und Kontakt-E-Mail.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['eInvoiceIncomplete'] = 'E-Rechnungen brauchen diese Angaben, bitte zuerst ausfüllen: %s.';
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceArchive'] =['Rechnungen zusätzlich ablegen', 'Legt jedes PDF beim Ausstellen in einem geschützten Ordner ab. Beim Abruf wird dann die abgelegte Datei ausgeliefert statt neu erzeugt.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceArchiveFolder'] = ['Ablageordner', 'Ein Ordner in files/, der nicht öffentlich sein darf.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceLogoCmyk'] = 'Das Logo ist im Farbmodell CMYK gespeichert (typisch für Druckdaten). Rechnungen entstehen als PDF/A, und dort lässt sich ein CMYK-Bild nicht einbetten — die Rechnung wäre nicht erzeugbar. Bitte das Logo als RGB speichern (in jedem Bildprogramm unter „Modus" bzw. „Farbraum") und neu hochladen.';
$GLOBALS['TL_LANG']['tl_consho_shop']['archiveFolderParentPublic'] = 'Der Ordner „%1$s“ ist öffentlich, und damit auch jeder Ordner darin. Rechnungen enthalten Personendaten — bitte einen Ordner außerhalb wählen oder „%1$s“ im Dateimanager selbst schützen.';
$GLOBALS['TL_LANG']['tl_consho_shop']['archiveFolderProtected'] = 'Der Ablageordner „%s“ war öffentlich und ist jetzt geschützt.';
$GLOBALS['TL_LANG']['tl_consho_shop']['invoice_legend'] = 'Rechnungsabsender';
$GLOBALS['TL_LANG']['tl_consho_shop']['mail_legend'] = 'E-Mail';

$GLOBALS['TL_LANG']['tl_consho_shop']['new'] = ['Neue Shop-Konfiguration', 'Shop-Konfiguration anlegen'];
$GLOBALS['TL_LANG']['tl_consho_shop']['status'] = ['Bestellstatus', 'Bestellstatus der Shop-Konfiguration ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_shop']['numbers'] = ['Nummernkreise', 'Nummernkreise der Shop-Konfiguration ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_shop']['setup'] = ['Einrichtung', 'Einrichtungsschritte der Shop-Konfiguration ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_consho_shop']['edit'] = ['Bearbeiten', 'Shop-Konfiguration ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_shop']['copy'] = ['Duplizieren', 'Shop-Konfiguration ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_shop']['delete'] = ['Löschen', 'Shop-Konfiguration ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_shop']['show'] = ['Details', 'Details der Shop-Konfiguration ID %s anzeigen'];
