<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['number'] = ['Widerrufsnummer', 'Aus der Bestellnummer abgeleitet.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['receivedAt'] = ['Eingegangen am', 'Zeitpunkt des Eingangs nach der Uhr des Servers — so steht er in der Eingangsbestätigung.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['source'] = ['Weg', 'Über die Bestellseite (Link aus der Mail) oder über das Widerrufsformular.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['name'] = ['Name', 'Wie vom Kunden angegeben oder bestätigt.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['orderReference'] = ['Angegebene Bestellnummer', 'So, wie der Kunde sie im Widerrufsformular eingetippt hat — auch wenn sie zu keiner Bestellung passte.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['afterWindow'] = ['Nach Fensterende eingegangen', 'Eingegangen nach „letzter Versand + Frist + Puffer". Das Fenster rechnet ab Versand, die Frist ab Erhalt — ob der Widerruf rechtzeitig war, entscheiden Sie.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['email'] = ['E-Mail', 'Die Adresse für die Eingangsbestätigung.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scope'] = ['Umfang', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['part'] = ['Widerrufener Teil', 'In den Worten des Kunden.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['message'] = ['Nachricht', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['confirmationSentAt'] = ['Bestätigung versandt am', 'Leer: Die Eingangsbestätigung ging nicht hinaus — den Grund nennt das Systemlog. Der Widerruf gilt trotzdem.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['state'] = ['Stand', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['states'] = ['received' => 'eingegangen', 'accepted' => 'angenommen', 'rejected' => 'abgelehnt'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decidedAt'] = ['Entschieden am', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['reason'] = ['Begründung der Ablehnung', 'Bei einer Ablehnung zur Bestellung ging sie wörtlich an den Kunden; beim Abweisen ohne Bestellung blieb sie intern.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnReceivedAt'] = ['Rücksendung erhalten am', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundShipping'] = ['Erstatteter Versandanteil', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['valueCompensation'] = ['Wertersatz', 'Für eine Dienstleistung einbehalten (§ 357a Abs. 2 BGB): vorgeschlagen bei der Annahme, festgelegt mit der Erstattung.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundAmount'] = ['Erstattet', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundedAt'] = ['Erstattet am', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['process'] = ['Bearbeiten', 'Widerruf ID %s bearbeiten: annehmen oder ablehnen, Rücksendung, Erstattung'];

// The page "Widerruf bearbeiten" (D-3).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['back'] = 'Zurück';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['labelDue'] = 'Erstattung fällig bis %s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['pid'] = ['Bestellung', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['signalsColumn'] = 'Status';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['customerColumn'] = 'Kunde';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDueColumn'] = 'Erstattung fällig bis';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['amountColumn'] = 'Erstattet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['signals'] = [
    'state' => [
        'received' => 'Widerruf eingegangen — zu entscheiden',
        'accepted' => 'Widerruf angenommen — Erstattung offen',
        'overdue' => 'Erstattung überfällig',
        'rejected' => 'Widerruf abgelehnt',
        'done' => 'Widerruf erledigt',
    ],
    'return' => [
        'none' => 'Rücksendung: noch nichts zurück',
        'partial' => 'Rücksendung: teilweise zurück',
        'complete' => 'Rücksendung: vollständig zurück',
        'pending' => 'Rücksendung: erst nach der Entscheidung',
        'na' => 'Rücksendung: nicht erforderlich',
    ],
    'refund' => [
        'open' => 'Rückzahlung: offen',
        'done' => 'Rückzahlung: gebucht',
        'na' => 'Rückzahlung: keine',
    ],
];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['labelRefunded'] = 'erstattet am %s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['pageTitle'] = 'Widerruf %s zur Bestellung %s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['headOrder'] = 'zur Bestellung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['declarationLegend'] = 'Erklärung und Fristen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['nextLegend'] = 'Nächster Schritt';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['doneRejected'] = 'Abgelehnt — der Kunde hat die Begründung per Mail erhalten.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['doneRefunded'] = 'Erledigt — die Erstattung ist gebucht, der Kunde hat den Beleg per Mail erhalten.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['datesLegend'] = 'Fristen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['windowClosesAt'] = 'Fensterende';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['windowOpen'] = 'noch keines, solange Ware aussteht';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDueAt'] = 'Erstattung fällig bis';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['datesHelp'] = 'Hinweise, kein Urteil: Das Fensterende ist letzter Versand + Frist + Zustellpuffer – eine Schätzung, denn die Frist beginnt mit dem Erhalt der Ware. Ein Widerruf danach wird trotzdem entgegengenommen und als „nach Fensterende eingegangen“ markiert; ob er rechtzeitig war, beurteilen Sie. Die Erstattung ist binnen 14 Tagen fällig (§ 357 Abs. 1 BGB; § 14 Abs. 1 FAGG: ab Zugang der Erklärung).';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptLegend'] = 'Annehmen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colItem'] = 'Position';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colOrdered'] = 'bestellt';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colDeclared'] = 'laut Erklärung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colLeft'] = 'noch nicht widerrufen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colAccept'] = 'annehmen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colAccepted'] = 'angenommen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colReturned'] = 'zurückerhalten';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptHelp'] = 'Vorbelegt mit dem, was der Kunde genannt hat; bei „ganze Bestellung" mit allem Offenen, bei einem Teil als Freitext mit nichts — dann den Text oben lesen. Die Erklärung selbst bleibt unverändert.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptSubmit'] = 'Annehmen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectLegend'] = 'Ablehnen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectHelp'] = 'Geht wörtlich per Mail an den Kunden.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectSubmit'] = 'Ablehnen und Kunden benachrichtigen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnLegend'] = 'Rücksendung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnSubmit'] = 'Rücksendung speichern';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundLegend'] = 'Erstattung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['retentionHint'] = 'Die Ware ist noch nicht als zurückerhalten erfasst. Sie dürfen die Erstattung zurückhalten, bis sie zurück ist oder der Kunde die Absendung nachweist — außer, Sie haben angeboten, die Ware abzuholen (§ 357 Abs. 4 BGB, § 14 Abs. 3 FAGG). Sie müssen es nicht.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['processWithdrawal'] = ['Widerruf bearbeiten', 'Widerruf bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundGoods'] = 'Ware';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['shippingHelp'] = 'Noch nicht erstattete Versandkosten: %s. Bei einem Widerruf der ganzen Bestellung vorgeschlagen, sonst 0 — zum Teilwiderruf sagen § 357 Abs. 2 BGB und § 14 FAGG nichts. Mehrkosten einer teureren als der günstigsten Standardlieferung müssen nicht erstattet werden.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundSubmit'] = 'Erstattung buchen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDocumentLabel'] = 'Beleg';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['subscriptionLabel'] = 'Abo';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationHelp'] = 'Vorschlag für den Wertersatz einer Dienstleistung (§ 357a Abs. 2 BGB): Zeilenbetrag × genutzte Tage / Tage der bezahlten Periode; genutzt ist jede angefangene 24-Stunden-Spanne von der Freigabe bis zum Eingang des Widerrufs. Sie entscheiden über den Betrag; er wird von der Erstattung abgezogen, die Rechnung dann korrigiert statt storniert.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationLine'] = '%1$s × %2$d / %3$d Tage = %4$s';
// WG-6: the refund goes to vouchers first (WG11).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundVouchers'] = ['Davon auf Gutscheine', 'Mit Gutschein bezahlt: %s. Die Erstattung geht zuerst dorthin zurück (dasselbe Zahlungsmittel, § 14 Abs. 1 FAGG, § 357 Abs. 3 BGB), höchstens so viel, wie diese Erstattung auszahlt; der Rest an die Zahlungsart. Ein abgelaufener Gutschein wird dadurch wieder unbefristet gültig; ein gesperrter bekommt nichts zurück, sein Anteil geht an die Zahlungsart.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationReasons'] = [
    'noConsent' => 'ohne Zustimmung zum Beginn vor Fristende — kein Wertersatz',
    'notReleased' => 'nicht freigegeben — nichts erbracht',
    'noPeriod' => 'keine Abo-Periode — bitte selbst bemessen',
];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['result'] = [
    'accepted' => 'Widerruf %s angenommen.',
    'rejected' => 'Widerruf %s abgelehnt, der Kunde ist benachrichtigt.',
    'rejectedNoMail' => 'Widerruf %s abgelehnt. Die Mail an den Kunden ging nicht hinaus — den Grund nennt das Systemlog.',
    'returnRecorded' => 'Rücksendung zu %s gespeichert.',
    'refunded' => 'Erstattung zu %s gebucht, der Kunde hat den Beleg per Mail.',
    'refundedNoMail' => 'Erstattung zu %s gebucht. Die Mail an den Kunden ging nicht hinaus — den Grund nennt das Systemlog.',
    'alreadyDecided' => 'Dieser Widerruf ist schon entschieden.',
    'notAccepted' => 'Das geht erst, wenn der Widerruf angenommen ist.',
    'alreadyRefunded' => 'Diese Erstattung ist schon gebucht.',
    'reasonMissing' => 'Bitte geben Sie eine Begründung an — sie geht an den Kunden.',
    'invalidQuantity' => 'Eine Menge passt nicht: nur Zahlen ab 0, höchstens was bestellt bzw. angenommen ist.',
    'tooMuch' => 'Von %s ist höchstens noch %s nicht widerrufen.',
    'wholeOnly' => '%s ist ein Abo und wird nur ganz angenommen (%s) oder gar nicht.',
    'nothingAccepted' => 'Bitte geben Sie mindestens eine Menge an.',
    'invalidShipping' => 'Der Versandanteil muss zwischen 0 und %s liegen.',
    'invalidCompensation' => 'Der Wertersatz muss zwischen 0 und %s liegen.',
    'invalidVoucherShare' => 'Auf Gutscheine kann zwischen 0 und %s zurückgehen.',
    'voucherRedeemed' => 'Widerrufene Gutscheine sind schon eingelöst: %s. Was damit gekauft wurde, muss zuerst zurück — die genannten Bestellungen widerrufen oder stornieren; deren Erstattung füllt den Gutschein wieder auf. Nichts wurde gebucht.',
    'voucherBlocked' => 'Widerrufene Gutscheine sind von Hand gesperrt: %s. Ob sie zurückgehen, entscheiden Sie — etwa wenn ein Ersatz ausgestellt wurde. Nichts wurde gebucht.',
    'voucherChangedByHand' => 'Bei widerrufenen Gutscheinen wurde der Saldo von Hand geändert: %s. Ob und wie viel zurückgeht, entscheiden Sie. Nichts wurde gebucht.',
    'numberRangeMissing' => 'Es fehlt der Nummernkreis „%s" in der Shop-Konfiguration. Nichts wurde gebucht.',
    'unknown' => 'Diesen Widerruf gibt es nicht (mehr).',
    // WA, A4: "Bestellung zuordnen".
    'assigned' => 'Widerruf %s ist der Bestellung %s zugeordnet und kann jetzt bearbeitet werden.',
    'assignedLate' => 'Widerruf %s ist der Bestellung %s zugeordnet. Er ging nach dem Ende ihres Widerrufsfensters ein — ob er rechtzeitig war, entscheiden Sie.',
    // WA: one message for "does not exist" and "not yours".
    'assignNotFound' => 'Zu dieser Bestellnummer gibt es in diesem Shop keine Bestellung, die Sie bearbeiten dürfen.',
    'assignAlready' => 'Dieser Widerruf ist schon einer Bestellung zugeordnet.',
    // WA: "Ohne Bestellung abweisen".
    'dismissed' => 'Widerruf %s ohne Bestellung abgewiesen. Der Kunde wurde nicht benachrichtigt.',
    'dismissReasonMissing' => 'Bitte geben Sie eine Begründung an — sie bleibt intern.',
];

