<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_cart_rule']['title'] = ['Bezeichnung', 'Steht im Warenkorb und auf der Rechnung als Name des Rabatts, etwa „Frühlingsrabatt".'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['code'] = ['Code', 'Den tippt der Kunde im Warenkorb ein. Groß- und Kleinschreibung zählt. Leer: Die Regel gilt von selbst, ohne Code.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['effect'] = ['Wirkung', ''];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['effects'] = ['percent' => 'Prozent vom Warenwert', 'amount' => 'Fester Betrag', 'freeShipping' => 'Versandkostenfrei'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['percent'] = ['Rabatt in Prozent', 'Auf die Waren, für die die Regel gilt.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['amount'] = ['Betrag', 'So, wie der Kunde die Preise sieht: brutto, für Kunden mit Nettopreisen netto. Nie mehr als der Wert der Waren, für die die Regel gilt. Braucht eine Währung.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['reduced'] = ['Auch für bereits reduzierte Ware', 'Ohne Häkchen zählen Artikel mit Aktion oder Preisregel nicht mit.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['shops'] = ['Shops', 'Leer: alle.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['currency'] = ['Währung', 'ISO-Code, etwa EUR. Nötig bei einem festen Betrag oder einem Mindestbestellwert — die Regel gilt dann nur in dieser Währung.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['minimumOrder'] = ['Mindestbestellwert', 'Warenwert ohne Versand, Zahlungsart und Abos, so wie der Kunde ihn sieht. 0: keiner.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['products'] = ['Produkte', 'Leer: alle. Der Rabatt gilt nur für diese Waren.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['manufacturers'] = ['Hersteller', 'Leer: alle.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['productTypes'] = ['Produkttypen', 'Leer: alle.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['memberGroups'] = ['Kundengruppen', 'Leer: alle, auch Gäste.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['maxUses'] = ['Einlösungen insgesamt', '0: unbegrenzt. Gezählt beim Kauf; ein Storno gibt die Einlösung zurück.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['maxUsesPerCustomer'] = ['Einlösungen je Kunde', '0: unbegrenzt. Mitglieder nach Kundenkonto, Gäste nach E-Mail-Adresse.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['published'] = ['Aktiv', 'Gilt nur, solange das Häkchen gesetzt ist und der Zeitraum passt.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['validFrom'] = ['Gültig ab', 'Datum und Uhrzeit, einschließlich. Leer: sofort.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['validTo'] = ['Gültig bis', 'Datum und Uhrzeit, ausschließlich. Leer: ohne Ende.'];

$GLOBALS['TL_LANG']['tl_consho_cart_rule']['title_legend'] = 'Regel';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['effect_legend'] = 'Wirkung';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['condition_legend'] = 'Gilt für — alle ausgefüllten Felder müssen passen';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['limit_legend'] = 'Einlösungen';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['publish_legend'] = 'Zeitraum';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['currencyMissing'] = 'Ein fester Betrag oder ein Mindestbestellwert braucht eine Währung — ohne sie gilt die Regel in keinem Warenkorb.';
