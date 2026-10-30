<?php declare(strict_types=1);

/*
 * Sentences for the findings of ConfigurationCheck and DeliverabilityChecker.
 *
 * The check classes only return codes and the values to insert — the
 * wording belongs here, so that it stays translatable and the check classes
 * need to know nothing about Contao.
 */
$GLOBALS['TL_LANG']['CONSHO']['findings'] = [
    'taxRateMissing' => 'Die Shop-Konfiguration „%shop%" hat für %country% nicht hinterlegt, wie die Gruppe „%taxClass%" dort besteuert wird. Ohne Eintrag scheitern Bestellungen aus diesem Land im Checkout; ist es das Heimatland des Shops, zeigt er für die Gruppe keine Preise.',
    'homeCountryMissing' => 'Die Shop-Konfiguration „%shop%" hat kein Heimatland. Ohne Heimatland gibt es keinen Bruttopreis: Der Shop zeigt in Listen und auf Produktseiten keine Preise. In der Shop-Konfiguration unter „Länder" eintragen.',
    'duplicateVatId' => 'Zwei Shop-Konfigurationen führen dieselbe UID-Nummer %vatId%: %shops%. Falls das ein Shop in mehreren Sprachen ist, gehört er auf EINE Konfiguration — sonst laufen zwei getrennte Rechnungsnummernkreise.',
    'mailTransportMissing' => 'Die Shop-Konfiguration „%shop%" hat keine Mail-Konfiguration. Es können keine Bestellbestätigungen versendet werden.',
    'productManufacturerMissing' => 'Waren ohne Hersteller: %count%. Die EU-Produktsicherheitsverordnung verlangt die Herstellerangabe bei jedem einzelnen Angebot.',
    'manufacturerEmailMissing' => 'Für den Hersteller „%manufacturer%" fehlt die E-Mail-Adresse. Eine Webseite genügt nicht — verlangt ist eine Adresse für direkte Kommunikation.',
    'duplicateGtin' => 'Die GTIN %gtin% ist an %count% Produkten hinterlegt. Eine GTIN bezeichnet normalerweise genau einen Artikel.',
    'manufacturerEuResponsibleMissing' => 'Der Hersteller „%manufacturer%" sitzt außerhalb der EU (%country%) und hat keine verantwortliche Person in der EU. Ohne sie dürfen seine Produkte hier nicht angeboten werden.',
    'translationMissing' => 'Veröffentlichte Produkte ohne Übersetzung mit Alias im Shop „%shop%" in der Sprache „%language%": %count%. Ohne Übersetzung erscheint ein Produkt dort nicht.',
    'translationSafetyMissing' => 'Übersetzte, veröffentlichte Produkte ohne Sicherheitshinweise im Shop „%shop%" in der Sprache „%language%": %count%. Ein solches Produkt ist dort unsichtbar: Die Hinweise müssen in der Sprache des Marktes vorliegen und werden deshalb nicht aus der Standardsprache übernommen.',
    'listPriceDeviation' => 'Listenpreise für Filter und Sortierung, die nicht zu den Preisen passen: %count%. Betroffene Produkte fehlen in der Produktliste oder stehen mit falschem Preis darin. Beheben mit dem Konsolenbefehl consho:price:rebuild; Einzelheiten zeigt consho:price:verify.',
    'visibilityDeviation' => 'Einträge für Produktlisten und Filter, die nicht zum Katalog passen: %count%. Betroffene Produkte fehlen in Listen, stehen zu Unrecht darin oder mit falschem Ab-Preis. Beheben mit dem Konsolenbefehl consho:visibility:rebuild; Einzelheiten zeigt consho:visibility:verify.',
    'pinnedProductMissing' => 'Anheftungen in Produktlisten, deren Produkt es nicht mehr gibt oder das inzwischen eine Variante ist: %count%. Auf der Website erscheinen sie nicht. In der Liste löschen oder ein anderes Produkt wählen.',
    'productShopMissing' => 'Veröffentlichte Produkte, die keinem Shop zugeordnet sind: %count%. Sie erscheinen in keiner Liste und haben keine Produktseite. Am Produkt unter „Shops" zuordnen.',
    'productPriceMissing' => 'Sichtbare Produkte ohne Preis in %currency% im Shop „%shop%": %count%. Sie erscheinen in keiner Produktliste und nicht in der Sitemap, ihre Produktseite zeigt keinen Preis. Ob ein Produkt mit Preis in keiner Liste steht, zeigt consho:catalog:verify.',
    'priceTie' => 'Artikel mit zwei gleichrangigen Preisen zur selben Zeit (gleiche Währung, Kundengruppe und Staffel, überlappende Gültigkeit): %count%. Welcher Preis gilt, ist dann zufällig. Eine der Zeilen befristen oder löschen.',
    'priceCutAsPromotion' => 'Artikel mit einer billigeren Preiszeile, die einen Beginn, aber kein Ende hat, neben dem Basispreis: %count%. Sie wird als Aktion mit Streichpreis gezeigt, und zwar dauerhaft. Ist eine dauerhafte Preissenkung gemeint, die alte Zeile mit „Gültig bis" beenden; die neue Zeile mit „Gültig ab" bleibt dann ohne Streichpreis.',
    'pathTemplateInvalid' => 'Produktseiten mit ungültiger Adressvorlage: %count% (%pages%). Die Vorlage wurde am Backend vorbei gespeichert; die Seite verwendet stattdessen die Standardvorlage {alias}, ihre Produkte sind unter anderen Adressen erreichbar als eingestellt. In den Seiteneinstellungen eine gültige Vorlage speichern.',
    'shippingPriceMissing' => 'Die Versandart „%method%" hat keinen gespeicherten Betrag in %currency%. Im Shop „%shop%" wird sie deshalb im Checkout nicht angeboten. Unter „Versandarten" einen Betrag in %currency% anlegen oder die Versandart in der Shop-Konfiguration abwählen.',
    'rootWithoutCurrency' => 'Der Startpunkt „%page%" gehört zur Shop-Konfiguration „%shop%", hat aber keine Währung. Unter ihm zeigt der Shop keinen einzigen Preis, und kein Produkt erscheint in einer Liste — ein Preis ohne Währung ist keine Angabe. Im Startpunkt unter „Consho" eine Währung wählen; zur Wahl stehen die Währungen des Shops.',
    'rootCurrencyNotSold' => 'Der Startpunkt „%page%" verkauft in %currency%, die Shop-Konfiguration „%shop%" nimmt diese Währung aber nicht (mehr) an. Besucher sehen dort Preise, die sich nicht kassieren lassen: Versandbeträge gibt es in %currency% nicht. Entweder %currency% in „%shop%" wieder zulassen oder am Startpunkt eine der angenommenen Währungen wählen.',
    'currencyMissing' => 'Kein Startpunkt der Shop-Konfiguration „%shop%" hat eine Währung. Der Shop zeigt deshalb nirgends einen Preis und kein Produkt erscheint in einer Liste. An einem Startpunkt dieses Shops eine Währung wählen — oder, falls noch keiner auf ihn zeigt, einen Startpunkt anlegen und ihm den Shop zuweisen.',
    'multipleShopsShareMembers' => 'Diese Installation hat mehr als eine Shop-Konfiguration: %shops%. Alle arbeiten mit derselben Mitgliederverwaltung — Kunden, Adressen und UID-Nummern sind geteilt. Das setzt voraus, dass hinter allen Shops derselbe Verantwortliche im Sinne der DSGVO steht; für zwei fremde Firmen gehören zwei getrennte Installationen aufgesetzt. Ist das geklärt, ist nichts zu tun: Mehrere Shops sind vorgesehen, und dieser Hinweis sperrt nichts.',
    'miniCartMissing' => 'Die Shop-Konfiguration „%shops%" schickt Besucher nach dem Hinzufügen zurück zur Seite, es gibt aber nirgends ein Mini-Warenkorb-Element. Der Besucher sieht dann nicht, dass etwas im Warenkorb gelandet ist. Fügen Sie ein Mini-Warenkorb-Element in einen Layout-Bereich oder einen Artikel ein, oder stellen Sie „Nach dem Hinzufügen" auf „zum Warenkorb".',
    'groupPriceAboveList' => 'Kundengruppen-Preise, die teurer sind als der Preis für alle: %count% (%examples%). Mitglieder dieser Gruppen zahlen dort mehr als ein Gast — so ist es eingestellt, und der Shop hält sich daran: Ein Kundengruppen-Preis geht einem Mengenrabatt vor, gleich welcher Betrag darin steht. Ist das gewollt (etwa Einzelabgabe an eine Gruppe, während der öffentliche Preis ein Palettenpreis ist), ist nichts zu tun. Sonst ist meist der Preis für alle nachträglich gesenkt worden, während der Gruppenpreis stehen blieb.',
    'checkoutPageMissing' => 'Startpunkte mit Shop, aber ohne Checkout-Seite: %count% (%pages%). Vom Warenkorb führt dann kein Weg weiter, und der Checkout dieses Shops ist gesperrt. Die Seite wird am Startpunkt eingestellt, in der Legende „Consho".',
    'checkoutElementMissing' => 'Checkout-Seiten ohne Checkout-Element: %count% (%pages%). Die Seite ist eingestellt, aber leer — setzen Sie ein Inhaltselement „Checkout" in einen Artikel dieser Seite. Bis dahin ist der Checkout dieses Shops gesperrt.',
    'shippingMethodMissing' => 'Kein Versand möglich in: %shops%. Entweder ist am Shop keine ausgewählt, oder alle ausgewählten sind unveröffentlicht. Ohne Versandart kann niemand bestellen.',
    'paymentMethodMissing' => 'Keine Zahlung möglich in: %shops%. Entweder ist am Shop keine ausgewählt, oder alle ausgewählten sind unveröffentlicht. Ohne Zahlungsart kann niemand bestellen.',
    'ageGateMissing' => 'Veröffentlichte Artikel ab 16 oder 18, die niemand bestellen kann: %count% (%shop%). Für diese Stufen braucht der Shop eine Kundengruppe mit geprüftem Alter oder eine Versandart, die bei der Übergabe prüft — eines von beiden genügt. Ist keines eingerichtet, weist der Checkout jede solche Bestellung ab, und der Artikel steht im Regal, ohne kaufbar zu sein.',
    'catalogPageAlwaysCached' =>'Diese Seiten sind auf „Immer aus dem Cache laden“ gestellt: %pages%. In dieser Installation gibt es kundenabhängige Preise (Nettogruppen oder Kundengruppen-Preise), und mit dieser Einstellung bekommt ein angemeldetes Mitglied die Seite, die ein Gast im Cache hinterlassen hat: Brutto-Kacheln mit „inkl. USt.“ neben einem Mini-Warenkorb, der netto rechnet. Prüfen Sie, ob eine dieser Seiten — oder eine Seite darunter, die die Einstellung erbt — Produktpreise zeigt, und nehmen Sie den Haken an der genannten Seite heraus.',
    'netPriceGroupMissing' => 'Die Shop-Konfiguration „%shop%" zeigt Nettopreise für eine Kundengruppe, die es nicht mehr gibt (ID %groups%) oder die deaktiviert ist. Die Auswahl wird dafür übergangen — Mitglieder, die früher in dieser Gruppe waren, sehen jetzt Bruttopreise. Das fällt dem Kunden auf, nicht Ihnen. Entfernen Sie den Eintrag aus „Kundengruppen mit Nettopreisen" oder wählen Sie die Gruppe, die an ihre Stelle getreten ist.',
    'cartPageMissing' => 'Startpunkte mit Shop, aber ohne Warenkorbseite: %count% (%pages%). Wer etwas hinzufügt, landet dann nirgends, und der Checkout dieses Shops ist gesperrt. Die Seite wird am Startpunkt eingestellt, in der Legende „Consho".',
    'cartElementMissing' => 'Warenkorbseiten ohne Warenkorb-Element: %count% (%pages%). Die Seite ist am Startpunkt eingestellt, aber leer — wer etwas hinzufügt, landet auf einer leeren Seite. Setzen Sie ein Inhaltselement „Warenkorb" in einen Artikel dieser Seite.',
    'orderPageMissing' => 'Startpunkte mit Shop, aber ohne Bestellseite: %count% (%pages%). Nach dem Kauf hätte der Käufer keine Seite, auf der er seine Bestellung sieht, und der Link in der Bestätigungsmail zeigte ins Leere. Legen Sie eine Seite dafür an und wählen Sie sie am Startpunkt.',
    'orderElementMissing' => 'Bestellseiten ohne Element „Bestellseite": %count% (%pages%). Die Seite ist am Startpunkt eingestellt, aber leer — nach dem Kauf und über den Link in der Bestätigungsmail sähe der Käufer nichts. Setzen Sie ein Inhaltselement „Bestellseite" in einen Artikel dieser Seite.',
    'legalTextMissing' => 'Startpunkte ohne Rechtstext: %count% (%pages%) — es fehlt: %kinds%. Verlangt sind die AGB, wenn „AGB-Häkchen verlangen" an ist (wer zustimmen soll, braucht einen Text), und die Widerrufsbelehrung, wenn der Shop in der EU oder im EWR sitzt und eine Widerrufsfrist hat — ohne Belehrung beginnt die Frist nicht (§ 12 FAGG; in Deutschland § 356 Abs. 3 BGB). Wählen Sie am Startpunkt ein Inhaltselement vom Typ „Text".',
    'legalTextAdvised' => 'Hinweis zu Rechtstexten: %count% Startpunkte (%pages%) — betroffen: %kinds%. Entweder sind AGB gewählt, die kein Käufer lesen kann, oder einem Shop außerhalb von EU und EWR mit Widerrufsfrist fehlt die Widerrufsbelehrung. Der Checkout bleibt offen; ob der Text nötig ist, entscheiden Sie.',
    'withdrawalFunctionMissing' => 'Startpunkte ohne erreichbare Funktion „Vertrag widerrufen": %count% (%pages%). In Deutschland seit 19.06.2026 (§ 356a BGB), in Österreich ab 01.10.2026 (§ 13a FAGG) muss ein Shop die Widerrufsfunktion während der ganzen Widerrufsfrist leicht zugänglich anbieten — auch Käufern ohne die Bestellmail. Legen Sie eine Seite mit dem Inhaltselement „Vertrag widerrufen" an, veröffentlichen Sie sie und wählen Sie sie am Startpunkt unter „Seite „Vertrag widerrufen"". Verlinken Sie sie am besten von jeder Seite aus, etwa in der Fußzeile.',
    'withdrawalFunctionAdvised' => 'Startpunkte ohne Funktion „Vertrag widerrufen": %count% (%pages%). Der Shop hat eine Widerrufsfrist, sitzt aber außerhalb von EU und EWR; der Checkout bleibt offen. Wer an Verbraucher in der EU verkauft, kann ihnen die Funktion trotzdem schulden. Legen Sie dann eine Seite mit dem Element „Vertrag widerrufen" an und wählen Sie sie am Startpunkt.',
    'withdrawalsOpen' => 'Offene Widerrufe im Shop „%shop%": %count% (%numbers%). Die erste Erstattung ist spätestens am %due% fällig (14 Tage ab Eingang). Bearbeiten unter „Widerrufe".',
    'withdrawalsUnassigned' => 'Widerrufe ohne Bestellung im Shop „%shop%": %count% (%numbers%). Bestellnummer und E-Mail-Adresse aus dem Widerrufsformular passten zu keiner Bestellung oder zu einer, die über das Formular nicht widerrufbar ist; eingegangen sind sie trotzdem. Unter „Widerrufe" einer Bestellung zuordnen oder ohne Bestellung abweisen — die erste Erstattung wäre spätestens am %due% fällig.',
    'retentionPeriodMissing' => 'Der Shop-Konfiguration „%shop%" fehlt die Aufbewahrungsfrist. Ohne sie wird nichts anonymisiert, und ein Löschantrag kann nur vermerkt werden. Tragen Sie die Frist im Abschnitt „Aufbewahrung und Datenschutz" ein.',
    'anonymizationDue' => 'Im Shop „%shop%" endet in den nächsten 30 Tagen die Aufbewahrungsfrist von %count% Bestellungen (%numbers%). Ihre Kundendaten werden danach automatisch anonymisiert; Nummern und Beträge bleiben.',
    'customerGroupMissing' => 'Der Shop-Konfiguration „%shop%" fehlt die Basisgruppe der Kunden, oder die gewählte Gruppe gibt es nicht mehr. Konten ohne Bestellung fehlen deshalb unter „Kunden". Wählen Sie die Gruppe im Abschnitt „Kundengruppen".',
    'automaticStatusCancels' => 'In der Shop-Konfiguration „%shop%" steht bei „%field%" der Status „%status%", der storniert. Dieser Wechsel findet nie statt: Nach einer Lieferung darf nicht mehr storniert werden, und nach einer Zahlung wäre eine stornierte Bestellung überzahlt. Wählen Sie einen anderen Status oder lassen Sie das Feld leer.',
    'statusContradictsBalance' => 'Bestellungen im Shop „%shop%" in einem Status, den Zahlungen und Lieferungen heute nicht tragen: %count% (%numbers%) — etwa „bezahlt", obwohl etwas offen oder erstattet ist. Consho ändert den Status nicht von selbst. Prüfen Sie die Bestellungen und setzen Sie den passenden Status.',
    'shippingCostsPageMissing' => 'Startpunkte ohne Versandkostenseite: %count% (%pages%). Das Element „Versandkosten" zeigt die Versandarten mit Preisen aus den Daten; welche Angaben der Shop dazu machen muss, beurteilen Sie.',
    'shippingCostsElementMissing' => 'Versandkostenseiten ohne Element „Versandkosten": %count% (%pages%). Die Seite ist am Startpunkt eingestellt, zeigt aber keine Versandkosten.',
    'deliveryCountryWithoutShipping' => 'Die Shop-Konfiguration „%shop%" liefert laut Lieferländern nach %countries%, aber keine ihrer Versandarten liefert dorthin. Der Checkout bietet diese Länder deshalb nicht an. Eine Versandart um die Länder ergänzen oder sie aus den Lieferländern nehmen.',
    'productKindMismatch' => 'Produkt „%product%" hat laut Produkttyp die Art %kind%, aber auch %fields%. Das wird nicht verwendet und ist in der Maske ausgeblendet; bitte am Produkt entfernen oder den Produkttyp prüfen.',
    'durabilityLabelMissing' => 'Produkt „%product%" hat eine Haltbarkeitsgarantie des Herstellers über %years% Jahre, aber kein GARAN-Label. Ohne Label zeigt der Shop die Garantie nicht an; sie ist vor dem Kauf in der harmonisierten Form anzugeben (§ 4 Abs. 1 Z 12a FAGG). Das ausgefüllte Label am Produkt hochladen.',
    'durabilityYearsMissing' => 'Produkt „%product%" hat ein GARAN-Label, aber keine gültige Dauer der Haltbarkeitsgarantie (mehr als zwei Jahre). Ohne sie zeigt der Shop das Label nicht an. Die Dauer in Jahren eintragen oder das Label entfernen.',
    'durabilityLabelNotPublic' => 'Das GARAN-Label von Produkt „%product%" liegt in einem Ordner, der nicht öffentlich ist. Im Shop wäre das Bild nicht zu sehen; deshalb zeigt Consho die Garantie dort gar nicht an. In der Dateiverwaltung beim Ordner „Öffentlich" setzen.',
    'durabilityLabelTooLarge' => 'Das GARAN-Label von Produkt „%product%" ist %size% KB groß. Es steckt in jeder Bestellbestätigung mit diesem Produkt; schwere Mails landen eher im Spam. Eine kleinere PNG- oder JPG-Datei hochladen (bis 100 KB).',
    'durabilityOnVariant' => 'Variante „%product%" trägt eine Haltbarkeitsgarantie. Die gilt nur am Hauptprodukt; an der Variante wirkt sie nicht. Am Hauptprodukt eintragen und an der Variante leeren.',
    'durabilityNestedUnused' => 'Das geschachtelte GARAN-Label von Produkt „%product%" erscheint nicht auf den Kacheln. Es braucht ein volles Label mit gültiger Dauer, das der Klick öffnet, und einen öffentlichen Ordner. Das volle Label ergänzen, den Ordner auf „Öffentlich" setzen oder das geschachtelte Label entfernen.',
    'mailFooterEmpty' => 'Die Shop-Konfiguration „%shop%" hat weder eine Firma noch eine Fußzeile — die Mails an Kunden sagen nicht, wer sie schickt. Was in geschäftlichen Mails stehen muss (etwa § 14 UGB, § 63 GewO), hängt an Rechtsform und Land. Unter „Rechnungsabsender" Firma und „Fußzeile (Rechnung und Mails)" ausfüllen.',
    'mailLogoTooLarge' => 'Das Logo der Shop-Konfiguration „%shop%" ist %size% KB groß. Es steckt in jeder Mail an Kunden; schwere Mails landen eher im Spam. Eine kleinere PNG- oder JPG-Datei hochladen (bis 100 KB).',
    'voucherTaxed' => 'Wertgutschein „%product%" hat eine Steuerklasse, die nicht als „nicht steuerbar" markiert ist. Er wird beim Verkauf besteuert, und was man damit kauft, noch einmal. Ist er ein Einzweck-Gutschein, im Produkt „Einzweck-Gutschein" einschalten.',
    'singlePurposeVoucherNotTaxed' => 'Einzweck-Gutschein „%product%" hat eine Steuerklasse „nicht steuerbar". Er wird ohne Umsatzsteuer verkauft und deshalb als Mehrzweck-Gutschein ausgegeben. Eine Steuerklasse mit Steuersatz wählen.',
    'notTaxableClassOnGoods' => 'Produkt „%product%" ist kein Wertgutschein, hat aber eine Steuerklasse „nicht steuerbar". Es wird ohne Umsatzsteuer verkauft.',
    'voucherValueMissing' => 'Wertgutschein „%product%" hat keinen Wert. Ein gekaufter Gutschein ist so viel wert, wie dafür bezahlt wurde.',
    'ossThresholdNear' => 'Die Fernverkäufe an Privatkunden in anderen EU-Ländern (%business%) haben %year% 80 % der Lieferschwelle von 10.000 € erreicht. Umsätze außerhalb von Consho zählen mit. Über der Schwelle ist die Umsatzsteuer des Kundenlandes zu berechnen — dafür „Steuer im Bestimmungsland" in der Shop-Konfiguration einschalten. Einzelheiten unter „Steuermeldung".',
    'invoicingOff' => 'Die Shop-Konfiguration „%shop%" stellt keine Rechnungen aus. Consho speichert die Rechnungsdaten jeder Bestellung, die Rechnungen selbst müssen außerhalb von Consho entstehen und an die Kunden gehen.',
    'invoiceProviderMissing' => 'Die Shop-Konfiguration „%shop%" überlässt die Rechnungen dem Modul „%module%", das nicht installiert ist. Bis es wieder installiert ist oder eine andere Einstellung gewählt wird, warten die Rechnungen dieses Shops.',
    'externalInvoiceFailed' => 'Belege, die nicht beim Rechnungsmodul angekommen sind: %count%. Die Rechnungsdaten sind gespeichert; den Grund nennt das Fehlerprotokoll. Nach der Behebung reicht „consho:document:verify --retry" sie erneut ein.',
    'eInvoiceIncomplete' => 'Die Shop-Konfiguration „%shop%" stellt E-Rechnungen aus, aber es fehlt: %fields%. Bis das ausgefüllt ist, gehen Rechnungen ohne die E-Rechnungsdaten hinaus.',
    'ossThresholdReached' =>'Die Fernverkäufe an Privatkunden in anderen EU-Ländern (%business%) haben %year% die Lieferschwelle von 10.000 € erreicht. Umsätze außerhalb von Consho zählen mit. Über der Schwelle ist die Umsatzsteuer des Kundenlandes zu berechnen — dafür „Steuer im Bestimmungsland" in der Shop-Konfiguration einschalten. Einzelheiten unter „Steuermeldung".',
    'saleModePriceMissing' => 'Produkt „%product%" wird als %modes% angeboten, hat dafür aber keinen Preis — so ist es nicht kaufbar. Am Produkt unter „Preise" eine Zeile mit „Verkauft als" anlegen, oder die Verkaufsart am Produkt abwählen.',
    'subscriptionSameTermOpen' => 'Die Aboregel „%rule%" verlängert sich jeweils um die volle Mindestlaufzeit und steht nicht nur Geschäftskunden offen — keine Kundengruppe oder eine, die in keinem Shop Nettopreis-Gruppe ist. Gegenüber Verbrauchern ist eine stillschweigende Verlängerung um eine feste Laufzeit in DE und AT nur eingeschränkt zulässig. Für Geschäftskunden in der Aboregel unter „Kundengruppen" die Nettopreis-Gruppe wählen; ist die Regel bewusst offen, zur Kenntnis nehmen.',
    'subscriptionNotListed' => 'Produkt „%product%" wird nur mit Aboregeln verkauft, die nicht allen offenstehen (auf Kundengruppen beschränkt). Produktlisten zeigen nur Modelle für alle — das Produkt steht deshalb in keiner Liste, nur auf seiner Seite. In einem geschlossenen Shop ist das richtig; sonst eine Aboregel ohne Kundengruppen dazunehmen.',
    'subscriptionBillingMethodMissing' => 'Shop „%shop%" verkauft Abos, hat aber keine Zahlungsart „Auf Rechnung". Folgebestellungen werden damit abgerechnet — ohne sie entsteht keine, und der Kunde nutzt weiter, was niemand in Rechnung stellt. In der Shop-Konfiguration unter Zahlungsarten eine vom Typ „Auf Rechnung" auswählen; ihre Einschränkungen (Gruppen, Land, Höchstbetrag) gelten für Folgebestellungen nicht.',
    'startPointForwardsToProductPage' => 'Der Startpunkt „%root%" leitet auf seine erste Seite weiter, und das ist die Produktseite „%page%". Ohne Produkt hat sie keine Adresse — die Domain des Startpunkts antwortet mit einem Fehler (500). In der Seitenstruktur eine andere Seite an die erste Stelle unter den Startpunkt setzen, etwa die Startseite des Shops.',
    'startPointWithoutDomain' => 'Startpunkte des Shops „%shop%" ohne Domain: %roots%. Mails aus dem Cron und von der Konsole — Abo-Rechnungen, „Ihre digitalen Inhalte" — verlinken dann auf localhost, weil dort kein Aufruf den Host verrät. Am Startpunkt unter „Domainname" die Domain eintragen oder in der Installation framework.router.default_uri setzen.',
    'subscriptionCountryChanged' => 'Abo-Kunden im Shop „%shop%" haben im Kundenkonto ein anderes Land eingetragen als auf ihrer letzten Rechnung: %numbers%. Folgerechnungen gehen weiter an die bisherige Anschrift — ein Landwechsel ändert Steuer und gegebenenfalls Reverse Charge; bitte mit dem Kunden klären.',
    'decisionNotSent' => 'Entscheidungen über Kündigungen im Shop „%shop%", deren Mail nicht hinausging: %count% (%numbers%). Der Kunde weiß nicht, wie seine Kündigung entschieden wurde. Den Grund nennt das Systemlog; ist er behoben, unter „Abos" → Kündigungen „Entscheidung erneut senden" wählen.',
    'cancellationsWaiting' =>'Außerordentliche Kündigungen im Shop „%shop%" warten auf Ihre Entscheidung: %count% (%numbers%), die erste seit %since%. Sie wirken erst, wenn Sie sie annehmen; annehmen oder ablehnen unter „Abos" → Kündigungen.',
    'registrationPathMissing' => 'Shop „%shop%" verkauft Abos, und ein Abo braucht ein Kundenkonto — aber kein Registrierungsmodul gibt neuen Konten die Kundengruppe, schaltet sie frei und erlaubt die Anmeldung. Contao legt neue Mitglieder gesperrt und ohne Anmeldung an. Am Registrierungsmodul „Anmeldung erlauben" und „Konten sofort freischalten" setzen, oder „Kontoaktivierung" mit der Kundengruppe des Shops unter „Standardgruppen".',
    'registrationPathMissingNoGuests' => 'Im Shop „%shop%" ist „Bestellung ohne Konto" aus, also braucht jeder Kauf ein Kundenkonto — aber kein Registrierungsmodul gibt neuen Konten die Kundengruppe, schaltet sie frei und erlaubt die Anmeldung. Neue Kunden können dann nicht bestellen. Am Registrierungsmodul „Anmeldung erlauben" und „Konten sofort freischalten" setzen, oder „Kontoaktivierung" mit der Kundengruppe des Shops unter „Standardgruppen". Legen Sie die Kundenkonten selbst an, ist nichts zu tun.',
    'subscriptionVatIdInvalid' => 'Abos im Shop „%shop%" wurden mit Reverse Charge gekauft, aber bei der letzten Abrechnung meldete VIES die UID als ungültig: %numbers%. Diese Folgebestellung wurde mit Umsatzsteuer berechnet. Bitte mit dem Kunden klären; die nächste Abrechnung prüft die UID erneut.',
    'netGroupVatIdLost' => 'Mitglieder, die über eine geprüfte UID automatisch in Nettopreis-Gruppen kamen, haben jetzt keine gültige UID mehr (ungültig oder gelöscht): %count% (%members%). Die Gruppen bleiben, bis Sie entscheiden. Unter „Mitglieder" die Gruppen entfernen — oder das Mitglied speichern, dann gelten sie als von Hand vergeben.',
    'customerPageLocksOut' => 'Die Seite „%page%" (Shop „%shop%") zeigt Kunden ihre Bestellungen oder Abos, ist aber geschützt, ohne diese Gruppen zuzulassen: %groups%. Deren Mitglieder bekommen „Zugriff verweigert", ohne Eintrag im Log. In der Seitenstruktur unter „Zugriffsschutz" die Gruppen ergänzen — auch auf der Elternseite, wenn der Schutz von dort kommt.',
    'orderPageProtected' => 'Die Bestellseite „%page%" (Shop „%shop%") ist geschützt, der Shop erlaubt aber Bestellungen ohne Konto. Ein Gast öffnet sie über den Link in der Bestätigungsmail und bekommt „Zugriff verweigert" statt seiner Bestellung. In der Seitenstruktur den „Zugriffsschutz" der Seite entfernen — auch auf der Elternseite, wenn der Schutz von dort kommt.',
    'loginPageMissing' => 'Shop „%shop%": Startpunkte mit geschützten Kundenseiten, aber ohne veröffentlichte 401-Seite: %roots%. Wer abgemeldet eine solche Seite öffnet, sieht Contaos allgemeine Fehlerseite statt der Anmeldung. Direkt unter dem Startpunkt eine Seite vom Typ „401 Nicht authentifiziert" mit einem Login-Modul anlegen und dort „Zur zuletzt besuchten Seite" setzen — sonst landet der Kunde nach der Anmeldung auf der Weiterleitungsseite des Moduls statt auf seiner Kundenseite.',
    'accountPasswordPageMissing' => 'Shop „%shop%": Startpunkte ohne Seite „Passwort vergessen": %roots%. Gäste können deshalb auf der Bestellseite kein Kundenkonto aus ihrer Bestellung anlegen. Legen Sie unter dem Startpunkt eine veröffentlichte, nicht geschützte Seite mit Contaos Modul „Passwort vergessen" an und wählen Sie sie am Startpunkt unter „Seite „Passwort vergessen"". Eine Seite unter einem anderen Startpunkt zählt nicht: Das Konto käme sonst in die Kundengruppe des anderen Shops.',
    'cancellationPageMissing' =>'Shop „%shop%" verkauft Abos, aber diese Startpunkte haben keine erreichbare Seite mit „Verträge hier kündigen": %roots%. Am Startpunkt unter „Seite ‚Verträge hier kündigen‘" eine veröffentlichte Seite ohne Anmeldepflicht wählen, auf der das Element „Verträge hier kündigen" steht. Ob § 312k BGB für den Shop gilt, beurteilen Sie.',
    'mandateMethodUnavailable' => 'Abos im Shop „%shop%" buchen über eine Zahlungsart ab, die nicht mehr verfügbar ist: %count% (%numbers%). Ihre Folgebestellungen gehen „Auf Rechnung". Ist die Zahlungsart wieder veröffentlicht und dem Shop zugeordnet, wird wieder abgebucht.',
    'subscriptionsSuspended' => 'Ruhende Abos im Shop „%shop%": %count% (%numbers%). Eine Rechnung ist über Zahlungsziel und Nachfrist offen, der Zugang ruht. Consho kündigt nicht und mahnt nicht; sobald die Zahlung in der Bestellung erfasst ist, lebt das Abo von selbst wieder auf.',
    'stockLow' => 'Bestand knapp oder leer: %count% Artikel — %products% (in Klammern, was noch frei ist). Bei 0 steht der Artikel als „derzeit nicht verfügbar" im Shop. Nachbestellen und den Bestand am Artikel buchen; bei Schlüsseln neue Codes unter „Schlüssel einfügen".',
    'digitalFileMissing' => 'Artikel mit Auslieferung „Datei", aber ohne abrufbare Datei: %count% (%products%). Keine Datei gewählt, die Datei fehlt, oder sie liegt in einem öffentlichen Ordner. Bestellungen dieser Artikel werden erst freigegeben, wenn die Datei da ist.',
    'deliveryTypeNotReady' => 'Eine Auslieferung kann nicht ausliefern: %reason% Betroffene veröffentlichte Artikel: %count%. Ihre Bestellungen werden erst freigegeben, wenn das behoben ist.',
    'deliveryArticleNotReady' => 'Artikel, die nicht ausliefern können: %count% (%products%). %reason% Bestellungen dieser Artikel werden erst freigegeben, wenn das behoben ist.',
    'paymentFeeTaxMissing' => 'Die Zahlungsart „%method%" verlangt eine Gebühr, aber es ist nicht gewählt, wie sie versteuert wird. Solange wird sie im Checkout nicht angeboten. In der Zahlungsart unter „Gebühr" „anteilig" oder „fest" mit Steuerklasse wählen — ob eine Zahlungsgebühr Nebenleistung oder eigene Leistung ist, entscheidet Consho nicht.',
    'invoiceOverdue' => 'Bestellungen im Shop „%shop%" über dem Zahlungsziel und nicht bezahlt: %count% (%numbers%). Consho mahnt nicht von selbst. Zahlungseingang in der Bestellung erfassen oder beim Kunden nachfragen.',
    'prepaymentUnpaid' => 'Bestellungen per Vorkasse im Shop „%shop%" seit mehr als %days% Tagen unbezahlt: %count% (%numbers%). Sie halten ihren Bestand, bis sie bezahlt oder storniert sind — Consho storniert nicht von selbst. Zahlungseingang erfassen, beim Kunden nachfragen oder die Bestellung stornieren.',
    'senderDomainMismatch' => 'Die Shop-Konfiguration „%shop%" verschickt ihre Mails von %sender%, ihre Startpunkte laufen unter %domains%. Eine Mail, deren Links alle auf eine andere Domain führen als der Absender, sieht für Spamfilter aus wie Phishing. Wenn es geht, einen Absender unter der Domain des Shops wählen.',
    'refundRangeMissing' => 'Der Shop-Konfiguration „%shop%" fehlen Nummernkreise für Erstattungen: %types%. Ohne sie lässt sich eine Erstattung nach einem Widerruf nicht buchen.',
    'withdrawalPeriodShort' => 'Die Shop-Konfiguration „%shop%" hat eine Widerrufsfrist von %days% Tagen und liefert in die EU oder den EWR (%countries%). Verbrauchern dort stehen gesetzlich 14 Tage zu; 0 Tage schaltet die Widerrufsfunktion ganz ab. Prüfen Sie die Einstellung unter „Widerruf".',
    'legalTextUnreachable' => 'Rechtstexte, die der Kunde nicht lesen kann: %count% (%pages%) — betroffen: %kinds%. Das gewählte Element gibt es nicht mehr, ist kein Textelement mehr, steht auf unsichtbar, liegt auf einer unveröffentlichten oder geschützten Seite oder außerhalb seines Zeitfensters. Ein Text hinter einer Anmeldung gilt als nicht erteilt.',
    'warrantyNoticeMissing' => 'Startpunkte ohne erreichbare Seite für die Gewährleistungs-Mitteilung: %count% (%pages%). Seit 27.09.2026 muss die harmonisierte EU-Mitteilung zum gesetzlichen Gewährleistungsrecht in jedem Shop gut sichtbar sein (§ 4 Abs. 1 Z 12 FAGG, Gestaltung verbindlich in der Durchführungsverordnung (EU) 2025/1960). Legen Sie eine Seite mit der unveränderten Grafik der Kommission in der Sprache dieses Startpunkts an — samt klickbarem Link auf europa.eu — und wählen Sie sie hier. Die Grafik bringt Consho nicht mit: Sie ist amtlich, darf nicht verändert werden und wird von der Kommission je Sprache herausgegeben.',
    'warrantyNoticeFileMissing' => 'Startpunkte ohne Gewährleistungs-Mitteilung als Datei: %count% (%pages%). Die Bestellbestätigung muss die harmonisierte EU-Mitteilung enthalten (Art. 8 Abs. 7 RL 2011/83/EU, § 7 Abs. 3 FAGG), Consho hängt sie an. Wählen Sie am Startpunkt unter „Gewährleistungs-Mitteilung als Datei" die unveränderte, farbige Fassung der Kommission in der Sprache dieses Startpunkts (PDF, PNG oder JPEG).',
    'confirmationNotSent' => 'Bestellungen im Shop „%shop%", deren Bestätigung nicht versendet wurde: %count% (%numbers%). Die Bestätigung mit AGB, Widerrufsbelehrung und Gewährleistungs-Mitteilung ist Pflicht (§ 7 Abs. 3 FAGG). Den Grund nennt das Systemlog; ist er behoben, in der Bestellung „Bestätigung erneut senden" wählen. Steht dort „was sent, but the time could not be recorded", ist die Mail schon beim Kunden — dann nicht erneut senden.',
    'operatorAddressIsSender' => 'Die Shop-Konfiguration „%shop%" schickt die Betreiberkopie an %address% — dieselbe Adresse, von der die Mails kommen. Microsoft 365 legt eine solche Mail nur unter „Gesendet" ab, die Kopie scheint dann nie anzukommen. Besser ein eigenes Postfach für die Kopien eintragen.',
    'testPaymentActive' => 'Der Test-Zahlungsanbieter ist eingeschaltet (Umgebungsvariable CONSHO_TEST_PAYMENT=1). Er bestätigt Zahlungen, ohne Geld anzunehmen. Auf einem echten Shop entfernen Sie die Variable aus der .env.local.',
    'privacyPageMissing' => 'Startpunkte ohne erreichbare Datenschutzseite: %count% (%pages%). Sie ist nicht gewählt oder die gewählte Seite ist unveröffentlicht. Art. 13 DSGVO verlangt die Information dort, wo die Daten erhoben werden — im Checkout. Die Seite wird am Startpunkt eingestellt, in der Legende „Consho".',
    'invoiceSenderIncomplete' => 'Der Rechnungsabsender der Shop-Konfiguration „%shop%" ist unvollständig. Es fehlen %count% Angaben: %fields%. Ohne vollständigen Namen und Anschrift des Ausstellers ist die Rechnung nach § 11 UStG (Österreich) bzw. § 14 UStG (Deutschland) mangelhaft, und geschrieben wird sie beim Kauf.',
    'numberPrefixShared' => 'Mehrere Belegnummernkreise verwenden das Präfix „%prefix%": %ranges%. Sie vergeben dieselben Nummern doppelt, sobald ihre Zähler sich treffen. Gehören sie zu einem Unternehmen, bekommt jeder Kreis sein eigenes Präfix; sind es getrennte Unternehmen, ist das gleiche Präfix unbedenklich.',
    'numberRangeMissing' => 'Der Shop-Konfiguration „%shop%" fehlen %count% Nummernkreise: %types%. Jede Bestellung braucht eine Bestellnummer, und die gewählten Zahlungsarten stellen Rechnungen aus — bei der Bestellung (Vorkasse), bei Zahlung oder beim Versand (Auf Rechnung). Ohne Nummernkreis bricht genau dieser Schritt ab: der Kauf oder die Meldung von Zahlung oder Versand. Den Storno-Kreis braucht es mit, sobald es Rechnungen gibt: Ein Wechsel der Zahlungsart storniert die erste Rechnung, und beide Nummernfolgen müssen lückenlos sein.',
    'invoiceArchiveFailed' => 'Rechnungen, deren Ablage gescheitert ist: %count% (%numbers%). Bestellung und Rechnung bestehen; beim Abruf wird das PDF neu erzeugt und als neu erzeugt gekennzeichnet. Den Grund nennt das Fehlerprotokoll. Nach der Behebung legt „consho:document:verify --retry" sie nach.',
    'invoiceArchiveFolderUnsafe' => 'Die Shop-Konfiguration „%shop%" legt Rechnungen ab, aber der Ablageordner %problem%. Solange das so ist, wird keine Rechnung abgelegt — in einen öffentlichen Ordner schreibt Consho nie, weil jede Rechnung dann für alle abrufbar wäre.',
    'reverseChargeNoteMissing' => 'Die Shop-Konfiguration „%shop%" kann Rechnungen mit Reverse Charge ausstellen (UID-Nummer und Lieferländer außerhalb des Heimatlands), hat aber keinen Hinweis dafür. Eine solche Rechnung ginge ohne Hinweis hinaus. Den Wortlaut legt der Betreiber fest, am Shop unter „Hinweis bei Reverse Charge".',
    'deliveryTimeMissing' => 'Die Shop-Konfiguration „%shop%" stellt mit einer gewählten Zahlungsart die Rechnung schon beim Kauf aus (etwa Vorkasse), hat aber keine Lieferzeit. Eine solche Rechnung entsteht vor dem Versand und soll den voraussichtlichen Lieferzeitraum nennen; ohne Lieferzeit bleibt die Angabe leer. Am Shop unter „Lieferzeit ab/bis (Werktage)" eintragen.',
    'noticeParamStripped' => 'Die Umgebungsvariable QUERY_PARAMS_ALLOW_LIST ist gesetzt und lässt diese Parameter nicht durch: %param%. Der geteilte Cache entfernt sie, bevor die Anfrage ankommt — der Checkout kommt dann nicht über den ersten Schritt, jeder Link zur Bestellseite endet mit 404, und eine abgelehnte Hinzufügung erscheint wortlos. Tragen Sie „consho_.*" in die Liste ein.',
];