// WA: a withdrawal without an order, and the late receipt.
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['unassigned'] = 'nicht zugeordnet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['unassignedTitle'] = 'Widerruf %s — keiner Bestellung zugeordnet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['unassignedHelp'] = 'Bestellnummer und E-Mail-Adresse aus dem Widerrufsformular passen zu keiner Bestellung dieses Shops — oder zu einer, die über das Formular nicht widerrufbar ist (etwa ohne Widerrufsrecht oder storniert ohne Versand). Der Widerruf ist trotzdem eingegangen und an die angegebene Adresse bestätigt. Ordnen Sie ihn der Bestellung zu, um die es geht — danach können Sie ihn annehmen oder mit Begründung ablehnen. Die Erklärung selbst ändert sich dabei nicht.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['assignLegend'] = 'Bestellung zuordnen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['assignOrderNumber'] = 'Bestellnummer';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['assignSubmit'] = 'Zuordnen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissLegend'] = 'Ohne Bestellung abweisen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissReason'] = 'Begründung (intern)';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissHelp'] = 'Für Erklärungen, die zu keiner Bestellung gehören — Unsinn, ein doppelter Eintrag. Der Widerruf gilt dann als abgelehnt und verschwindet aus den offenen Aufgaben; die Erklärung bleibt gespeichert. Der Kunde wird nicht benachrichtigt: Die Adresse ist nicht geprüft.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissSubmit'] = 'Abweisen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['dismissedLegend'] = 'Ohne Bestellung abgewiesen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['afterWindowHint'] = 'Nach dem Ende des Widerrufsfensters eingegangen. Das Fenster rechnet ab Versand, die Frist ab Erhalt — ob der Widerruf rechtzeitig war, entscheiden Sie.';

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['sources'] = ['orderPage' => 'Bestellseite', 'form' => 'Widerrufsformular'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scopes'] = ['all' => 'ganze Bestellung', 'items' => 'einzelne Positionen', 'part' => 'Teil (Freitext)'];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['withdrawal_legend'] = 'Erklärung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['confirmation_legend'] = 'Eingangsbestätigung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['state_legend'] = 'Bearbeitung';

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['items'] = ['Positionen', 'Positionen des Widerrufs ID %s'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['show'] = ['Details', 'Details des Widerrufs ID %s anzeigen'];

$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['orderItem'] = ['Position', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['quantity'] = ['Menge laut Erklärung', '0 bei einer Position, die der Betreiber beim Annehmen ergänzt hat.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['acceptedQuantity'] = ['Angenommen', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['returnedQuantity'] = ['Zurückerhalten', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['item_legend'] = 'Position';
$GLOBALS['TL_LANG']['tl_consho_withdrawal_item']['show'] = ['Details', 'Details der Position ID %s anzeigen'];

// Block G (G9).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colRestock'] = 'ins Lager';
