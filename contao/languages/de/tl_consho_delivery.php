<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_delivery']['shippedAt'] = ['Versanddatum', ''];
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

// RK-5: a replacement delivery for a complaint.
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementFor'] = 'Ersatzlieferung zu Reklamation %s: ohne Rechnung, sie zählt nicht als Lieferung der Bestellung. Beim Melden geht die Ware aus dem Lager.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementCompleteHelp'] = 'Alles, was zu dieser Reklamation noch zu ersetzen ist, geht mit diesem Paket. Für einen Teilersatz den Haken entfernen und die Mengen angeben; 0 heißt: nicht im Paket.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['contentsToReplace'] = 'zu ersetzen';
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementNothingLeft'] = 'Zu dieser Reklamation ist nichts mehr zu ersetzen.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementInvalid'] = 'Der Inhalt ist nicht übernommen: Jede Menge muss eine Zahl zwischen 0 und der noch zu ersetzenden Menge sein.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notifyReplacementHelp'] = 'Beim Speichern: Die Ware geht aus dem Lager, der Kunde bekommt die Mail zur Ersatzlieferung mit dem Inhalt des Pakets, mit einem Nummernkreis „Lieferscheinnummer“ entsteht der Lieferschein, und die Lieferung ist danach gesperrt. Keine Rechnung. Ohne Haken bleibt sie ein Entwurf.';

$GLOBALS['TL_LANG']['tl_consho_delivery']['new'] = ['Neue Lieferung', 'Lieferung erfassen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['items'] = ['Inhalt', 'Inhalt der Lieferung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['edit'] = ['Bearbeiten', 'Lieferung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['delete'] = ['Löschen', 'Lieferung ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['show'] = ['Details', 'Details der Lieferung ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['report'] = ['Als versandt melden', 'Lieferung ID %s als versandt melden: Der Kunde bekommt eine Mail mit dem Inhalt des Pakets, bei Versand-Zahlarten entsteht die Rechnung, mit einem Nummernkreis „Lieferscheinnummer" der Lieferschein. Danach ist die Lieferung gesperrt.'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['deliveryNote'] = ['Lieferschein', 'Lieferschein der Lieferung ID %s als PDF öffnen'];

$GLOBALS['TL_LANG']['tl_consho_delivery']['notReported'] = 'noch nicht gemeldet';
$GLOBALS['TL_LANG']['tl_consho_delivery']['notNotified'] = 'nicht versendet';

$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotification'] = ['Benachrichtigung erneut senden', 'Die Mail zur Lieferung ID %s noch einmal an den Kunden senden'];
$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationSent'] = 'Die Benachrichtigung zur Lieferung der Bestellung %s ging erneut an %s.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationFailed'] = 'Die Benachrichtigung zur Lieferung der Bestellung %s konnte nicht gesendet werden. Den Grund nennt das Systemlog.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationAnonymized'] = 'Die Benachrichtigung zur Lieferung der Bestellung %s wurde nicht gesendet: Die Kundendaten sind nach Ablauf der Aufbewahrungsfrist anonymisiert.';
$GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationNothing'] = 'Zu dieser Lieferung der Bestellung %s gibt es keine Benachrichtigung: Sie ist noch nicht gemeldet oder eine elektronische Freischaltung mit eigener Mail.';

// Column headings and the kind icon of the list.
$GLOBALS['TL_LANG']['tl_consho_delivery']['kind'] = ['Art', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['notification'] = ['Benachrichtigung', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportedAt'] = ['Gemeldet am', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['notifiedAt'] = ['Benachrichtigt am', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['lockedAt'] = ['Gesperrt seit', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['complaint'] = ['Reklamation (ID)', ''];
$GLOBALS['TL_LANG']['tl_consho_delivery']['kindDelivery'] = 'Lieferung';

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
$GLOBALS['TL_LANG']['tl_consho_delivery']['replacementLabel'] = 'Ersatz zu %s';
$GLOBALS['TL_LANG']['tl_consho_delivery']['reportResult'] = [
    'reported' => 'Die Lieferung zur Bestellung %s ist gemeldet, der Kunde hat die Versandmail bekommen.',
    'reportedNoMail' => 'Die Lieferung zur Bestellung %s ist gemeldet. Die Versandmail ging nicht hinaus — den Grund nennt das Systemlog.',
    'reportedReplacement' => 'Die Ersatzlieferung zur Bestellung %s ist gemeldet, der Kunde hat die Mail zur Ersatzlieferung bekommen.',
    'reportedReplacementNoMail' => 'Die Ersatzlieferung zur Bestellung %s ist gemeldet. Die Mail zur Ersatzlieferung ging nicht hinaus — den Grund nennt das Systemlog.',
    'tooMuch' => 'Nicht gemeldet: Diese Positionen übersteigen, was noch offen ist: %s.',
    'tooMuchForComplaint' => 'Nicht gemeldet: Diese Positionen übersteigen, was die Reklamation noch zu ersetzen hat: %s.',
    'outOfStock' => 'Nicht gemeldet: Für diese Positionen reicht der Bestand nicht, und sie sind nicht nachbestellbar: %s. Es wurde nichts ausgebucht.',
    'notReplacement' => 'Nicht gemeldet: Die Reklamation ist nicht mehr als Ersatz offen.',
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