// The parts of the finding "product does not fit its kind" (DL5).
$GLOBALS['TL_LANG']['CONSHO']['kindMismatch'] = [
    'kinds' => ['physical' => '„Ware"', 'digital' => '„Digitales"', 'service' => '„Dienstleistung"', 'voucher' => '„Wertgutschein"'],
    'delivery' => 'eine Auslieferungsart',
    'file' => 'eine Datei',
    'keys' => 'Schlüssel im Vorrat',
    'subscription' => 'den Verkauf im Abo',
    'durability' => 'eine Haltbarkeitsgarantie (GARAN-Label)',
    'and' => ' und ',
];

/*
 * Messages from save callbacks. They appear right at the field.
 */
$GLOBALS['TL_LANG']['CONSHO']['errors'] = [
    'facetStateEmpty' => '„%s" ergibt keine gültige Filterkombination. Erwartet wird Gruppe:Option, mehrere getrennt durch Semikolon — zum Beispiel colour:red;volume:250ml.',
    'facetStateSorted' => '„%s" enthält eine Sortierung. Eine sortierte Ansicht zeigt dieselben Produkte in anderer Reihenfolge und bekommt deshalb nie eine eigene Adresse.',
    'facetStateAvailable' => '„%s" enthält „Nur verfügbare". Was verfügbar ist, ändert sich mit dem Lager — eine solche Ansicht bekommt keine eigene Adresse.',
    'facetStateDuplicate' => 'Diese Filterkombination ist auf dieser Seite bereits als „%s" freigegeben. Zwei Adressen für dieselbe Auswahl wären zwei Seiten mit identischem Inhalt.',
    'facetStateUnknown' => '„%s" nennt eine Gruppe oder Option, die es nicht gibt oder nach der Besucher nicht filtern können (Gruppe nicht als filterbar markiert). Die Landingpage zeigte sonst unter eigenem Titel die ganze Kategorie.',
    'facetStateOutsideCategory' => '„%s" liegt außerhalb der Produktauswahl dieser Listenseite. Eine Landingpage grenzt innerhalb ihrer Kategorie ein — diese Kombination enthielte dort kein einziges Produkt.',
    'facetStateMatchesCategory' => '„%s" entspricht genau der Filtervorgabe dieser Kategorie. Diese Kombination zeigt dieselben Produkte wie die Kategorie selbst und braucht deshalb keine eigene Adresse.',
    'facetPageWithoutListing' => 'Die Seite mit der ID %s trägt keine sichtbare Produktliste, die der Adresse folgt. Unter ihr wäre die Landingpage nicht erreichbar. Bitte eine Listenseite wählen.',
    'facetAliasForm' => '„%s" ist als Adresse nicht zulässig. Erlaubt sind Kleinbuchstaben, Ziffern und Bindestriche — dieselbe Form wie in Filteradressen.',
    'facetAliasDuplicate' => 'Die Adresse „%s" ist auf dieser Seite schon an die Landingpage mit der ID %s vergeben.',
    'facetAliasShadowed' => 'Die Seite „%s" (ID %s) antwortet bereits unter %s. Sie verdeckt diese Landingpage: gespeichert wäre sie, erreichbar nie. Bitte eine andere Adresse wählen.',
    'gtinInvalid' => '„%s" ist keine gültige GTIN. Erwartet werden 8, 12, 13 oder 14 Ziffern mit stimmender Prüfziffer — eine vertippte Nummer bezeichnet einen fremden Artikel.',
    'variantSelf' => 'Ein Produkt kann keine Variante von sich selbst sein.',
    'variantHasVariants' => 'Dieses Produkt hat selbst Varianten (Anzahl: %s) und kann deshalb keine Variante werden. Die vorhandenen Varianten hätten sonst keinen Hauptartikel mehr.',
    'pathTemplateUnknownPlaceholder' => '„%s" ist kein bekannter Platzhalter. Erlaubt sind {alias}, {sku}, {manufacturer} und {type} — also nur Angaben, von denen jedes Produkt genau eine hat. Eine Kategorie gehört ausdrücklich nicht dazu: Ein Produkt steht in mehreren, und die Adresse wäre nicht mehr eindeutig.',
    'pathTemplateNoIdentifier' => 'Die Adressvorlage braucht {alias}. Nur der Alias ist je Produkt eindeutig; ohne ihn teilen sich Produkte eine Adresse. {sku} darf zusätzlich vorkommen, etwa {sku}/{alias}.',
    'pathTemplateRepeated' => 'Der Platzhalter {%s} kommt mehrfach vor. Die Adresse würde dieselbe Angabe zweimal enthalten.',
    'pathTemplateEmpty' => 'Die Adressvorlage darf nicht leer sein. Der kürzeste sinnvolle Wert ist {alias}.',
    'translationAliasRequired' => 'In der Sprache „%s" ist etwas ausgefüllt, aber weder Alias noch Bezeichnung. Ohne Alias ist der Eintrag in dieser Sprache nicht erreichbar — bitte einen Alias oder eine Bezeichnung eintragen, aus der er erzeugt wird, oder den Abschnitt ganz leeren.',
    'shopCurrenciesEmpty' => 'Der Shop braucht mindestens eine Währung. Ohne Angabe könnte kein Startpunkt eine auswählen — und es gibt keinen stillen Vorgabewert: Euro anzunehmen, wo niemand es gesagt hat, wäre schlimmer als die Rückfrage.',
    'shopCurrencyUnknown' => '„%s" ist kein gültiger ISO-4217-Code. Ein alter oder von Hand eingetragener Wert wird von Contao als unbekannte Option wieder angeboten — hier endet er.',
    'rootCurrencyNotAllowed' => 'Die Shop-Konfiguration „%2$s" nimmt „%1$s" nicht an. Contao bietet eine einmal gespeicherte Währung auch dann wieder an, wenn sie am Shop gestrichen wurde — hier endet sie. Entweder eine der angebotenen Währungen wählen oder „%1$s" in „%2$s" wieder zulassen.',
    'rootCurrencyWithoutShop' => 'Diese Seite hat keine Shop-Konfiguration, deshalb gilt hier auch keine Währung — „%1$s" kann nicht gespeichert werden. Zuerst im Startpunkt einen Shop wählen; welche Währungen dann zur Wahl stehen, ergibt sich aus ihm.',
    'priceCurrencyWithoutShop' => 'In der Währung „%s" verkauft kein Shop. Eine Preiszeile braucht die Währung einer Shop-Konfiguration mit Heimatland — Währung wählen oder zuerst den Shop anlegen.',
    'priceCountryMissing' => 'In der Währung %1$s verkaufen Shops aus %2$s. Bitte eines dieser Länder wählen — mit dessen Steuersatz wird der Bruttobetrag in netto umgerechnet.',
    'priceTaxClassMissing' => 'Das Produkt hat keine Steuerklasse. Ohne sie lässt sich ein Bruttobetrag nicht in netto umrechnen — Steuerklasse am Produkt eintragen oder den Betrag netto eingeben.',
    'priceTaxRateMissing' => 'Für die Steuerklasse des Produkts ist in %s am %s kein Steuersatz hinterlegt. Den Satz unter „Steuern" anlegen oder den Betrag netto eingeben.',
    'priceNegative' => 'Ein Preis kann nicht negativ sein. Rabatte gehören nicht in die Preiszeile.',
    'priceInvalid' => 'Bitte den Betrag als Zahl eingeben, z. B. 12.90.',
    'priceOverrideNeedsMode' => 'Den Betrag bitte zusammen mit „Eingabe als" überschreiben — sonst gälte dieselbe Zahl in einer Zeile als brutto, in der nächsten als netto.',
    'priceTooLarge' => 'Der Betrag ist zu groß: Gespeichert werden höchstens 99.999.999,9999 netto.',
    'priceAmountMissing' => 'Ohne Betrag lässt sich die neue Preiszeile nicht speichern. Fehlt das Feld „Betrag" in der Maske, fehlen der Benutzergruppe Feldrechte: Eine Administratorin oder ein Administrator muss Währung, Eingabe als, Betrag und Land gemeinsam erlauben.',
    'shippingPriceTaxClassMissing' => 'Die Versandart hat keine Steuerklasse. Ohne sie lässt sich ein Bruttobetrag nicht in netto umrechnen — die Steuerklasse an der Versandart setzen oder den Betrag netto eingeben.',
    'shippingPriceDuplicate' => 'Diese Versandart hat schon einen Betrag in %s (bei einer Gewichtsstaffel: für diese Stufe). Je Währung und Stufe gilt genau einer — einen davon bearbeiten oder löschen.',
    'ibanInvalid' => '„%s" ist keine gültige IBAN — Länge oder Prüfziffer passen nicht. Eine vertippte IBAN auf der Rechnung schickt das Geld auf ein fremdes Konto.',
];

