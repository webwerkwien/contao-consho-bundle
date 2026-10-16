<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['title'] = ['Bezeichnung', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['type'] = ['Art', 'Wie versendet wird.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['host'] = ['Server', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['port'] = ['Port', 'Üblich: 587 mit TLS, 465 mit SSL.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['encryption'] = ['Verschlüsselung', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['username'] = ['Benutzername', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['password'] = ['Passwort', 'Wird verschlüsselt gespeichert und deshalb nie wieder angezeigt. Leer lassen behält das gespeicherte Passwort; zum Entfernen den Transport löschen.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['tenantId'] = ['Verzeichnis-ID (Tenant)', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['clientId'] = ['Anwendungs-ID (Client)', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['clientSecret'] = ['Client-Geheimnis', 'Wird verschlüsselt gespeichert und deshalb nie wieder angezeigt. Leer lassen behält den gespeicherten Wert.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['senderAddress'] = ['Absenderadresse', 'Muss zur authentifizierten Domain passen, sonst landen Mails im Spam.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['senderName'] = ['Absendername', ''];

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['title_legend'] = 'Bezeichnung';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['server_legend'] = 'Server';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['oauth_legend'] = 'Microsoft-365-Zugang';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['sender_legend'] = 'Absender';

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['types'] = [
    'smtp' => 'SMTP',
    'graph' => 'Microsoft Graph (Microsoft 365)',
    'sendmail' => 'sendmail (lokal) — nur bei bestandener Zustellbarkeitsprüfung',
];

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['encryptions'] = [
    'tls' => 'TLS (empfohlen, Port 587)',
    'ssl' => 'SSL (Port 465)',
    'none' => 'keine — nur im eigenen Netz vertretbar',
];

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['new'] = ['Neuer Transport', 'Mail-Transport anlegen'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['edit'] = ['Bearbeiten', 'Transport ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['delete'] = ['Löschen', 'Transport ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['show'] = ['Details', 'Details des Transports ID %s anzeigen'];

// "Testmail senden" (C-9).
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testMail'] = ['Testmail senden', 'Eine Testmail über die Mail-Konfiguration ID %s senden'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testLegend'] = 'Testmail';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testRecipient'] = 'Empfänger';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testHelp'] = 'Absender ist %s — genau wie bei den Bestellbestätigungen. Am besten eine Adresse bei einem anderen Anbieter wählen.';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testSubmit'] = 'Testmail senden';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testBack'] = 'Zurück';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testSent'] = 'Die Testmail an %s wurde vom Mailserver angenommen. Ob sie ankommt, zeigt das Postfach.';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testFailed'] = 'Die Testmail wurde nicht angenommen. Antwort: %s';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testInvalid'] = '„%s" ist keine gültige E-Mail-Adresse.';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['deliverabilityLegend'] = 'Zustellbarkeit';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['deliverabilityClean'] = 'SPF und DMARC sind eingetragen, und der Absender passt zur Anmeldung. Ob eine Mail im Spam landet, entscheidet der Empfänger trotzdem selbst.';
