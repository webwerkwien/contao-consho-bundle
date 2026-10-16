<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_delivery']['shippedAt'] = ['Versendet am', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['carrier'] = ['Versanddienst', 'z. B. Post AT, DPD, GLS.'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['trackingNumber'] = ['Sendungsnummer', ''];

$GLOBALS['TL_LANG']['tl_consho_delivery']['delivery_legend'] = 'Lieferung';

$GLOBALS['TL_LANG']['tl_consho_delivery']['new'] = ['Neue Lieferung', 'Lieferung erfassen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['items'] = ['Inhalt', 'Inhalt der Lieferung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['edit'] = ['Bearbeiten', 'Lieferung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['delete'] = ['Löschen', 'Lieferung ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['show'] = ['Details', 'Details der Lieferung ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['report'] = ['Als versandt melden', 'Lieferung ID %s als versandt melden: Der Kunde bekommt eine Mail mit dem Inhalt des Pakets, bei Versand-Zahlarten entsteht die Rechnung. Danach ist die Lieferung gesperrt.'];

$GLOBALS['TL_LANG']['tl_consho_delivery']['notReported'] = 'noch nicht gemeldet';
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportedOn'] = 'gemeldet am %s';
$GLOBALS['TL_LANG']['tl_consho_delivery']['locked'] = 'Diese Lieferung ist als versandt gemeldet und gesperrt: Der Kunde hat die Mail über dieses Paket bereits erhalten.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportResult'] = [
    'reported' => 'Die Lieferung zur Bestellung %s ist gemeldet, der Kunde hat die Versandmail bekommen.',
    'reportedNoMail' => 'Die Lieferung zur Bestellung %s ist gemeldet. Die Versandmail ging nicht hinaus — den Grund nennt das Systemlog.',
    'tooMuch' => 'Nicht gemeldet: Diese Positionen übersteigen, was noch offen ist: %s.',
    'empty' => 'Nicht gemeldet: Die Lieferung enthält keine Position mit einer Menge.',
    'alreadyReported' => 'Diese Lieferung ist bereits gemeldet.',
];

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['orderItem'] = ['Position', 'Welche Position dieser Bestellung mitgegangen ist.'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['quantity'] = ['Menge', 'Wie viel davon mit dieser Lieferung ging. Teillieferungen ergeben sich daraus von selbst.'];

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['item_legend'] = 'Positionsmenge';

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['new'] = ['Neue Positionsmenge', 'Positionsmenge erfassen'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['edit'] = ['Bearbeiten', 'Positionsmenge ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['delete'] = ['Löschen', 'Positionsmenge ID %s löschen'];