/*
 * Heading of the section per translation language, the same in all five
 * translated tables — hence here and not five times in the table files.
 */
$GLOBALS['TL_LANG']['CONSHO']['translationLegend'] = 'Übersetzung: %s';

// A product's tax class changed: the price rows typed as gross keep their gross.
$GLOBALS['TL_LANG']['CONSHO']['taxClassChange'] = [
    'converted' => 'Neue Steuerklasse: Diese brutto eingegebenen Preise bleiben brutto gleich, ihr Nettobetrag wurde neu berechnet: %s.',
    'unconverted' => 'Neue Steuerklasse: Diese brutto eingegebenen Preise ließen sich nicht umrechnen, weil ein Steuersatz oder das Heimatland fehlt, und behalten ihren Nettobetrag: %s. Bitte die Preiszeilen prüfen.',
    'net' => '%s netto',
];

/*
 * Label of the button on a notice. Only notices carry it — warnings cannot
 * be dismissed.
 */
$GLOBALS['TL_LANG']['CONSHO']['acknowledge'] = 'Zur Kenntnis genommen';

$GLOBALS['TL_LANG']['CONSHO']['deliverability'] = [
    'senderAddressInvalid' => 'Die Absenderadresse ist keine gültige E-Mail-Adresse.',
    'spfMissing' => 'Für %domain% gibt es keinen SPF-Eintrag. Mails werden voraussichtlich gefiltert oder abgewiesen.',
    'dmarcMissing' => 'Für %domain% gibt es keinen DMARC-Eintrag.',
    'alignmentMismatch' => 'Die Absenderdomain (%domain%) passt nicht zur authentifizierten Domain (%authenticated%). Das ist die häufigste Ursache dafür, dass Mails im Spam landen.',
    'dkimNotVerifiable' => 'DKIM lässt sich nicht prüfen: Der Eintrag liegt unter <selektor>._domainkey.%domain%, und der Selektor ist aus der Adresse nicht ableitbar.',
];

/*
 * Label of the page type. Contao mixes the registered types into the
 * selection (PageTypeOptionsListener); without this entry the raw key would
 * stand there.
 */
$GLOBALS['TL_LANG']['PTY']['consho_product'] = ['Consho Produktseite', 'Die Seite, unter der alle Produktdetailseiten liegen. Die Adresse je Produkt bestimmt die Adressvorlage an dieser Seite.'];

