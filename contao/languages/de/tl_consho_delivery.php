<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_delivery']['shippedAt'] = ['Versendet am', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['carrier'] = ['Versanddienst', 'z. B. Post AT, DPD, GLS.'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['trackingNumber'] = ['Sendungsnummer', ''];

$GLOBALS['TL_LANG']['tl_consho_delivery']['delivery_legend'] = 'Lieferung';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contents_legend'] = 'Inhalt';
$GLOBALS['TL_LANG']['tl_consho_delivery']['report_legend'] = 'Versand melden';

$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsComplete'] = 'Lieferung vollständig';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsCompleteHelp'] = 'Alles, was von der Bestellung noch offen ist, geht mit diesem Paket. Für eine Teillieferung den Haken entfernen und die Mengen angeben; 0 heißt: nicht im Paket.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsItem'] = 'Position';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsOpen'] = 'offen';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsInParcel'] = 'in diesem Paket';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsQuantityLabel'] = 'Menge von %s in diesem Paket';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsNothingOpen'] = 'Von dieser Bestellung ist nichts mehr zu versenden:';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsAccount'] = [
    'ordered' => 'bestellt %s',
    'shipped' => 'versandt %s',
    'withdrawn' => 'widerrufen %s',
    'planned' => 'in einer anderen, noch nicht gemeldeten Lieferung %s',
];
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsCancelled'] = 'Die Bestellung ist storniert, es ist nichts zu versenden.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsNoGoods'] = 'Diese Bestellung enthält keine Ware, die in ein Paket geht.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsInvalid'] = 'Der Inhalt ist nicht übernommen: Jede Menge muss eine Zahl zwischen 0 und der offenen Menge sein.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notify'] = 'Als versandt melden und Kunden verständigen';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notifyHelp'] = 'Beim Speichern: Der Kunde bekommt die Versandmail mit dem Inhalt des Pakets, je nach Zahlungsart und Einstellung entstehen Rechnung und Lieferschein, und die Lieferung ist danach gesperrt. Ohne Haken bleibt sie ein Entwurf und wird später in der Liste der Lieferungen gemeldet.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notifyDenied'] = 'Nicht gemeldet: Dafür fehlt die Berechtigung, die Bestellung zu bearbeiten.';

$GLOBALS['TL_LANG']['tl_consho_delivery']['new'] = ['Neue Lieferung', 'Lieferung erfassen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['items'] = ['Inhalt', 'Inhalt der Lieferung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['edit'] = ['Bearbeiten', 'Lieferung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['delete'] = ['Löschen', 'Lieferung ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['show'] = ['Details', 'Details der Lieferung ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['report'] = ['Als versandt melden', 'Lieferung ID %s als versandt melden: Der Kunde bekommt eine Mail mit dem Inhalt des Pakets, bei Versand-Zahlarten entsteht die Rechnung, mit einem Nummernkreis „Lieferscheinnummer" der Lieferschein. Danach ist die Lieferung gesperrt.'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['deliveryNote'] = ['Lieferschein', 'Lieferschein der Lieferung ID %s als PDF öffnen'];

$GLOBALS['TL_LANG']['tl_consho_delivery']['notReported'] = 'noch nicht gemeldet';
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportedOn'] = 'gemeldet am %s';

// Services in the delivery list (DeliveryServiceListener).
$GLOBALS['TL_LANG']['tl_consho_delivery']['servicePerformed'] = 'Leistung erbracht';
$GLOBALS['TL_LANG']['tl_consho_delivery']['servicePerformedTitle'] = '„%s“ als vollständig erbracht melden';
$GLOBALS['TL_LANG']['tl_consho_delivery']['servicePerformedConfirm'] = 'Leistung als vollständig erbracht melden? Das lässt sich nicht zurücknehmen. Hat der Kunde dem vorzeitigen Beginn zugestimmt, erlischt damit sein Widerrufsrecht für diese Position.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['servicePerformedOn'] = '%s: erbracht am %s';
$GLOBALS['TL_LANG']['tl_consho_delivery']['releaseOn'] = '„%s“ wird am %s freigeschaltet.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['releaseAfterPayment'] = '„%s“ wird nach Zahlungseingang freigeschaltet.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['releaseDue'] = '„%s“ wird mit dem nächsten Lauf freigeschaltet.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['releaseThenPerformed'] = 'Danach steht hier an der Lieferung „Leistung erbracht“.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['locked'] = 'Diese Lieferung ist als versandt gemeldet und gesperrt: Der Kunde hat die Mail über dieses Paket bereits erhalten.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportResult'] = [
    'reported' => 'Die Lieferung zur Bestellung %s ist gemeldet, der Kunde hat die Versandmail bekommen.',
    'reportedNoMail' => 'Die Lieferung zur Bestellung %s ist gemeldet. Die Versandmail ging nicht hinaus — den Grund nennt das Systemlog.',
    'tooMuch' => 'Nicht gemeldet: Diese Positionen übersteigen, was noch offen ist: %s.',
    'empty' => 'Nicht gemeldet: Die Lieferung enthält keine Position mit einer Menge.',
    'alreadyReported' => 'Diese Lieferung ist bereits gemeldet.',
    'unknown' => 'Nicht gemeldet: Die Lieferung ist nicht mehr da.',
];

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['orderItem'] = ['Position', 'Welche Position dieser Bestellung mitgegangen ist.'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['quantity'] = ['Menge', 'Wie viel davon mit dieser Lieferung ging. Teillieferungen ergeben sich daraus von selbst.'];

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['item_legend'] = 'Positionsmenge';

$GLOBALS['TL_LANG']['tl_consho_delivery_item']['new'] = ['Neue Positionsmenge', 'Positionsmenge erfassen'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['edit'] = ['Bearbeiten', 'Positionsmenge ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_delivery_item']['delete'] = ['Löschen', 'Positionsmenge ID %s löschen'];