$GLOBALS['TL_LANG']['CTE']['consho'] = 'Consho';
$GLOBALS['TL_LANG']['CTE']['consho_product_list'] = ['Produktliste', 'Zeigt Produkte, die der gewaehlten Eigenschaftsauswahl entsprechen.'];
$GLOBALS['TL_LANG']['CTE']['consho_product_filter'] = ['Produktfilter', 'Filter als Links zur Produktliste der Seite, mit Trefferzahlen. Steht meist in einem Layout-Bereich und bedient dann alle Kategorieseiten.'];
$GLOBALS['TL_LANG']['CONSHO']['listEmpty'] = 'In dieser Auswahl gibt es derzeit keine Produkte.';
$GLOBALS['TL_LANG']['CONSHO']['filterAvailableOnly'] = 'Nur verfügbare';
$GLOBALS['TL_LANG']['CTE']['consho_product_pin'] = ['Anheftung', 'Stellt ein Produkt an den Anfang der Produktliste. Nur innerhalb einer Produktliste anlegbar.'];
$GLOBALS['TL_LANG']['CONSHO']['pinLabel'] = 'Angeheftet: %s';
$GLOBALS['TL_LANG']['CONSHO']['pinMissing'] = 'Das Produkt mit der ID %s gibt es nicht als Hauptprodukt. Diese Anheftung zeigt nichts.';

$GLOBALS['TL_LANG']['CONSHO']['sort'] = [
    'title' => 'Bezeichnung',
    'price_asc' => 'Preis aufsteigend',
    'price_desc' => 'Preis absteigend',
    'newest' => 'Neueste zuerst',
    'sorting' => 'Eigene Reihenfolge',
];
$GLOBALS['TL_LANG']['CONSHO']['priceFrom'] = 'ab';
// Block H (H-2): badge and note next to the struck-through reference price. The wording
// is the operator's (§ 9a PrAG, § 11 PAngV require the price, not the words).
$GLOBALS['TL_LANG']['CONSHO']['promotion']['badge'] = 'Aktion';
$GLOBALS['TL_LANG']['CONSHO']['promotion']['reference'] = 'niedrigster Preis der letzten 30 Tage';

$GLOBALS['TL_LANG']['CTE']['consho_product_detail'] = ['Produktdetail', 'Zeigt das Produkt der Produktseite: Bilder, Preis, Varianten, Warnhinweise und Herstellerangaben. Auf anderen Seiten bleibt das Element leer.'];

// Placeholders: amount, reference quantity, unit symbol.
$GLOBALS['TL_LANG']['CONSHO']['basePrice'] = '%1$s / %2$s %3$s';
$GLOBALS['TL_LANG']['CONSHO']['unitSymbols'] = [
    'kg' => 'kg',
    'g' => 'g',
    'l' => 'l',
    'ml' => 'ml',
    'm' => 'm',
    'cm' => 'cm',
    'm2' => 'm²',
    'stk' => 'Stk.',
];
$GLOBALS['TL_LANG']['CONSHO']['priceTaxNotice'] = 'inkl. USt., zzgl. Versandkosten';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxNoticeNet'] = 'zzgl. USt., zzgl. Versandkosten';
// Without shipping: digital products and services (DL).
$GLOBALS['TL_LANG']['CONSHO']['priceTaxOnly'] = 'inkl. USt.';
// WG-7: not taxable (gift voucher) — no tax rate, only the shipping.
$GLOBALS['TL_LANG']['CONSHO']['priceShippingOnly'] = 'zzgl. Versandkosten';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxOnlyNet'] = 'zzgl. USt.';
$GLOBALS['TL_LANG']['CONSHO']['ownPriceGroup'] = 'Sie sind als %s angemeldet — Ihren Preis sehen Sie auf der Produktseite.';
$GLOBALS['TL_LANG']['CONSHO']['scaleQuantity'] = 'Menge';
$GLOBALS['TL_LANG']['CONSHO']['scalePrice'] = 'Preis je Stück';
$GLOBALS['TL_LANG']['CONSHO']['scaleFrom'] = 'ab %s';
$GLOBALS['TL_LANG']['CONSHO']['minimumAge'] = 'Abgabe ab %s Jahren';
// Block GARAN: the label's content in words (§ 4 Abs. 1 Z 12a FAGG) — the
// guarantee, its duration, and that the legal guarantee applies as well.
$GLOBALS['TL_LANG']['CONSHO']['garan']['alt'] = 'GARAN – Haltbarkeitsgarantie des Herstellers: %s Jahre. Ihr gesetzliches Gewährleistungsrecht besteht daneben.';
$GLOBALS['TL_LANG']['CONSHO']['garan']['more'] = 'Mehr zur Haltbarkeitsgarantie (Ihr Europa)';
$GLOBALS['TL_LANG']['CONSHO']['garan']['open'] = 'GARAN-Label in voller Größe öffnen';
$GLOBALS['TL_LANG']['CONSHO']['detail'] = [
    'sku' => 'Artikelnummer',
    'gtin' => 'GTIN',
    'mpn' => 'Herstellernummer',
    'productSafety' => 'Produktsicherheit',
    'manufacturer' => 'Hersteller',
    'euResponsible' => 'Verantwortliche Person in der EU',
    'safetyInformation' => 'Sicherheitsinformationen',
    // ZW-4: the product type's custom fields (Z3).
    'customFields' => 'Angaben',
    'yes' => 'Ja',
    'no' => 'Nein',
];
$GLOBALS['TL_LANG']['CTE']['consho_cart'] = ['Warenkorb', 'Zeigt den Warenkorb des Besuchers mit Mengen und Summen. Die Seite wird dadurch privat und nicht zwischengespeichert.'];

$GLOBALS['TL_LANG']['CONSHO']['cart'] = [
    'quantity' => 'Menge',
    // Placeholder: the title of the line. The visible column heading is still
    // just "Menge" — this text is the label of the single field, which without
    // the title would read the same in every line.
    'quantityOf' => 'Menge: %s',
    // Abo-7b (P67): a line from "Lizenz verlängern".
    'renews' => 'verlängert Abo %s — Lizenz und Schlüssel bleiben',
    'quantityHint' => 'Mindestmenge %1$s, Schrittweite %2$s.',
    'add' => 'In den Warenkorb',
    'empty' => 'Ihr Warenkorb ist leer.',
    'article' => 'Artikel',
    'lineTotal' => 'Summe',
    'total' => 'Gesamt',
    'actions' => 'Aktionen',
    'update' => 'Übernehmen',
    'remove' => 'Entfernen',
    'noPrice' => 'Kein Preis',
    'unknownArticle' => 'Nicht mehr verfügbarer Artikel',
    'unpriced' => 'Für einen Artikel im Warenkorb gibt es derzeit keinen Preis. Bitte entfernen Sie ihn, um fortzufahren.',
    'notice' => [
        'added' => 'Der Artikel wurde in den Warenkorb gelegt.',
        'unavailable' => 'Dieser Artikel ist derzeit nicht erhältlich.',
        'needs_choice' => 'Bitte wählen Sie zuerst eine Ausführung.',
        'invalid_quantity' => 'Diese Menge ist nicht möglich.',
        'not_offered' => 'Dieses Modell ist für Sie nicht erhältlich. Bitte wählen Sie ein anderes.',
        'out_of_stock' => 'Dieser Artikel ist derzeit nicht verfügbar.',
        'too_many_codes' => 'Zu viele ungültige Codes. Bitte versuchen Sie es in einer Viertelstunde erneut.',
        'voucher_unknown' => 'Diesen Gutscheincode kennen wir nicht. Bitte prüfen Sie die Eingabe.',
        'too_many_vouchers' => 'Mehr Gutscheine lassen sich in einem Einkauf nicht einlösen.',
    ],
];

$GLOBALS['TL_LANG']['CTE']['consho_mini_cart'] = ['Mini-Warenkorb', 'Anzahl und Summe des Warenkorbs, auch in einem Layout-Bereich. Wird als ESI-Fragment geladen und nimmt die Seite deshalb nicht aus dem Cache.'];

/*
 * Both texts stand invisibly next to number and amount: a "2" and an amount
 * without a word cannot be made sense of with a screen reader.
 */
$GLOBALS['TL_LANG']['CONSHO']['miniCart'] = [
    'label' => 'Warenkorb',
    'lines' => 'Positionen',
];


/*
 * The checkout (C-5). The step names are deliberately not the block names:
 * with one step it is called "Bestellung", with four they are named one by
 * one — the visitor reads a number, not an architecture.
 */
$GLOBALS['TL_LANG']['CONSHO']['checkout'] = [
    'empty' => 'Ihr Warenkorb ist leer. Legen Sie etwas hinein, um zu bestellen.',
    /*
     * The late double click (F2): the order exists, the cart does not any more.
     * Deliberately without order number and without link — this page cannot
     * prove who is asking, and the confirmation is on its way by mail.
     */
    'alreadyOrdered' => 'Ihre Bestellung ist bereits eingegangen. Die Einzelheiten stehen in Ihrer Bestätigungsmail – doppelt bestellt wurde nichts.',
    'steps' => 'Schritte der Bestellung',
    /*
     * One name per block group, not per number: which groups exist is decided
     * by CheckoutBlock::steps(), and a numbered name would be wrong with any
     * other number of steps.
     */
    'step' => [
        'address_shipping_payment_review' => 'Bestellung',
        'address_shipping_payment' => 'Ihre Angaben',
        'shipping_payment' => 'Versand und Zahlung',
        'address' => 'Adresse',
        'shipping' => 'Versandart',
        'payment' => 'Zahlungsart',
        'review' => 'Prüfen und kaufen',
    ],
    'choose' => 'Bitte wählen',
    'free' => 'kostenlos',
    'save' => 'Weiter',
    'unavailable' => 'derzeit nicht möglich',
    'shipping' => 'Versandart',
    'payment' => 'Zahlungsart',
    'review' => 'Prüfen und kaufen',
    'login_required' => 'Für diesen Shop ist eine Anmeldung nötig. Melden Sie sich an, um fortzufahren.',
    'reverse_charge' => 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge)',
    'age' => 'Dieser Warenkorb enthält Artikel ab %s Jahren.',
    'address' => [
        'billing' => 'Rechnungsadresse',
        'shipping' => 'Lieferadresse',
        'separate' => 'Abweichende Lieferadresse angeben',
    ],
    'total' => [
        'net' => 'Gesamt netto',
        'gross' => 'Gesamtsumme (inkl. USt.)',
        'plain' => 'Gesamtsumme',
    ],
    'vat' => [
        'recheck' => 'UID erneut prüfen',
    ],
    'field' => [
        'company' => 'Firma',
        'salutation' => 'Anrede',
        'firstname' => 'Vorname',
        'lastname' => 'Nachname',
        'street' => 'Straße und Hausnummer',
        'postal' => 'PLZ',
        'city' => 'Ort',
        'state' => 'Bundesland',
        'country' => 'Land',
        'phone' => 'Telefon',
        'email' => 'E-Mail',
        'vatId' => 'UID-Nummer',
    ],
    'terms_accept' => 'Ich habe die AGB gelesen und stimme ihnen zu',
    'legal' => [
        'intro' => 'Bitte beachten Sie:',
        'terms' => 'AGB',
        'withdrawal' => 'Widerrufsbelehrung',
        'warranty' => 'Gewährleistung',
        'privacy' => 'Datenschutz',
        'newWindow' => ' (öffnet in einem neuen Fenster)',
    ],
    /*
     * The three permitted labels of the purchase button. Which one applies is
     * set on the shop; that there are only these three is in BuyButtonLabel.
     */
    'buy' => [
        'paymentRequired' => 'Zahlungspflichtig bestellen',
        'chargeable' => 'Kostenpflichtig bestellen',
        ],
    'purchase' => [
        'checksumMismatch' => 'In der Zwischenzeit hat sich etwas an Ihrer Bestellung geändert. Bitte prüfen Sie die Angaben und bestätigen Sie erneut.',
        'termsNotAccepted' => 'Bitte stimmen Sie den AGB zu, um zu bestellen.',
        'noOrderPage' => 'Dieser Shop kann Bestellungen noch nicht bestätigen. Es wurde nichts bestellt — bitte wenden Sie sich an uns.',
        'couldNotPlace' => 'Ihre Bestellung konnte gerade nicht abgeschlossen werden, und es wurde nichts berechnet. Bitte versuchen Sie es gleich noch einmal oder wenden Sie sich an uns.',
        'stockShort' => 'Ein Artikel ist nicht mehr in der gewünschten Menge lieferbar. Es wurde nichts bestellt — bitte passen Sie die Menge im Warenkorb an.',
        'discountExhausted' => 'Der Rabatt wurde in der Zwischenzeit aufgebraucht. Es wurde nichts bestellt — bitte prüfen Sie Ihre Bestellung ohne ihn.',
        'voucherChanged' => 'Ein Gutschein hat sich in der Zwischenzeit geändert. Es wurde nichts bestellt — bitte prüfen Sie, was Ihre Gutscheine jetzt decken.',
    ],
    'problem' => [
        'basketEmpty' => 'Ihr Warenkorb ist leer.',
        'unpricedLine' => 'Für einen Artikel im Warenkorb gibt es derzeit keinen Preis. Bitte entfernen Sie ihn.',
        'addressIncomplete' => 'Bitte vervollständigen Sie Ihre Adresse.',
        'deliveryCountryNotServed' => 'In dieses Land liefern wir derzeit nicht.',
        'vatIdInvalid' => 'Diese UID-Nummer ist nicht gültig.',
        'vatServiceUnavailable' => 'Die UID konnte gerade nicht geprüft werden. Sie können mit Steuer bestellen oder die Prüfung wiederholen.',
        'noShippingMethod' => 'Für diese Bestellung steht derzeit keine Versandart zur Verfügung.',
        'shippingMethodGone' => 'Die gewählte Versandart ist nicht mehr möglich. Bitte wählen Sie erneut.',
        'noPaymentMethod' => 'Für diese Bestellung steht derzeit keine Zahlungsart zur Verfügung.',
        'paymentMethodGone' => 'Die gewählte Zahlungsart ist nicht mehr möglich. Bitte wählen Sie erneut.',
        'ageNotVerifiable' => 'Dieser Warenkorb enthält altersbeschränkte Artikel, die wir so nicht versenden dürfen.',
        'dateOfBirthMissing' => 'Für diese Versandart brauchen wir Ihr Geburtsdatum.',
        'tooYoung' => 'Für die Artikel in diesem Warenkorb sind Sie noch nicht alt genug.',
        'stockShort' => 'Ein Artikel im Warenkorb ist nicht in der gewünschten Menge lieferbar. Bitte passen Sie die Menge im Warenkorb an.',
    ],
];

$GLOBALS['TL_LANG']['CTE']['consho_checkout'] = ['Checkout', 'Der Bestellvorgang in ein bis vier Schritten. Die Seite wird dadurch privat und nicht zwischengespeichert.'];
$GLOBALS['TL_LANG']['CTE']['consho_order'] = ['Bestellseite', 'Zeigt eine Bestellung über den Link aus der Bestätigung: Stand, Belege, Zahlungsart ändern. Gehört auf die Bestellseite des Startpunkts. Ohne gültigen Link bietet sie an, einen neuen an die Adresse der Bestellung zu schicken.'];
$GLOBALS['TL_LANG']['CTE']['consho_my_subscriptions'] = ['Meine Abos', 'Die Abos des angemeldeten Kunden in diesem Shop: Stand, nächste Abrechnung, Ende; Link zur Bestellung und „Kündigen". Gehört auf eine geschützte Seite des Kontobereichs. Ersetzt „Verträge hier kündigen" nicht — das muss ohne Anmeldung erreichbar sein.'];
$GLOBALS['TL_LANG']['CTE']['consho_cancellation'] =['Verträge hier kündigen', 'Die Kündigungsschaltfläche nach § 312k BGB: Abonummer, E-Mail und Name, dann „jetzt kündigen"; danach die Erklärung mit Datum und Uhrzeit zum Speichern. Gehört auf eine Seite des Startpunkts, die von jeder Seite aus erreichbar ist, etwa über die Fußzeile mit {{consho_cancellation_link}} — ohne Anmeldung.'];
$GLOBALS['TL_LANG']['CTE']['consho_withdrawal'] =['Vertrag widerrufen', 'Die Widerrufsfunktion für Käufer ohne die Bestellmail: Bestellnummer, E-Mail und Name, dann „Widerruf bestätigen". Gehört auf die Seite „Vertrag widerrufen" des Startpunkts und sollte von jeder Seite aus erreichbar sein, etwa über die Fußzeile.'];

$GLOBALS['TL_LANG']['CONSHO']['checkout']['blocked'] = 'Eine Bestellung ist derzeit nicht möglich. Bitte versuchen Sie es später noch einmal.';

$GLOBALS['TL_LANG']['CONSHO']['cart']['checkout'] = 'Zur Kasse';

$GLOBALS['TL_LANG']['CONSHO']['tile']['purchase'] = '%s in den Warenkorb legen';
$GLOBALS['TL_LANG']['CONSHO']['tile']['alsoSubscription'] = 'auch im Abo';

$GLOBALS['TL_LANG']['CONSHO']['checkout']['requiredNote'] = 'Mit * gekennzeichnete Felder sind Pflichtfelder.';

$GLOBALS['TL_LANG']['CONSHO']['checkout']['address']['shippingHint'] = '— nur auszufüllen, wenn Sie oben eine abweichende Lieferadresse angehakt haben';

// The invoice (C-8). Labels only — every value comes from the snapshot.
$GLOBALS['TL_LANG']['CONSHO']['invoice']['types']['invoice'] = 'Rechnung';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['types']['cancellation'] = 'Stornorechnung';
// The number ranges as the form names them — here, because findings appear
// on the start page, where tl_consho_number_range is not loaded (D-3).
$GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'] = [
    'order' => 'Bestellnummer',
    'invoice' => 'Rechnungsnummer',
    'deliveryNote' => 'Lieferscheinnummer',
    'creditNote' => 'Rechnungskorrektur-Nummer',
    'cancellation' => 'Stornonummer',
];
// D-3, F2: not "Gutschrift" — for tax purposes that is a settlement by the recipient (§ 14 Abs. 2 UStG, § 11 Abs. 7 UStG 1994).
$GLOBALS['TL_LANG']['CONSHO']['invoice']['types']['creditNote'] = 'Rechnungskorrektur';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['types']['deliveryNote'] = 'Lieferschein';
// dev#2: the delivery note (consho_delivery_note.html.twig).
$GLOBALS['TL_LANG']['CONSHO']['deliveryNote'] = [
    'number' => 'Lieferscheinnummer',
    'date' => 'Datum',
    'shippedAt' => 'Versanddatum',
    'carrier' => 'Versanddienst',
    'trackingNumber' => 'Sendungsnummer',
    'partial' => 'Teillieferung — die übrigen Artikel folgen separat.',
    'lines' => 'Inhalt dieser Sendung',
    'rest' => 'Folgt separat',
];
$GLOBALS['TL_LANG']['CONSHO']['invoice']['number'] = 'Rechnungsnummer';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['date'] = 'Rechnungsdatum';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['correction']['number'] = 'Nummer der Rechnungskorrektur';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['correction']['date'] = 'Datum der Rechnungskorrektur';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['orderNumber'] = 'Bestellnummer';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['cancels'] = 'Storniert Rechnung';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['corrects'] = 'Korrigiert Rechnung';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['withdrawal'] = 'Widerruf';
// %s, not %date%: Contao fills contao_default with vsprintf, and "%d" in
// "%date%" asks for a second argument.
$GLOBALS['TL_LANG']['CONSHO']['invoice']['cancelsDate'] = 'vom %s';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['orderDate'] = 'Bestelldatum';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['subscription'] = 'Abo';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['servicePeriod'] = 'Leistungszeitraum';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['deliveryPeriod'] = 'Voraussichtl. Lieferung';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['deliveryDate'] = 'Lieferdatum';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['deliveryPartial'] = '%s (Teillieferung)';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['deliveryRest'] = 'Restlieferung voraussichtl.';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['advance'] = 'Diese Rechnung rechnet über eine Vorauszahlung ab, die Lieferung erfolgt nach Zahlungseingang.';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['dueDate'] = 'Zahlbar bis';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['billingAddress'] = 'Rechnungsadresse';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['shippingAddress'] = 'Lieferadresse';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['vatId'] = 'UID-Nr.';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['customerVatId'] = 'UID-Nr. des Empfängers';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['lines'] = 'Positionen';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['position'] = 'Pos.';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['item'] = 'Bezeichnung';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['sku'] = 'Art.-Nr.';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['quantity'] = 'Menge';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['unitPrice'] = 'Einzelpreis';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['taxRate'] = 'USt.-Satz';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['total'] = 'Gesamt';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['taxes'] = 'Umsatzsteuer je Satz';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['net'] = 'Netto';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['tax'] = 'USt.';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['gross'] = 'Brutto';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['netTotal'] = 'Summe netto';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['taxTotal'] = 'Umsatzsteuer';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['grandTotal'] = 'Gesamtbetrag';
// Wertgutscheine (WG12): payments before the invoice and what remains.
$GLOBALS['TL_LANG']['CONSHO']['invoice']['paid'] = 'Bereits bezahlt';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['refunded'] = 'Erstattet';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['outstanding'] = 'Offener Betrag';
// Wertgutscheine (WG3): the line of a voucher sold with a "nicht steuerbar" class.
$GLOBALS['TL_LANG']['CONSHO']['invoice']['notTaxable'] = 'nicht steuerbar';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['singlePurposeVoucher'] = 'Einzweck-Gutschein';
// Wertgutscheine (EZ-4): tax a single-purpose voucher already carried at its sale.
$GLOBALS['TL_LANG']['CONSHO']['invoice']['prepaidTax'] = 'davon mit %1$s bereits versteuert (%2$s)';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['taxDue'] = 'Verbleibende Umsatzsteuer';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['shippingMethod'] = 'Versandart';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['paymentMethod'] = 'Zahlungsart';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['payment'] = 'Zahlung';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['accountHolder'] = 'Kontoinhaber';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['iban'] = 'IBAN';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['bic'] = 'BIC';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['bankName'] = 'Bank';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['reference'] = 'Verwendungszweck';
// The grey delete button of a shipping or payment method (C-9).
$GLOBALS['TL_LANG']['CONSHO']['methodInUse'] = 'Das Element ist mit mind. einer Bestellung verknüpft und darf nicht mehr gelöscht werden.';
$GLOBALS['TL_LANG']['CONSHO']['recordInUse'] = 'Verwendete Items können nicht entfernt werden.';
$GLOBALS['TL_LANG']['CONSHO']['defaultStatus'] = ['new' => 'Neu', 'paid' => 'Bezahlt', 'shipped' => 'Versendet', 'completed' => 'Abgeschlossen', 'cancelled' => 'Storniert'];
$GLOBALS['TL_LANG']['CONSHO']['invoice']['giroCode'] = 'Zum Bezahlen mit der Banking-App scannen';

// The confirmation mails (C-9). ⚠️ Placeholders are %s in order: Contao fills
// contao_default strings with vsprintf, not by name.
$GLOBALS['TL_LANG']['CONSHO']['mail']['salutation'] = 'Guten Tag %s,';
$GLOBALS['TL_LANG']['CONSHO']['mail']['viewOrder'] = 'Bestellung ansehen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdraw'] = 'Vertrag widerrufen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['subject'] = 'Ihre Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['intro'] = 'vielen Dank für Ihre Bestellung %s vom %s. Wir bestätigen den Eingang wie folgt.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['invoiceAttached'] = 'Die Rechnung finden Sie im Anhang.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['invoiceSeparate'] = 'Die Rechnung erhalten Sie gesondert.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['warrantyAttached'] = 'Die Mitteilung über Ihr gesetzliches Gewährleistungsrecht finden Sie im Anhang.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['garanHeadline'] = 'Haltbarkeitsgarantie des Herstellers';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['garanLine'] = 'Für „%s" gewährt der Hersteller eine Haltbarkeitsgarantie von %s Jahren. Ihr gesetzliches Gewährleistungsrecht besteht daneben.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['operator']['subject'] = 'Neue Bestellung %s über %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['operator']['intro'] = 'Neue Bestellung %s vom %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['subject'] = 'Neue Zahlungsart für Ihre Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['intro'] = 'die Zahlungsart Ihrer Bestellung %s ist jetzt: %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['cancelled'] = 'Die Rechnung %s ist damit storniert, die Stornorechnung %s liegt bei.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['newInvoice'] = 'Die neue Rechnung %s finden Sie im Anhang.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['operator']['subject'] = 'Zahlungsart geändert: Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['operator']['intro'] = 'Die Zahlungsart der Bestellung %s wurde geändert auf: %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['subject'] = 'Zahlung erhalten: Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['intro'] = 'wir haben Ihre Zahlung für die Bestellung %s erhalten. Vielen Dank!';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['invoiceAttached'] = 'Die Rechnung %s finden Sie im Anhang.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['operator']['subject'] = 'Zahlung eingegangen: Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['operator']['intro'] = 'Für die Bestellung %s ist die Zahlung eingegangen (%s).';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['subject'] = 'Ihre Bestellung %s ist unterwegs';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['intro'] = 'wir haben folgende Artikel Ihrer Bestellung %s versandt:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['rest'] = 'Die übrigen Artikel folgen in einer weiteren Sendung.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['tracking'] = 'Sendungsnummer (%s): %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['trackingNoCarrier'] = 'Sendungsnummer: %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['invoiceAttached'] = 'Die Rechnung %s finden Sie im Anhang.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['subject'] = 'Ihre Rechnung zum Abo %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['intro'] = 'für Ihr Abo %s beginnt ein neuer Zeitraum: vom %s bis %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['invoiceAttached'] = 'Die Rechnung %s finden Sie im Anhang.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['charged'] = 'Der Betrag wurde am %s über %s abgebucht.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['operator']['introCharged'] = 'Folgebestellung %s zum Abo %s über %s am %s abgebucht, Zeitraum %s bis %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['operator']['subject'] = 'Folgebestellung %s zum Abo %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['operator']['intro'] = 'Folgebestellung %s zum Abo %s angelegt, „Auf Rechnung", Zeitraum %s bis %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['subject'] = 'Ihr Abo %s ruht';
// Abo-5: after a change to online payment the invoice is cancelled,
// so the order number stands here rather than the invoice's.
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['intro'] = 'die Zahlung zu %s für Ihr Abo %s war am %s fällig und ist noch offen: %s. Bis zum Zahlungseingang ruht Ihr Zugang.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['resume'] = 'Sobald die Zahlung eingegangen ist, steht Ihnen der Zugang wieder zur Verfügung — Sie müssen dafür nichts weiter tun. Die Zahlungsdaten finden Sie auf Ihrer Bestellseite.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['operator']['subject'] = 'Abo %s ruht: %s offen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['operator']['intro'] = 'Das Abo %s ruht: Die Rechnung %s (Bestellung %s) war am %s fällig, offen sind %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['operator']['note'] = 'Consho kündigt nicht und mahnt nicht. Sobald die Zahlung in der Bestellung erfasst ist, lebt das Abo von selbst wieder auf.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['subject'] = 'Ihr Abo %s: Die Mindestlaufzeit endet am %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['intro'] = 'die Mindestlaufzeit Ihres Abos %s (%s) endet am %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['ends'] = 'Danach endet das Abo. Wenn Sie %s weiter nutzen möchten, können Sie es neu abschließen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['sameTerm'] = 'Danach verlängert es sich um %s zu den bisherigen Bedingungen. Wenn Sie das nicht möchten, kündigen Sie bis spätestens %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['indefinite'] = 'Danach läuft es unbefristet zu den bisherigen Bedingungen weiter und ist jederzeit mit %s Frist kündbar. Wenn Sie es schon zum %s beenden möchten, kündigen Sie bis spätestens %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['buyAgain'] = 'Neu abschließen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['renew'] = 'Lizenz verlängern';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['subject'] = 'Pause für Ihr Abo %s bestätigt';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['intro'] = 'Ihr Abo %s pausiert vom %s bis %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['effect'] = 'In dieser Zeit berechnen wir nichts, und Ihr Zugang ruht. Am %s geht das Abo weiter; die Laufzeit verschiebt sich um die Pause.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['operator']['subject'] = 'Abo %s pausiert';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['operator']['intro'] = 'Das Abo %s pausiert vom %s bis %s.';
// E7: a charge with the mandate is under way.
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionChargePending']['subject'] = 'Abbuchung zu Ihrem Abo %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionChargePending']['intro'] = 'für Ihr Abo %s beginnt ein neuer Zeitraum: vom %s bis %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionChargePending']['charge'] = 'Den Betrag von %s haben wir über %s zur Abbuchung gegeben. Bis die Zahlung bestätigt ist, kann es einige Tage dauern.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionChargePending']['invoiceFollows'] = 'Sobald der Betrag eingegangen ist, erhalten Sie die Rechnung.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionResumed']['subject'] ='Ihr Abo %s ist wieder aktiv';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionResumed']['intro'] = 'zu Ihrem Abo %s ist keine Rechnung mehr offen. Das Abo ist wieder aktiv, Ihr Zugang steht Ihnen wieder zur Verfügung.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseSuspended']['subject'] = 'Ihre digitalen Inhalte zur Bestellung %s ruhen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseSuspended']['intro'] = 'die Zahlung zu %s war am %s fällig und ist noch offen: %s. Bis zum Zahlungseingang ruhen Ihre digitalen Inhalte aus dieser Bestellung — Downloads, Lizenzen und Zugänge sind so lange nicht nutzbar.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseSuspended']['resume'] = 'Sobald die Zahlung eingegangen ist, stehen sie Ihnen wieder zur Verfügung — Sie müssen dafür nichts weiter tun. Die Zahlungsdaten finden Sie auf Ihrer Bestellseite.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseResumed']['subject'] = 'Ihre digitalen Inhalte zur Bestellung %s stehen wieder zur Verfügung';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseResumed']['intro'] = 'zu Ihrer Bestellung %s ist keine Zahlung mehr überfällig. Ihre digitalen Inhalte stehen Ihnen wieder zur Verfügung.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['documentIssued']['subject'] = [
    'invoice' => 'Ihre Rechnung %s zur Bestellung %s',
    'cancellation' => 'Stornorechnung %s zu Ihrer Bestellung %s',
    'creditNote' => 'Rechnungskorrektur %s zu Ihrer Bestellung %s',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['documentIssued']['intro'] = [
    'invoice' => 'anbei erhalten Sie die Rechnung %s zu Ihrer Bestellung %s.',
    'cancellation' => 'anbei erhalten Sie die Stornorechnung %s zu Ihrer Bestellung %s.',
    'creditNote' => 'anbei erhalten Sie die Rechnungskorrektur %s zu Ihrer Bestellung %s.',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['subject'] = 'Eingangsbestätigung: Kündigung Ihres Abos %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['intro'] = 'wir bestätigen den Eingang Ihrer Kündigung des Abos %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['statement'] = 'Ihre Kündigung, wie sie bei uns eingegangen ist:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['receivedAt'] = 'Eingang';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['number'] = 'Kündigungsnummer';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['contract'] = 'Vertrag';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['kind'] = 'Art der Kündigung';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['kinds'] = ['ordinary' => 'ordentliche Kündigung', 'extraordinary' => 'außerordentliche Kündigung'];
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['reason'] = 'Kündigungsgrund';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['name'] = 'Name';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['email'] = 'E-Mail';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['requestedAt'] = 'Gewünschter Zeitpunkt';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['earliest'] = 'zum frühestmöglichen Zeitpunkt';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['endsAt'] = 'Der Vertrag endet durch diese Kündigung am %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['earliestApplied'] = 'Der gewünschte Zeitpunkt lag vor dem frühestmöglichen. Die Kündigung wirkt deshalb zum frühestmöglichen Zeitpunkt.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['laterThanEarliest'] = 'Der gewünschte Zeitpunkt liegt nach dem frühestmöglichen Ende (%s). Abrechnungszeiträume, die vor dem Vertragsende beginnen, werden noch voll berechnet.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['pending'] ='Über eine außerordentliche Kündigung entscheiden wir und teilen Ihnen das Ergebnis mit, bei Annahme auch den Zeitpunkt, zu dem der Vertrag endet.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['operator']['subject'] = 'Kündigung eingegangen: Abo %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['operator']['intro'] = 'Zum Abo %s ist eine Kündigung eingegangen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['operator']['decide'] = 'Eine außerordentliche Kündigung wirkt erst, wenn Sie sie im Backend annehmen (unter Abos → Kündigungen). Dort können Sie sie auch mit Begründung ablehnen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['acceptedSubject'] = 'Ihre Kündigung des Abos %s ist angenommen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['rejectedSubject'] = 'Ihre Kündigung des Abos %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['accepted'] = 'wir nehmen Ihre außerordentliche Kündigung %s des Abos %s an.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['rejected'] = 'Ihre außerordentliche Kündigung %s des Abos %s können wir nicht annehmen. Die Begründung:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['runsOn'] = 'Das Abo läuft zu den bisherigen Bedingungen weiter. Eine ordentliche Kündigung ist davon unberührt.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['subject'] = 'Eingangsbestätigung: Widerruf zur Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['intro'] = 'wir bestätigen den Eingang Ihres Widerrufs zur Bestellung %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['receivedAt'] = 'Eingegangen am';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['number'] = 'Widerrufsnummer';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['order'] = 'Bestellung';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['name'] = 'Name';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['email'] = 'E-Mail';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['scope'] = 'Widerrufen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['all'] = 'die ganze Bestellung';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['message'] = 'Ihre Nachricht';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['statement'] = 'Ihre Erklärung im Wortlaut:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['message'] = 'Nachricht des Kunden';
// WA (A3): a declaration that matched no order — no order data in the mail.
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['unassigned']['subject'] = 'Eingangsbestätigung: Widerruf %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['unassigned']['intro'] = 'wir bestätigen den Eingang Ihres Widerrufs. Wir ordnen ihn Ihrer Bestellung zu und melden uns, falls wir dazu noch etwas von Ihnen brauchen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['unassigned']['orderReference'] = 'Angegebene Bestellnummer';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['unassigned']['subject'] = 'Widerruf eingegangen, nicht zugeordnet: %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['unassigned']['intro'] = 'Über das Widerrufsformular ist ein Widerruf eingegangen, dessen Bestellnummer und E-Mail-Adresse zu keiner Bestellung passen. Bitte unter „Widerrufe" einer Bestellung zuordnen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['unassigned']['unconfirmed'] = 'Die Eingangsbestätigung an den Kunden ist nicht hinausgegangen — bitte von Hand bestätigen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawalRejected']['subject'] = 'Ihr Widerruf %s zur Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawalRejected']['intro'] = 'Ihren Widerruf %s zur Bestellung %s können wir nicht annehmen. Die Begründung:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['subject'] = 'Ihr Widerruf %s zur Bestellung %s: Erstattung';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['intro'] = 'wir haben Ihren Widerruf %s zur Bestellung %s bearbeitet.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['payout'] = 'Wir erstatten Ihnen %s mit demselben Zahlungsmittel, mit dem Sie bezahlt haben, sofern wir nichts anderes vereinbart haben.';
// WG-6 (WG11): the part that goes back to the voucher.
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['vouchers'] = 'Davon schreiben wir %s Ihrem Gutschein gut, mit dem Sie bezahlt haben.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['credit'] = 'Der Betrag, den Sie zu zahlen haben, verringert sich um %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['documentAttached'] = 'Die Belege finden Sie im Anhang: %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['remaining'] = 'Bitte überweisen Sie nur den verbleibenden Betrag von %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['valueCompensation'] = 'Für die bis zu Ihrem Widerruf erbrachte Leistung behalten wir einen Wertersatz von %s ein.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['subscriptionEnded'] = 'Ihr Abo %s ist mit dem Widerruf beendet.';
// "Meine Bestellungen" (D-4, K3).
$GLOBALS['TL_LANG']['CTE']['consho_my_orders'] = ['Meine Bestellungen', 'Die Bestellungen des angemeldeten Mitglieds in diesem Shop, jede mit einem Link zur Bestellseite. Bestellungen als Gast unter der Kontoadresse erscheinen, sobald das Mitglied die Adresse per Mail bestätigt hat. Gehört auf eine geschützte Seite des Kundenkontos.'];
$GLOBALS['TL_LANG']['CONSHO']['myOrders'] = [
    'empty' => 'Sie haben in diesem Shop noch keine Bestellung aufgegeben.',
    'colOrder' => 'Bestellung',
    'colDate' => 'Bestellt am',
    'colTotal' => 'Gesamtsumme',
    'asGuest' => '(als Gast)',
    'guestHint' => 'Bestellungen, die Sie ohne Konto unter %s aufgegeben haben, zeigen wir hier, sobald Sie bestätigt haben, dass die Adresse Ihnen gehört.',
    'guestButton' => 'Bestellungen als Gast anzeigen',
    'confirmIntro' => 'Bitte bestätigen Sie, dass die Adresse %s Ihnen gehört. Danach sehen Sie hier auch Ihre Bestellungen als Gast.',
    'confirmButton' => 'Adresse bestätigen',
    'confirmState' => [
        'login' => 'Bitte melden Sie sich mit Ihrem Kundenkonto an und öffnen Sie den Link aus der Mail danach noch einmal.',
        'invalid' => 'Dieser Link ist ungültig oder abgelaufen. Fordern Sie unter „Meine Bestellungen" einen neuen an.',
        'otherAccount' => 'Dieser Link gehört zu einem anderen Kundenkonto oder einer anderen Adresse. Es wurde nichts bestätigt.',
    ],
    'notice' => [
        'sent' => 'Wir haben Ihnen eine Mail an %s geschickt. Bitte öffnen Sie den Link darin, solange Sie hier angemeldet sind.',
        'notSent' => 'Die Mail konnte nicht verschickt werden. Bitte versuchen Sie es später noch einmal.',
        'tooMany' => 'Sie haben die Mail gerade schon mehrmals angefordert. Bitte versuchen Sie es in einer Stunde noch einmal.',
        'confirmed' => 'Danke — die Adresse ist bestätigt. Ihre Bestellungen als Gast stehen jetzt in der Liste.',
        'notFound' => 'Diese Bestellung gibt es in Ihrem Kundenkonto nicht.',
        'noOrderPage' => 'Die Bestellung lässt sich gerade nicht öffnen. Bitte wenden Sie sich an den Shop.',
    ],
];
$GLOBALS['TL_LANG']['CONSHO']['statusFields'] = [
    'statusAfterPayment' => 'Status nach vollständiger Zahlung',
    'statusAfterDelivery' => 'Status nach vollständiger Lieferung',
    'statusWhenSettled' => 'Status, wenn alles erledigt ist',
];

// Menu item "Kunden" (D-4, K2).
// Block R (R10): the fields an e-invoice needs, for the finding.
$GLOBALS['TL_LANG']['CONSHO']['eInvoiceFields'] = [
    'invoiceCompany' => 'Firmenname',
    'invoiceCountry' => 'Land des Rechnungsabsenders',
    'vatId' => 'Eigene UID-Nummer',
    'invoiceContactEmail' => 'Kontakt-E-Mail für Rechnungen',
];

// Block Dashboard.
$GLOBALS['TL_LANG']['CONSHO']['dashboard'] = [
    'title' => 'Consho Dashboard',
    'openPoints' => 'Offene Punkte',
    'unpaid' => 'Zahlungen',
    'overdue' => 'davon %d überfällig',
    'toDeliver' => 'Lieferungen',
    'complaints' => 'Reklamationen',
    'undecided' => 'Widerrufe',
    'refundOpen' => 'Erstattungen',
    'refundDue' => 'bis %s',
    'stockLow' => 'Artikel unter Meldebestand',
    'reorder' => 'Nachbestellen',
    'stockMore' => 'und %d weitere',
    'figures' => 'Bestellungen und Bestellwert brutto, ohne Stornos',
    'today' => 'Heute',
    'week' => '7 Tage',
    'month' => 'Monat',
    'noOrders' => 'Diesen Monat noch keine Bestellung.',
    'tax' => 'Steuermeldung',
    'threshold' => 'Lieferschwelle',
    'previousYear' => 'Vorjahr',
    'sharedWith' => 'gemeinsam mit %s',
    'taxOpen' => 'Steuermeldung und Quartalsexport',
    'incomplete' => 'Einrichtung unvollständig — dieser Shop verkauft nicht.',
    'incompleteHidden' => 'Einrichtung unvollständig — dieser Shop verkauft nicht. Die Gründe sieht, wer die Einrichtung bearbeiten darf.',
    'setupContinue' => 'Einrichtung fortsetzen',
    'setupStart' => 'Noch keine Shop-Konfiguration — Einrichtung beginnen',
    'warnings' => 'Warnungen',
    'notes' => 'Hinweise',
    'close' => 'Schließen',
    'notesElsewhere' => '%d Hinweise im Shop-Dashboard.',
    'open' => 'Öffnen',
    'filtered' => 'Gefiltert vom Dashboard: %s (%d).',
    'showAll' => 'Alle anzeigen',
    'filters' => [
        'unpaid' => 'unbezahlt',
        'overdue' => 'überfällig',
        'toDeliver' => 'zu liefern',
        'complaint' => 'Reklamation offen',
        'undecided' => 'zu entscheiden',
        'refundOpen' => 'Erstattung offen',
        'low' => 'unter Meldebestand',
    ],
];

// Block I: "Einrichtung".
$GLOBALS['TL_LANG']['CONSHO']['setup'] = [
    'title' => 'Einrichtung',
    'back' => 'Zurück zum Dashboard',
    'intro' => 'Was ein Shop bis zum ersten Verkauf braucht. Die Schritte kommen aus denselben Prüfungen wie die Hinweise: Ein Schritt ist erledigt, wenn keine davon mehr anschlägt. Inhalte wie Rechtstexte, Adressen, Preise und Steuersätze tragen Sie selbst ein.',
    'ready' => 'Bereit — dieser Shop verkauft.',
    'notReady' => 'Noch nicht bereit — dieser Shop verkauft nicht.',
    'noShop' => 'Es gibt noch keine Shop-Konfiguration. Legen Sie eine an und tragen Sie sie danach an einem Startpunkt im Seitenbaum unter „Consho" ein.',
    'createShop' => 'Shop-Konfiguration anlegen',
    'status' => ['done' => 'erledigt', 'open' => 'offen', 'attention' => 'prüfen'],
    'steps' => [
        'shop' => ['Shop und Startpunkt', 'Ein veröffentlichter Startpunkt im Seitenbaum nennt unter „Consho" diese Shop-Konfiguration und eine Währung.'],
        'basics' => ['Grunddaten', 'Heimatland, Rechnungsabsender und die übrigen Angaben der Shop-Konfiguration.'],
        'mail' => ['Mailversand', 'Ein Postausgang für Bestellbestätigungen und Rechnungen, in der Shop-Konfiguration ausgewählt.'],
        'shipping' => ['Versandart', 'Mindestens eine veröffentlichte Versandart mit Steuerklasse und Preisen, in der Shop-Konfiguration ausgewählt.'],
        'payment' => ['Zahlungsart', 'Mindestens eine veröffentlichte Zahlungsart, in der Shop-Konfiguration ausgewählt.'],
        'numberRanges' => ['Nummernkreise', 'Bestellnummern immer; Rechnungs- und Stornonummern, sobald eine Zahlungsart Rechnungen stellt; Rechnungskorrekturen bei einer Widerrufsfrist.'],
        'shopPages' => ['Shopseiten', 'Warenkorb, Kasse, Bestellung, Versandkosten, Widerrufsformular und Kündigung — je eine Seite mit ihrem Element, am Startpunkt verknüpft.'],
        'legalTexts' => ['Rechtstexte', 'AGB, Widerrufsbelehrung, Datenschutzerklärung und Gewährleistungshinweis schreiben Sie selbst und wählen sie am Startpunkt unter „Consho" aus.'],
    ],
    'links' => [
        'shopEdit' => 'Shop-Konfiguration bearbeiten',
        'pages' => 'Seitenstruktur',
        'rootEdit' => 'Startpunkt „%s" bearbeiten',
        'mail' => 'Mailversand',
        'shipping' => 'Versandarten',
        'payment' => 'Zahlungsarten',
        'numberRanges' => 'Nummernkreise',
    ],
    'others' => 'Weitere Warnungen dieses Shops',
    'adminOnly' => 'Das Anlegen auf Knopfdruck steht Administratoren zur Verfügung.',
    'createRanges' => 'Fehlende Nummernkreise anlegen',
    'rangesCreated' => 'Nummernkreise angelegt: %s. Die Präfixe lassen sich in der Maske ändern.',
    'rangesNone' => 'Es fehlte kein Nummernkreis.',
    'createPages' => 'Fehlende Shopseiten anlegen',
    'pagesCreated' => 'Unter „%s" angelegt: %s. Die Seiten sind veröffentlicht und nicht in der Navigation.',
    'pagesNone' => 'Es fehlte keine Shopseite.',
    'unpublishedOnly' => 'Betrifft nur Startpunkte, die noch nicht veröffentlicht sind — gesperrt wird dadurch nichts.',
    'pageTitles' => [
        'cart' => 'Warenkorb',
        'checkout' => 'Kasse',
        'order' => 'Ihre Bestellung',
        'shippingCosts' => 'Versandkosten',
        'withdrawal' => 'Vertrag widerrufen',
        'cancellation' => 'Verträge hier kündigen',
    ],
    'cache' => 'Seiten-Cache',
    'cacheHelp' => 'Optional, sperrt nie. Ob Katalogseiten zwischengespeichert werden, entscheiden Sie.',
    'cacheOff' => 'Am Startpunkt „%s" ist der Seiten-Cache aus (Contao-Vorgabe): Jede Seite wird bei jedem Aufruf neu berechnet. Liegt ein Proxy wie Cloudflare oder Varnish davor, ist das richtig so.',
    'cacheOn' => 'Am Startpunkt „%s" ist der Seiten-Cache eingeschaltet: %d Sekunden.',
    'cacheEnable' => 'Zwischenspeichern einschalten (1 Stunde)',
    'cacheEnabled' => 'Seiten-Cache am Startpunkt „%s" eingeschaltet: 1 Stunde.',
    'probe' => 'Jetzt messen',
    'probeNote' => 'Gemessen aus Sicht des Servers: zwei Abrufe ohne Cookies, wie ein Gast. Ein Besucher an einem anderen Ort kann einen anderen Stand sehen.',
    'probeResults' => [
        'cached' => 'kommt aus einem Cache (%s)',
        'private' => 'privat — wird nirgends zwischengespeichert',
        'public' => 'öffentlich erlaubt, kein Cache-Treffer erkennbar (ein Proxy kann seine Spuren entfernen)',
        'failed' => 'Messung nicht möglich: %s',
    ],
    'probeBudget' => 'Die Zeit für diese Messung ist aufgebraucht (15 Sekunden). Bitte erneut messen.',
];

// Block R (R6, R7): "Steuermeldung".
$GLOBALS['TL_LANG']['CONSHO']['taxReport'] = [
    'back' => 'Zurück zum Dashboard',
    'intro' => 'Fernverkäufe und elektronische Leistungen an Privatkunden in anderen EU-Ländern, netto, je Unternehmen (Shops mit derselben UID). Bis zur Lieferschwelle von %s im laufenden und im vorigen Jahr gilt die Umsatzsteuer des Shoplandes. Umsätze außerhalb von Consho zählen mit; nur Euro wird gegen die Schwelle gerechnet.',
    'atDestinationOn' => 'Steuer im Bestimmungsland: eingeschaltet',
    'atDestinationOff' => 'Steuer im Bestimmungsland: ausgeschaltet',
    'colYear' => 'Jahr',
    'colCounted' => 'Fernverkäufe netto',
    'colShare' => 'Anteil an der Schwelle',
    'notConverted' => 'nicht umgerechnet',
    'colQuarter' => 'Quartal',
    'colExport' => 'Export (CSV)',
    'summary' => 'Meldung',
    'records' => 'Belegliste',
    'noShops' => 'Noch keine Shop-Konfiguration.',
    'exportNote' => 'Die Meldung enthält nur Umsätze, die im Land des Kunden versteuert wurden. Teil A: Umsätze des Quartals je Land und Satz; Teil B: Berichtigungen früherer Quartale je Land und ursprünglichem Quartal. Ein Umsatz zählt zum früheren Datum von vollständiger Zahlung und Lieferung. Beträge in der Währung der Bestellung — für die Erklärung in Euro zum EZB-Kurs vom letzten Tag des Quartals umrechnen. Kein Upload-Format einer Behörde.',
    'colPart' => 'Teil',
    'colCountry' => 'Mitgliedstaat',
    'colRate' => 'Satz',
    'colPeriod' => 'Ursprünglicher Zeitraum',
    'colCurrency' => 'Währung',
    'colNet' => 'Bemessungsgrundlage',
    'colTax' => 'Steuer',
    'colOrder' => 'Bestellung',
    'colDocument' => 'Beleg',
    'colKind' => 'Art',
    'colDate' => 'Datum',
    'colSupply' => 'Umsatz zum',
    'colPaid' => 'Bezahlt am',
    'colDelivered' => 'Geliefert am',
    'colDestination' => 'Bestimmungsland',
    'colItem' => 'Position',
    'supply' => 'Umsatz',
    'correction' => 'Berichtigung',
];

$GLOBALS['TL_LANG']['CONSHO']['customers'] = [
    'back' => 'Zurück',
    'shop' => 'Shop',
    'allShops' => 'alle Shops',
    'search' => 'Suchen',
    'searchHint' => 'Name oder E-Mail',
    'apply' => 'Anwenden',
    'empty' => 'Keine Kunden gefunden.',
    'colName' => 'Name',
    'colEmail' => 'E-Mail',
    'colKind' => 'Konto',
    'colOrders' => 'Bestellungen',
    'colLast' => 'Letzte Bestellung',
    'member' => 'Mitglied',
    'guest' => 'Gast',
    'alsoGuest' => 'auch als Gast bestellt',
    'limit' => 'Anzeigen',
    'openMember' => 'Mitgliedskonto öffnen',
    'noOrders' => 'Noch keine Bestellung in diesem Shop.',
    'colOrder' => 'Bestellung',
    'colDate' => 'Bestellt am',
    'colTotal' => 'Gesamtsumme',
    'colAs' => 'Bestellt als',
    'filter' => 'Filtern',
    'filterShow' => 'Filter einblenden',
    'filterHide' => 'Filter ausblenden',
    'filterLabel' => 'Such- und Filterfunktionen',
    'reset' => 'Zurücksetzen',
    'opShow' => 'Kunde und Bestellungen anzeigen',
    'noMember' => 'Gastbestellung',
    // D-5: data access and erasure.
    'colErasure' => 'Löschung verlangt',
    'privacyLegend' => 'Auskunft und Löschung',
    'privacyHelp' => 'Die Auskunft ist eine ZIP-Datei mit allen Daten dieser Person (daten.json, maschinenlesbar) und den Belegen als PDF. Consho verschickt sie nicht: Prüfen Sie die Identität und ergänzen Sie die Angaben, die Art. 15 Abs. 1 DSGVO verlangt.',
    'exportButton' => 'Auskunft herunterladen',
    'eraseHelp' => 'Bestellungen, deren Aufbewahrungsfrist abgelaufen ist, werden sofort anonymisiert — Nummern und Beträge bleiben. Bestellungen in der Frist bleiben unverändert und werden am Fristende anonymisiert. Warenkörbe, offene Bestätigungen, die Consho-Felder des Kontos und Reklamationsnotizen gehen sofort. Laufende Abos bleiben bis zum Vertragsende und werden danach mit ihren Bestellungen anonymisiert; kündigen müssen Sie sie selbst. Das Mitgliedskonto selbst löschen Sie unter „Mitglieder". Nicht rückgängig zu machen.',
    'eraseConfirm' => 'Die Person hat die Löschung verlangt, ihre Identität ist geprüft.',
    'eraseButton' => 'Daten löschen',
    'eraseUnconfirmed' => 'Bitte bestätigen Sie zuerst, dass die Löschung verlangt und die Identität geprüft ist. Es wurde nichts gelöscht.',
    'erasedNow' => 'Anonymisiert: %s.',
    'erasedLater' => 'In der Aufbewahrungsfrist, Löschung vermerkt: %s.',
    'pendingUntil' => '%s (bis %s)',
    'pendingNoPeriod' => '%s (keine Frist eingestellt)',
    'erasedRunning' => 'Laufende Abos, werden nach Vertragsende anonymisiert: %s.',
    'erasedAround' => 'Warenkörbe, offene Bestätigungen, Consho-Felder des Kontos und Reklamationsnotizen sind gelöscht.',
];
// Confirmation of an e-mail address (D-4, K3a) — shared, then per purpose.
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['hello'] = 'Guten Tag,';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['validity'] = 'Der Link gilt 24 Stunden.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['notYou'] = 'Haben Sie das nicht angefordert, klicken Sie nicht auf den Link — dann ändert sich nichts.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['guestOrders']['subject'] = 'Bitte bestätigen Sie Ihre E-Mail-Adresse';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['guestOrders']['intro'] = 'Sie möchten in Ihrem Kundenkonto auch die Bestellungen sehen, die Sie ohne Konto unter der Adresse %s aufgegeben haben. Bestätigen Sie dazu, dass diese Adresse Ihnen gehört: Melden Sie sich zuerst im Shop mit Ihrem Kundenkonto an und öffnen Sie danach den Link.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['guestOrders']['button'] = 'Adresse bestätigen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['orderContext'] = 'Die Bestellung:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['subject'] = 'Widerruf eingegangen: Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['intro'] = 'Zur Bestellung %s ist ein Widerruf eingegangen (%s).';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['source'] = ['orderPage' => 'über die Bestellseite', 'form' => 'über das Widerrufsformular'];

// New link to the order page (D-6, T4).
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['subject'] = 'Ihr Link zur Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['intro'] = 'Sie haben einen neuen Link zu Ihrer Bestellung %s angefordert. Darüber sehen Sie den Stand der Bestellung und Ihre Belege.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['button'] = 'Bestellung anzeigen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['validity'] = 'Der Link gilt 90 Tage. Danach können Sie auf der Bestellseite jederzeit einen neuen anfordern.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['notYou'] = 'Haben Sie das nicht angefordert, können Sie diese Mail ignorieren. Der Link wurde nur an Sie geschickt.';

// "Kundenkonto anlegen" on the order page (K1).
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountCreate']['subject'] = 'Ihr Kundenkonto: Passwort festlegen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountCreate']['intro'] = 'Sie haben auf der Seite Ihrer Bestellung ein Kundenkonto angelegt. Es wird geöffnet, sobald Sie Ihr Passwort festlegen. Ihre Adressen aus der Bestellung sind schon eingetragen, und im Konto sehen Sie Ihre Bestellungen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountCreate']['username'] = 'Ihr Benutzername: %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountCreate']['button'] = 'Passwort festlegen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountCreate']['validity'] = 'Der Link gilt 24 Stunden. Legen Sie bis dahin kein Passwort fest, wird das Konto wieder gelöscht. Sie können es jederzeit erneut auf der Seite Ihrer Bestellung anlegen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountCreate']['notYou'] = 'Haben Sie das nicht angefordert, können Sie diese Mail ignorieren. Ohne Passwort wird kein Konto geöffnet.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountExists']['subject'] = 'Sie haben bereits ein Kundenkonto';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountExists']['intro'] = 'Sie wollten auf der Seite Ihrer Bestellung ein Kundenkonto anlegen. Zu Ihrer E-Mail-Adresse gibt es bereits eines, mit dem Benutzernamen %s. Haben Sie das Passwort vergessen, können Sie hier ein neues festlegen.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountExists']['button'] = 'Passwort vergessen';
$GLOBALS['TL_LANG']['CONSHO']['mail']['accountExists']['notYou'] = 'Haben Sie das nicht angefordert, können Sie diese Mail ignorieren. An Ihrem Konto wurde nichts geändert.';

// The order page (C-10).
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['title'] = 'Ihre Bestellung %s';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['linkRequest'] = [
    'title' => 'Link zur Bestellung anfordern',
    'intro' => 'Der Link ist nicht mehr gültig oder unvollständig. Geben Sie Bestellnummer und E-Mail-Adresse der Bestellung an — wir schicken Ihnen einen neuen Link an die Adresse der Bestellung.',
    'number' => 'Bestellnummer',
    'email' => 'E-Mail-Adresse',
    'submit' => 'Neuen Link anfordern',
    'requested' => 'Wenn die Angaben zu einer Bestellung passen, ist ein neuer Link an deren E-Mail-Adresse unterwegs. Sehen Sie auch im Spam-Ordner nach.',
    'tooMany' => 'Sie haben gerade schon mehrmals einen Link angefordert. Bitte versuchen Sie es später noch einmal.',
    'missing' => 'Bitte geben Sie Bestellnummer und E-Mail-Adresse an.',
];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['gate'] = [
    'text' => 'Ihre Bestellung %s ist bereit. Mit dem Klick bestätigen Sie zugleich Ihre E-Mail-Adresse.',
    'button' => 'Bestellung anzeigen',
];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['paymentState'] = 'Zahlung';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['deliveryState'] = 'Lieferung';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['paymentStates'] = ['open' => 'offen', 'partial' => 'teilweise bezahlt', 'paid' => 'bezahlt', 'overpaid' => 'überzahlt', 'refunded' => 'erstattet', 'nothingDue' => 'nichts zu zahlen'];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['deliveryStates'] = ['open' => 'noch nicht versandt', 'partial' => 'teilweise versandt', 'complete' => 'versandt'];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['documents'] = 'Belege';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['cancelled'] = 'storniert';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['format'] = ['pdf' => 'PDF', 'html' => 'HTML'];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['retrieve'] = ['pdf' => '%s als PDF', 'html' => '%s als HTML'];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['changeBlocked'] = [
    'notOpen' => 'Die Zahlungsart lässt sich nicht mehr ändern, weil bereits eine Zahlung eingegangen ist.',
    'statusLocks' => 'Die Zahlungsart lässt sich für diese Bestellung nicht mehr ändern.',
    'alreadyChanged' => 'Die Zahlungsart dieser Bestellung wurde bereits einmal geändert. Für eine weitere Änderung wenden Sie sich bitte an uns.',
    'afterWithdrawal' => 'Nach einem Widerruf lässt sich die Zahlungsart nicht mehr ändern. Für eine Änderung wenden Sie sich bitte an uns.',
    'overdue' => 'Die Rechnung ist bereits fällig; die Zahlungsart lässt sich nicht mehr ändern. Die Zahlungsdaten finden Sie oben.',
];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['change'] = [
    'title' => 'Zahlungsart ändern',
    'explanation' => 'Eine bereits ausgestellte Rechnung wird dabei storniert, und Sie erhalten eine neue. Die Belege schicken wir Ihnen auch per E-Mail.',
    'explanationElsewhere' => 'Eine bereits ausgestellte Rechnung wird dabei storniert, und Sie erhalten eine neue. Die Rechnung erhalten Sie gesondert.',
    'current' => 'aktuelle Zahlungsart',
    'unavailable' => 'für diese Bestellung nicht verfügbar',
    'confirm' => 'Ja, ich möchte die Zahlungsart ändern.',
    'submit' => 'Zahlungsart ändern',
];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['notice'] = [
    'changed' => 'Die Zahlungsart wurde geändert. Die neuen Belege finden Sie unten.',
    'changedElsewhere' => 'Die Zahlungsart wurde geändert. Die Rechnung erhalten Sie gesondert.',
    'notOpen' => 'Die Zahlungsart lässt sich nicht mehr ändern, weil bereits eine Zahlung eingegangen ist.',
    'statusLocks' => 'Die Zahlungsart lässt sich für diese Bestellung nicht mehr ändern.',
    'alreadyChanged' => 'Die Zahlungsart dieser Bestellung wurde bereits einmal geändert. Für eine weitere Änderung wenden Sie sich bitte an uns.',
    'afterWithdrawal' => 'Nach einem Widerruf lässt sich die Zahlungsart nicht mehr ändern. Für eine Änderung wenden Sie sich bitte an uns.',
    'overdue' => 'Die Rechnung ist bereits fällig; die Zahlungsart lässt sich nicht mehr ändern. Die Zahlungsdaten finden Sie oben.',
    'methodUnavailable' => 'Diese Zahlungsart ist für Ihre Bestellung nicht verfügbar.',
    'confirmMissing' => 'Bitte bestätigen Sie die Änderung.',
    'paymentFailed' => 'Die Zahlung konnte nicht gestartet werden. Sie können es erneut versuchen oder die Zahlungsart ändern.',
    'withdrawn' => 'Ihr Widerruf ist eingegangen. Die Eingangsbestätigung haben wir Ihnen per E-Mail geschickt.',
    'withdrawnUnconfirmed' => 'Ihr Widerruf ist eingegangen und gespeichert. Die Eingangsbestätigung per E-Mail konnte nicht versandt werden; wir holen das nach. Den Eingang sehen Sie unten.',
    'withdrawClosed' => 'Für diese Bestellung ist kein Widerruf über diese Seite mehr möglich.',
    'withdrawInvalid' => 'Die Angaben zu den Artikeln passen nicht zur Bestellung. Bitte prüfen Sie die Mengen.',
    'withdrawNothingSelected' => 'Bitte geben Sie bei mindestens einem Artikel eine Menge an oder wählen Sie die ganze Bestellung.',
    'withdrawNameMissing' => 'Bitte geben Sie Ihren Namen an.',
    // K1: one sentence for a new account and an existing one (K1-5).
    'accountRequested' => 'Wir haben Ihnen eine E-Mail an die Adresse dieser Bestellung geschickt. Darin steht, wie es weitergeht. Sehen Sie auch im Spam-Ordner nach.',
    'accountTooMany' => 'Sie haben das gerade schon mehrmals angefordert. Bitte versuchen Sie es später noch einmal.',
    'accountUnavailable' => 'Für diese Bestellung lässt sich hier kein Kundenkonto anlegen.',
];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['account'] = [
    'title' => 'Kundenkonto anlegen',
    'text' => 'Mit einem Kundenkonto sehen Sie alle Ihre Bestellungen an einem Ort, und Ihre Adressen sind beim nächsten Einkauf schon eingetragen. Wir schicken Ihnen eine E-Mail, mit der Sie Ihr Passwort festlegen.',
    'submit' => 'Kundenkonto anlegen',
];

// The withdrawal function (D-2). "Vertrag widerrufen" and "Widerruf bestätigen"
// are prescribed by law (§ 356a BGB, § 13a FAGG) — do not rephrase them.
$GLOBALS['TL_LANG']['CONSHO']['withdrawal'] = [
    'button' => 'Vertrag widerrufen',
    'confirm' => 'Widerruf bestätigen',
    'title' => 'Vertrag widerrufen',
    'intro' => 'Hier können Sie Ihren Vertrag widerrufen. Prüfen Sie Ihre Angaben und bestätigen Sie den Widerruf. Sie erhalten sofort eine Eingangsbestätigung per E-Mail.',
    'name' => 'Ihr Name',
    'email' => 'E-Mail für die Eingangsbestätigung',
    'order' => 'Bestellung',
    'scope' => 'Was möchten Sie widerrufen?',
    'all' => 'Die ganze Bestellung',
    'items' => 'Einzelne Artikel',
    'quantity' => 'Menge %s (bis zu %s)',
    'whole' => '%s (Abo, %s — nur ganz)',
    'message' => 'Nachricht (optional)',
    'back' => 'Zurück zur Bestellung',
    // WA, B2: neutral between du and Sie.
    'late' => 'Nach unseren Daten ist die Frist abgelaufen. Der Widerruf wird entsprechend geprüft.',
    'received' => 'Eingegangene Widerrufe',
    'receivedEntry' => '%s, eingegangen am %s',
    'confirmed' => 'Eingangsbestätigung per E-Mail versandt',
    'unconfirmed' => 'Eingangsbestätigung per E-Mail ausstehend',

    // The element for buyers without the order mail (W1, E6).
    // WA: the address the confirmation goes to — the order's, where they
    // match, else the one typed here.
    'formIntro' => 'Geben Sie Ihre Bestellnummer, Ihre E-Mail-Adresse und Ihren Namen an. Sie erhalten sofort eine Eingangsbestätigung per E-Mail.',
    'orderNumber' => 'Bestellnummer',
    'orderEmail' => 'E-Mail-Adresse (am besten die der Bestellung)',
    'partOptional' => 'Nur einen Teil der Bestellung? Dann nennen Sie ihn hier (optional)',
    'tooMany' => 'Zu viele Versuche. Bitte versuchen Sie es in einer Viertelstunde erneut.',
    'missing' => 'Bitte füllen Sie Bestellnummer, E-Mail-Adresse und Name aus.',
    'emailInvalid' => 'Bitte geben Sie eine gültige E-Mail-Adresse an — an sie geht die Eingangsbestätigung.',
    // WA: one answer for every case — no number (its shape told whether
    // the order was found) and no "not sent" (only the path without an
    // order has a mail limit).
    'done' => 'Ihr Widerruf ist eingegangen und gespeichert. Die Eingangsbestätigung mit der Nummer des Widerrufs geht an die angegebene E-Mail-Adresse.',

    // In the finding "withdrawal period under 14 days" when no delivery countries are chosen.
    'allCountries' => 'alle Länder',
];
// "Meine Abos" (Abo-7, P60).
$GLOBALS['TL_LANG']['CONSHO']['mySubscriptions'] = [
    'empty' => 'Sie haben in diesem Shop kein Abo.',
    'number' => 'Abo',
    'product' => 'Produkt',
    'state' => 'Stand',
    'nextBilling' => 'Nächste Abrechnung',
    'actions' => 'Aktionen',
    'cancel' => 'Kündigen',
    'pending' => 'Außerordentliche Kündigung eingegangen, noch nicht entschieden',
    // Abo-7b (P67): a new subscription on today's terms; licence and key stay.
    'renew' => 'Lizenz verlängern',
    'renewedBy' => 'verlängert durch %s',
    'states' => ['active' => 'läuft', 'suspended' => 'ruht (offene Rechnung)', 'paused' => 'pausiert bis %s', 'ending' => 'gekündigt zum %s', 'ended' => 'beendet', 'upcoming' => 'beginnt am %s'],
    // Pausing a subscription (P101, P107).
    'pause' => 'Pausieren',
    'pauseAnnounced' => 'Pause ab %s bis %s angekündigt',
    'withdrawPause' => 'Pause zurücknehmen',
    'pauseTitle' => 'Abo pausieren',
    'pauseIntro' => 'Die Pause beginnt am %s, am Ende der laufenden Periode. In dieser Zeit wird nichts berechnet, und der Zugang ruht. Die Laufzeit verschiebt sich um die Pause.',
    'pauseLength' => 'Dauer',
    'pauseOptionOne' => '%d Monat, bis %s',
    'pauseOptionMany' => '%d Monate, bis %s',
    'pauseConfirm' => 'Pause bestätigen',
    'pauseWithdrawHint' => 'Bis zum Beginn können Sie die Pause unter „Meine Abos" zurücknehmen.',
    'back' => 'Zurück',
    'notice' => [
        'notFound' => 'Dieses Abo haben wir in Ihrem Konto nicht gefunden.',
        'noOrderPage' => 'Die Bestellseite ist in diesem Shop nicht eingerichtet.',
        'renewalUnavailable' => 'Diese Lizenz lässt sich derzeit nicht verlängern.',
        'paused' => 'Die Pause ist angekündigt. Die Bestätigung haben wir Ihnen per E-Mail geschickt.',
        'pauseWithdrawn' => 'Die Pause ist zurückgenommen. Ihr Abo läuft weiter wie bisher.',
        'pauseUnavailable' => 'Dieses Abo lässt sich derzeit nicht so pausieren.',
        'expired' => 'Diese Bestätigungsseite ist nicht mehr abrufbar. Ihre Kündigung ist gespeichert; die Eingangsbestätigung haben Sie per E-Mail erhalten.',
    ],
];
// Cancellation (Abo-7, § 312k BGB). The two button texts are prescribed by law.
$GLOBALS['TL_LANG']['CONSHO']['cancellation'] = [
    'button' => 'Verträge hier kündigen',
    'confirm' => 'jetzt kündigen',
    'formIntro' => 'Hier können Sie Ihr Abo kündigen. Sie erhalten sofort eine Eingangsbestätigung per E-Mail.',
    'kind' => 'Art der Kündigung',
    'kinds' => ['ordinary' => 'ordentliche Kündigung', 'extraordinary' => 'außerordentliche Kündigung (aus wichtigem Grund)'],
    'reason' => 'Grund (nur bei einer außerordentlichen Kündigung)',
    'name' => 'Ihr Name',
    'subscriptionNumber' => 'Abonummer',
    'email' => 'E-Mail-Adresse des Abos',
    'contract' => 'Vertrag',
    'date' => 'Zeitpunkt, zu dem der Vertrag enden soll (optional)',
    'dateHint' => 'Leer lassen: zum frühestmöglichen Zeitpunkt.',
    'earliestIs' => 'Frühestmöglich bei ordentlicher Kündigung: %s.',
    'laterBilled' => 'Liegt Ihr Zeitpunkt danach, werden Abrechnungszeiträume, die vor dem Vertragsende beginnen, noch voll berechnet.',
    'confirmationBy' => 'Die Eingangsbestätigung schicken wir an die E-Mail-Adresse des Abos.',
    'done' => 'Ihre Kündigung ist eingegangen.',
    'mailSent' => 'Die Eingangsbestätigung haben wir an %s geschickt.',
    'mailNotSent' => 'Die Eingangsbestätigung per E-Mail konnte nicht versandt werden; wir holen das nach. Ihre Kündigung ist gespeichert.',
    'save' => 'Bitte speichern oder drucken Sie diese Seite als Nachweis Ihrer Kündigung. Sie ist 90 Tage abrufbar; die Eingangsbestätigung per E-Mail bleibt.',
    'expired' => 'Diese Bestätigungsseite ist nicht mehr abrufbar. Ihre Kündigung ist gespeichert; die Eingangsbestätigung haben Sie per E-Mail erhalten.',
    'error' => [
        'missing' => 'Bitte füllen Sie Abonummer, E-Mail-Adresse und Name aus.',
        'reasonMissing' => 'Bitte nennen Sie für eine außerordentliche Kündigung den Grund.',
        'dateInvalid' => 'Bitte geben Sie den Zeitpunkt als Datum an oder lassen Sie das Feld leer.',
        'notFound' => 'Zu dieser Abonummer und E-Mail-Adresse haben wir kein Abo gefunden. Bitte prüfen Sie Ihre Angaben.',
        'tooMany' => 'Zu viele Versuche. Bitte versuchen Sie es in einer Viertelstunde erneut.',
        'alreadyEnding' => 'Dieses Abo ist bereits gekündigt und endet am %s.',
        'ended' => 'Dieses Abo ist bereits beendet.',
    ],
];
// Online payment (C-11). The page only says what it can prove.
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['online'] = [
    'confirmed' => 'Zahlung eingegangen (vom Zahlungsanbieter bestätigt).',
    'reported' => 'Der Zahlungsanbieter meldet die Zahlung als abgeschlossen. Sobald die Bestätigung eintrifft, erscheint sie hier.',
    'none' => 'Für diese Bestellung ist noch keine Zahlung eingegangen.',
    'failed' => 'Die Zahlung ist fehlgeschlagen.',
    'cancelled' => 'Die Zahlung wurde abgebrochen.',
    'retry' => 'Erneut versuchen',
];
$GLOBALS['TL_LANG']['CONSHO']['invoice']['regenerated'] = 'Neu erzeugt aus den gespeicherten Rechnungsdaten. Die beim Ausstellen abgelegte Datei war nicht verfügbar oder stimmte nicht mit ihrer Prüfsumme überein.';
$GLOBALS['TL_LANG']['CONSHO']['invoiceArchiveFolder']['missing'] = 'ist nicht gewählt oder existiert nicht mehr';
$GLOBALS['TL_LANG']['CONSHO']['invoiceArchiveFolder']['public'] = 'ist öffentlich (über „%s")';

// Shipping costs (E-6, V5).
$GLOBALS['TL_LANG']['CTE']['consho_shipping_costs'] = ['Versandkosten', 'Die Versandarten des Shops mit Ländern, Preisen und Grenzen — aus den Daten der Versandarten, nicht von Hand. Gehört auf die am Startpunkt gewählte Versandkostenseite.'];
$GLOBALS['TL_LANG']['CONSHO']['shippingCosts'] = [
    'none' => 'Derzeit sind keine Versandarten hinterlegt.',
    'allCountries' => 'In alle Lieferländer des Shops.',
    'countries' => 'Nach: %s.',
    'calculated' => 'Der Preis wird im Checkout berechnet.',
    'noAmount' => 'Preis im Checkout',
    'weight' => 'Gewicht',
    'price' => 'Preis',
    'upTo' => 'bis %s kg',
    'proportional' => 'Die Umsatzsteuer auf den Versand richtet sich nach den Steuersätzen der bestellten Waren; der Bruttobetrag im Checkout kann daher abweichen.',
    'limits' => 'Je Stück höchstens: %s.',
    'limitLength' => '%s cm längste Seite',
    'limitGirth' => '%s cm Gurtmaß',
    'limitWeight' => '%s kg',
    'cod' => 'Nachnahme möglich.',
    'codWithFee' => 'Nachnahme möglich, zzgl. Nachnahmegebühr; sie steht im Checkout.',
];

// Phone number for the parcel service (E-6, V6).
$GLOBALS['TL_LANG']['CONSHO']['checkout']['problem']['phoneMissing'] = 'Der Paketdienst dieser Versandart braucht eine Telefonnummer des Empfängers.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['shippingPhone'] = 'Telefon für den Paketdienst';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['shippingPhoneHint'] = 'Der Paketdienst meldet sich damit vor der Zustellung.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['dateOfBirth'] = 'Geburtsdatum';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['dateOfBirthHint'] = 'Für Artikel mit Altersgrenze verlangt diese Versandart Ihr Geburtsdatum.';

// The shipping method for a cart with digital lines only (F-2, F14).
$GLOBALS['TL_LANG']['CONSHO']['checkout']['electronicDelivery'] = 'Elektronische Zustellung';

// Consent to the early start for digital content (F-3, F15), close to the
// wording of § 356 Abs. 6 BGB and § 18 Abs. 1 Z 11 FAGG. Changeable like any
// label through the project's own language file — the operator is responsible for the wording.
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsent'] = 'Ich stimme ausdrücklich zu, dass Sie vor Ablauf der Widerrufsfrist mit der Bereitstellung der digitalen Inhalte beginnen. Mir ist bekannt, dass ich durch diese Zustimmung mit Beginn der Bereitstellung mein Widerrufsrecht verliere.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsentHint'] = 'Freiwillig. Ohne Zustimmung erhalten Sie die digitalen Inhalte nach Ablauf der Widerrufsfrist.';
// Abo-Nachlese (P75): lines of the net group on the review page.
$GLOBALS['TL_LANG']['CONSHO']['checkout']['reviewNet'] = 'zzgl. USt.';
// Abo-7b: a renewal in the cart.
$GLOBALS['TL_LANG']['CONSHO']['checkout']['renewalConsentHint'] = 'Bei einer Lizenzverlängerung gilt das auch für Ihre bestehende Lizenz: Ohne Zustimmung läuft sie erst nach Ablauf der Widerrufsfrist im neuen Abo weiter. Endet Ihr bisheriges Abo vorher, ist die Lizenz bis dahin nicht nutzbar.';
// For products whose withdrawal works like a service (Abo-2, P14, A12), close
// to § 10 FAGG and § 356 Abs. 5 Nr. 2 BGB, read in the primary text on
// 2026-09-29. The operator can change it and is responsible for the wording.
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsentService'] = 'Ich verlange ausdrücklich, dass Sie vor Ablauf der Widerrufsfrist mit der Erbringung der Dienstleistung beginnen. Mir ist bekannt, dass ich mein Widerrufsrecht mit vollständiger Vertragserfüllung durch Sie verliere.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsentHintService'] = 'Freiwillig. Ohne dieses Verlangen beginnen wir erst nach Ablauf der Widerrufsfrist.';
// Content and services in the same cart (DL).
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsentHintMixed'] = 'Freiwillig. Ohne Zustimmung erhalten Sie die digitalen Inhalte und beginnen wir mit den Leistungen erst nach Ablauf der Widerrufsfrist.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digitalConsentService'] = 'Sie haben vor Vertragsausführung ausdrücklich verlangt, dass wir vor Ablauf der Widerrufsfrist mit der Erbringung der Dienstleistung beginnen, und bestätigt, dass Sie Ihr Widerrufsrecht mit vollständiger Vertragserfüllung durch uns verlieren.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digitalConsent'] = 'Sie haben vor Vertragsausführung ausdrücklich zugestimmt, dass wir vor Ablauf der Widerrufsfrist mit der Bereitstellung der digitalen Inhalte beginnen, und bestätigt, dass Sie durch diese Zustimmung mit Beginn der Bereitstellung Ihr Widerrufsrecht verlieren.';

// Subscription terms on the product page, cart, checkout and order line (Abo-2, P15).
// SubscriptionTerms joins them with " · ". `day`/`days` follow "mit"
// (dative: „mit 14 Tagen Frist"), `dayNominative`/`daysNominative` stand alone
// („Kündigungsfrist 30 Tage").
$GLOBALS['TL_LANG']['CONSHO']['subscription'] = [
    'once' => '%s einmalig',
    'perMonth' => '%s / Monat',
    'perYear' => '%s / Jahr',
    'perMonths' => '%s / %s Monate',
    'term' => 'Laufzeit %s',
    'month' => '%s Monat',
    'months' => '%s Monate',
    'year' => '%s Jahr',
    'years' => '%s Jahre',
    'day' => '%s Tag',
    'days' => '%s Tagen',
    'dayNominative' => '%s Tag',
    'daysNominative' => '%s Tage',
    'ends' => 'endet danach',
    'sameTerm' => 'verlängert sich um jeweils %s, Kündigungsfrist %s',
    'indefinite' => 'danach unbefristet, jederzeit mit %s Frist kündbar',
    'oneTimeSale' => 'Einmalkauf',
    'choose' => 'Modell',
    'accountRequired' => 'Für ein Abo brauchen Sie ein Kundenkonto. Sie melden sich in der Kasse an oder registrieren sich dort.',
    // Abo-7b (P70).
    'renewalRefused' => 'Die Lizenz von Abo %s lässt sich nicht mehr verlängern. Bitte entfernen Sie die Zeile im Warenkorb.',
];

// Entitlements on the order page (F-5).
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['entitlements'] = 'Digitale Inhalte';
// DL3: services are activated, not provided.
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['entitlementsServices'] = 'Leistungen';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['entitlementsMixed'] = 'Digitale Inhalte und Leistungen';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['serviceUnlocked'] = 'freigeschaltet';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['serviceRunning'] = 'läuft';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['servicePerformed'] = 'erbracht am %s';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['validUntil'] = 'gültig bis %s';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['inactive'] = 'nicht mehr gültig';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['resting'] = 'ruht, bis die offene Rechnung bezahlt ist';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['download'] = 'Herunterladen';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['downloadOf'] = '%s herunterladen';

// Mails about digital content (F-7, F20).
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['ready'] = [
    'digital' => 'Ihre digitalen Inhalte stehen jetzt auf Ihrer Bestellseite bereit.',
    'service' => 'Ihre Leistungen sind jetzt freigeschaltet; Sie sehen sie auf Ihrer Bestellseite.',
    'mixed' => 'Ihre digitalen Inhalte stehen jetzt auf Ihrer Bestellseite bereit, Ihre Leistungen sind freigeschaltet.',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['digitalReleased']['invoice'] = 'Die Rechnung %s liegt dieser E-Mail bei.';
// Wertgutscheine (WG4): the mail with the codes, the PDF and the order page.
$GLOBALS['TL_LANG']['CONSHO']['mail']['vouchersIssued'] = [
    'subject' => 'Ihr Gutschein: Bestellung %s',
    'intro' => 'vielen Dank für Ihre Bestellung %s. Hier ist Ihr Gutschein:',
    'attached' => 'Jeder Gutschein liegt dieser E-Mail als PDF bei — zum Ausdrucken oder Weitergeben.',
];
// Wertgutscheine (WG-5, E3): redemption. %1$s the code (…MDSN), %2$s an amount.
$GLOBALS['TL_LANG']['CONSHO']['cart']['voucher'] = [
    'code' => 'Gutschein einlösen',
    'apply' => 'Einlösen',
    'remove' => 'Entfernen',
    'removeLabel' => 'Gutschein %1$s entfernen',
    'later' => 'Gutscheine werden in der Kasse angerechnet.',
    'notForVouchers' => 'Gutscheine lassen sich nicht mit Gutscheinen bezahlen.',
    'applied' => 'Gutschein %1$s',
    'payable' => 'Noch zu zahlen',
    'covered' => 'Ihre Gutscheine decken den ganzen Betrag — eine Zahlungsart ist nicht nötig.',
    'change' => 'Gutscheine im Warenkorb ändern',
    'payment' => 'Gutschein',
    'status' => [
        'usable' => 'Gutschein %1$s: Guthaben %2$s',
        'spent' => 'Gutschein %1$s ist aufgebraucht.',
        'blocked' => 'Gutschein %1$s ist gesperrt.',
        'expired' => 'Gutschein %1$s ist abgelaufen.',
        'otherCurrency' => 'Gutschein %1$s gilt nicht in dieser Währung.',
        'notApplicable' => 'Gutschein %1$s passt zu keinem Artikel in diesem Einkauf.',
        'unknown' => 'Gutschein %1$s gilt in diesem Shop nicht.',
    ],
    'after' => 'Danach bleiben %2$s auf dem Gutschein %1$s.',
];
$GLOBALS['TL_LANG']['CONSHO']['voucher'] = [
    'title' => 'Gutschein',
    'code' => 'Gutscheincode',
    'validUntil' => 'Gültig bis %s',
    'unlimited' => 'Unbefristet gültig',
    'howTo' => 'Einzulösen im Warenkorb unter „Gutschein einlösen".',
    'balance' => 'Restguthaben',
    'blocked' => 'gesperrt',
    'heading' => 'Ihre Gutscheine',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['digitalReleased']['digital'] = ['subject' => 'Ihre digitalen Inhalte: Bestellung %s', 'intro' => 'aus Ihrer Bestellung %s stehen jetzt auf Ihrer Bestellseite bereit:'];
$GLOBALS['TL_LANG']['CONSHO']['mail']['digitalReleased']['service'] = ['subject' => 'Freigeschaltet: Bestellung %s', 'intro' => 'aus Ihrer Bestellung %s ist jetzt freigeschaltet:'];
$GLOBALS['TL_LANG']['CONSHO']['mail']['digitalReleased']['mixed'] = ['subject' => 'Bereit und freigeschaltet: Bestellung %s', 'intro' => 'aus Ihrer Bestellung %s ist jetzt bereit bzw. freigeschaltet:'];
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digital']['now'] = 'Die digitalen Inhalte Ihrer Bestellung stellen wir auf Ihrer Bestellseite bereit.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digital']['afterPayment'] = 'Die digitalen Inhalte Ihrer Bestellung stellen wir auf Ihrer Bestellseite bereit, sobald Ihre Zahlung eingegangen ist.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digital']['afterPeriod'] = 'Die digitalen Inhalte Ihrer Bestellung stellen wir nach Ablauf der Widerrufsfrist am %s auf Ihrer Bestellseite bereit.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digital']['afterBoth'] = 'Die digitalen Inhalte Ihrer Bestellung stellen wir auf Ihrer Bestellseite bereit, sobald Ihre Zahlung eingegangen und die Widerrufsfrist am %s abgelaufen ist.';
// DL3: the same points in time for services and mixed orders.
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['service'] = [
    'now' => 'Die Leistungen Ihrer Bestellung schalten wir frei; Sie sehen sie auf Ihrer Bestellseite.',
    'afterPayment' => 'Die Leistungen Ihrer Bestellung schalten wir frei, sobald Ihre Zahlung eingegangen ist.',
    'afterPeriod' => 'Die Leistungen Ihrer Bestellung schalten wir nach Ablauf der Widerrufsfrist am %s frei.',
    'afterBoth' => 'Die Leistungen Ihrer Bestellung schalten wir frei, sobald Ihre Zahlung eingegangen und die Widerrufsfrist am %s abgelaufen ist.',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['mixed'] = [
    'now' => 'Die digitalen Inhalte und Leistungen Ihrer Bestellung stellen wir auf Ihrer Bestellseite bereit bzw. schalten sie frei.',
    'afterPayment' => 'Die digitalen Inhalte und Leistungen Ihrer Bestellung stellen wir bereit bzw. schalten sie frei, sobald Ihre Zahlung eingegangen ist.',
    'afterPeriod' => 'Die digitalen Inhalte und Leistungen Ihrer Bestellung stellen wir nach Ablauf der Widerrufsfrist am %s bereit bzw. schalten sie frei.',
    'afterBoth' => 'Die digitalen Inhalte und Leistungen Ihrer Bestellung stellen wir bereit bzw. schalten sie frei, sobald Ihre Zahlung eingegangen und die Widerrufsfrist am %s abgelaufen ist.',
];

// Block G: availability (G7). The number only in the cart, where it decides the quantity.
$GLOBALS['TL_LANG']['CONSHO']['stock'] = [
    'available' => 'lieferbar',
    'few' => 'nur noch wenige',
    'unavailable' => 'derzeit nicht verfügbar',
    'backorder' => 'auf Bestellung, längere Lieferzeit',
    'onlyLeft' => 'nur noch %s verfügbar',
    'availableFrom' => 'wieder lieferbar ab %s',
];

// Block G (G12, G15): "Benachrichtigen, wenn wieder da" — only for logged-in customers.
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['subject'] = 'Wieder lieferbar: %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['intro'] = '„%s" ist wieder lieferbar. Sie wollten benachrichtigt werden.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['button'] = 'Zum Artikel';
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['once'] = 'Diese Nachricht kommt nur einmal. Ihr Wunsch ist damit erledigt.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['confirm'] = 'Über den Link können Sie zugleich Ihre E-Mail-Adresse bestätigen: angemeldet mit Ihrem Kundenkonto, auf der Seite des Artikels. Das geht 24 Stunden lang; der Artikel selbst bleibt erreichbar.';
$GLOBALS['TL_LANG']['CONSHO']['stockNotice'] = [
    'subscribe' => 'Benachrichtigen, wenn wieder da',
    'unsubscribe' => 'Nicht mehr benachrichtigen',
    'subscribed' => 'Sie werden per E-Mail benachrichtigt, sobald der Artikel wieder lieferbar ist.',
    'login' => 'Melden Sie sich mit Ihrem Kundenkonto an, um benachrichtigt zu werden, sobald der Artikel wieder lieferbar ist.',
    'result' => [
        'subscribed' => 'Sie werden benachrichtigt, sobald der Artikel wieder lieferbar ist.',
        'unsubscribed' => 'Sie werden zu diesem Artikel nicht mehr benachrichtigt.',
        'available' => 'Der Artikel ist wieder lieferbar.',
        'login' => 'Bitte melden Sie sich zuerst mit Ihrem Kundenkonto an.',
        'confirmed' => 'Ihre E-Mail-Adresse ist bestätigt.',
        'invalid' => 'Der Link zum Bestätigen ist nicht mehr gültig.',
        'otherAccount' => 'Der Link gehört zu einem anderen Kundenkonto oder zu einer anderen Adresse.',
    ],
    'confirm' => [
        'pending' => 'Bestätigen Sie hier Ihre E-Mail-Adresse.',
        'button' => 'E-Mail-Adresse bestätigen',
        'login' => 'Um Ihre E-Mail-Adresse zu bestätigen, melden Sie sich mit Ihrem Kundenkonto an und öffnen Sie den Link danach noch einmal.',
        'confirmed' => 'Ihre E-Mail-Adresse ist bestätigt.',
        'invalid' => 'Der Link zum Bestätigen ist nicht mehr gültig. Der Artikel ist trotzdem hier.',
        'otherAccount' => 'Der Link gehört zu einem anderen Kundenkonto oder zu einer anderen Adresse.',
    ],
];

// Block H (H-4): discount code in the cart. Placeholders: %1$s the code, %2$s the
// amount still missing to the minimum order value.
$GLOBALS['TL_LANG']['CONSHO']['cart']['discount'] = [
    'code' => 'Rabattcode',
    'apply' => 'Einlösen',
    'remove' => 'Code entfernen',
    'subtotal' => 'Warenwert',
    'freeShipping' => 'versandkostenfrei',
    'status' => [
        'none' => '',
        'applied' => 'Der Code %1$s ist eingelöst.',
        'unknown' => 'Den Code %1$s gibt es nicht. Bitte achten Sie auf Groß- und Kleinschreibung.',
        'notActive' => 'Der Code %1$s gilt derzeit nicht.',
        'notForYou' => 'Der Code %1$s gilt nicht für diesen Einkauf.',
        'minimumOrder' => 'Der Code %1$s gilt ab einem Mindestbestellwert — es fehlen noch %2$s.',
        'noMatchingGoods' => 'Der Code %1$s gilt für keinen Artikel in Ihrem Warenkorb.',
        'exhausted' => 'Der Code %1$s ist bereits eingelöst und gilt nicht mehr.',
        'superseded' => 'Der Code %1$s gilt, ein anderer Rabatt bringt Ihnen aber mehr — der gilt.',
        'nothingToSave' => 'Der Code %1$s erlässt die Versandkosten — bei dieser Bestellung fallen keine an. Er bleibt Ihnen für eine andere.',
    ],
];

// Connect-2, CW-1: versions of order records are history, not a way back.
$GLOBALS['TL_LANG']['CONSHO']['versionFrozen'] = 'Frühere Fassungen von Bestellungen, Zahlungen, Lieferungen und ihren Teilen lassen sich vergleichen, aber nicht wiederherstellen: Sie würden Zahlungseingänge, Status und Sperren an den Prüfungen vorbei zurücksetzen. Bitte die Angabe stattdessen neu eintragen.';
