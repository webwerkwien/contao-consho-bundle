<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['number'] = ['Widerrufsnummer', 'Aus der Bestellnummer abgeleitet.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['receivedAt'] = ['Eingegangen am', 'Zeitpunkt des Eingangs nach der Uhr des Servers — so steht er in der Eingangsbestätigung.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['source'] = ['Weg', 'Über die Bestellseite (Link aus der Mail), über das Widerrufsformular oder von Hand erfasst (E-Mail, Brief, Telefon).'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['name'] = ['Name', 'Wie vom Kunden angegeben oder bestätigt.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['orderReference'] = ['Angegebene Bestellnummer', 'So, wie der Kunde sie im Widerrufsformular eingetippt hat — auch wenn sie zu keiner Bestellung passte.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['afterWindow'] = ['Nach Fensterende eingegangen', 'Eingegangen nach „letzter Versand + Frist + Puffer". Das Fenster rechnet ab Versand, die Frist ab Erhalt — ob der Widerruf rechtzeitig war, entscheiden Sie.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['email'] = ['E-Mail', 'Die Adresse für die Eingangsbestätigung.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scope'] = ['Umfang', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['part'] = ['Widerrufener Teil', 'In den Worten des Kunden.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['message'] = ['Nachricht', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['confirmationSentAt'] = ['Bestätigung versandt am', 'Leer: Die Eingangsbestätigung ging nicht hinaus — den Grund nennt das Systemlog — oder sie war beim Erfassen von Hand nicht gewünscht. Der Widerruf gilt trotzdem.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['state'] = ['Stand', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['states'] = ['received' => 'eingegangen', 'accepted' => 'angenommen', 'rejected' => 'abgelehnt'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decidedAt'] = ['Entschieden am', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['reason'] = ['Begründung der Ablehnung', 'Bei einer Ablehnung zur Bestellung ging sie wörtlich an den Kunden; beim Abweisen ohne Bestellung blieb sie intern.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnReceivedAt'] = ['Rücksendung erhalten am', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundShipping'] = ['Erstatteter Versandanteil', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['valueCompensation'] = ['Wertersatz', 'Für eine Dienstleistung einbehalten (§ 357a Abs. 2 BGB): vorgeschlagen bei der Annahme, festgelegt mit der Erstattung.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundAmount'] = ['Erstattet', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundedAt'] = ['Erstattet am', ''];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['process'] = ['Bearbeiten', 'Widerruf ID %s entscheiden, Rücksendung und Erstattung buchen'];

// The page "Widerruf entscheiden" (D-3).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['back'] = 'Zurück';
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
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['pageTitle'] = 'Widerruf %s zur Bestellung %s';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['headOrder'] = 'zur Bestellung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['declarationLegend'] = 'Widerruf';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['nextLegend'] = 'Nächster Schritt';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['doneRejected'] = 'Abgelehnt — der Kunde hat die Begründung per Mail erhalten.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['doneRefunded'] = 'Erledigt — die Erstattung ist gebucht, der Kunde hat den Beleg per Mail erhalten.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['datesLegend'] = 'Fristen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['windowClosesAt'] = 'Fensterende';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['windowOpen'] = 'offene Lieferung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDueAt'] = 'Erstattung fällig bis';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['datesHelp'] = 'Hinweis: Das Fensterende ist letzter Versand + Frist + Zustellpuffer – eine Schätzung, denn die Frist beginnt mit dem Erhalt der Ware. Ein Widerruf danach wird trotzdem entgegengenommen und als „nach Fensterende eingegangen“ markiert.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptLegend'] = 'Annehmen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colItem'] = 'Position';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colOrdered'] = 'bestellt';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colDeclared'] = 'laut Erklärung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colLeft'] = 'noch nicht widerrufen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colAccept'] = 'annehmen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colAccepted'] = 'angenommen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colReturned'] = 'zurückerhalten';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['acceptHelp'] = 'Vorbelegt mit dem, was der Kunde genannt hat; bei „ganze Bestellung" mit allem Offenen, bei einem Teil als Freitext mit nichts — dann den Text oben lesen. Die Erklärung selbst bleibt unverändert.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectLegend'] = 'Ablehnen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['rejectHelp'] = 'Geht wörtlich per Mail an den Kunden.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decideLegend'] = 'Entscheidung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decideTiles'] = ['accept' => 'Ware zurück, Geld zurück', 'reject' => 'mit Begründung an den Kunden'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['chooseDecision'] = 'Entscheidung wählen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['chooseHint'] = 'Entscheidung wählen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['decidedAs'] = 'Entschieden';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['completesWith'] = 'Erledigt ist der Widerruf mit der Erstattung. Die Rücksendung lässt sich davor oder danach erfassen.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colDelivered'] = 'Lieferinformationen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['shippedOn'] = 'versendet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['notDelivered'] = 'noch nicht versendet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['releasedOn'] = 'freigeschaltet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['notReleased'] = 'noch nicht freigeschaltet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['issuedOn'] = 'ausgegeben';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['notIssued'] = 'noch nicht ausgegeben';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['deliveriesLink'] = 'Lieferungen der Bestellung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['deliveredGap'] = ['same' => 'am Tag des Eingangs', 'oneBefore' => '1 Tag vor Eingang', 'before' => '%d Tage vor Eingang', 'oneAfter' => '1 Tag nach Eingang', 'after' => '%d Tage nach Eingang'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnLegend'] = 'Rücksendung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['returnSubmit'] = 'Rücksendung speichern';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundLegend'] = 'Erstattung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['retentionHint'] = 'Die Ware ist noch nicht als zurückerhalten erfasst.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['processWithdrawal'] = ['Widerruf entscheiden', 'Widerruf entscheiden'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundGoods'] = 'Ware';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['shippingHelp'] = 'Noch nicht erstattete Versandkosten: %s. Bei einem Widerruf der ganzen Bestellung vorgeschlagen, sonst 0.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundSubmit'] = 'Erstattung buchen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundDocumentLabel'] = 'Beleg';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['subscriptionLabel'] = 'Abo';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationHelp'] = 'Vorschlag für den Wertersatz einer Dienstleistung (§ 357a Abs. 2 BGB): Zeilenbetrag × genutzte Tage / Tage der bezahlten Periode; genutzt ist jede angefangene 24-Stunden-Spanne von der Freigabe bis zum Eingang des Widerrufs. Sie entscheiden über den Betrag; er wird von der Erstattung abgezogen, die Rechnung dann korrigiert statt storniert.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationLine'] = '%1$s × %2$d / %3$d Tage = %4$s';
// WG-6: the refund goes to vouchers first (WG11).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['throughProvider'] = ['Über die Zahlungsart zurückzahlen (%s)', 'Der Anteil der Zahlungsart geht nach dem Buchen über den Zahlungsanbieter an den Kunden zurück, höchstens %s; vorher bestätigen Sie den Betrag. Was darüber hinausgeht, bleibt von Hand zurückzuzahlen. Ohne Haken wird nur gebucht, wie bisher.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirm'] = '%s werden über „%s" an den Kunden zurückgezahlt. Das lässt sich nicht rückgängig machen. Gebucht werden Erstattung, Beleg und Mail wie gewohnt.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirmManual'] = '%s bleiben von Hand zurückzuzahlen.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirmRecorded'] = '%s sind beim Zahlungsanbieter schon zurückgezahlt und werden mit erfasst.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundForeign'] = ['Schon beim Zahlungsanbieter zurückgezahlt', 'Erstattungen, die direkt beim Zahlungsanbieter gemacht wurden und in Consho noch offen sind. Angekreuzt gehören sie zu dieser Erstattung: Sie werden zuerst gebucht, der Zahlungsanbieter zahlt nur den Rest. Solange eine offen ist, ist „Über die Zahlungsart zurückzahlen“ nicht vorgewählt.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirmSubmit'] = 'Jetzt zurückzahlen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundConfirmCancel'] = 'Abbrechen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['refundVouchers'] = ['Mit Gutschein bezahlt', 'Die Erstattung geht zuerst auf die Gutscheine zurück (dasselbe Zahlungsmittel, § 14 Abs. 1 FAGG, § 357 Abs. 3 BGB), höchstens so viel, wie diese Erstattung auszahlt; der Rest an die Zahlungsart. Ein abgelaufener Gutschein wird dadurch wieder unbefristet gültig; ein gesperrter bekommt nichts zurück, sein Anteil geht an die Zahlungsart.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['compensationReasons'] = [
    'noConsent' => 'ohne Zustimmung zum Beginn vor Fristende — kein Wertersatz',
    'notReleased' => 'nicht freigegeben — nichts erbracht',
    'noPeriod' => 'keine Abo-Periode — bitte selbst bemessen',
];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['result'] = [
    'choiceMissing' => 'Bitte wählen Sie zuerst Annehmen oder Ablehnen.',
    'accepted' => 'Widerruf %s angenommen.',
    'rejected' => 'Widerruf %s abgelehnt, der Kunde ist benachrichtigt.',
    'rejectedNoMail' => 'Widerruf %s abgelehnt. Die Mail an den Kunden ging nicht hinaus — den Grund nennt das Systemlog.',
    'returnRecorded' => 'Rücksendung zu %s gespeichert.',
    'refunded' => 'Erstattung zu %s gebucht, der Kunde hat den Beleg per Mail.',
    'refundedNoMail' => 'Erstattung zu %s gebucht. Die Mail an den Kunden ging nicht hinaus — den Grund nennt das Systemlog.',
    'alreadyDecided' => 'Dieser Widerruf ist schon entschieden.',
    'notAccepted' => 'Das geht erst, wenn der Widerruf angenommen ist.',
    'alreadyRefunded' => 'Diese Erstattung ist schon gebucht.',
    'payoutChanged' => 'Der Betrag über den Zahlungsanbieter hat sich inzwischen geändert. Nichts wurde gebucht — bitte prüfen und erneut buchen.',
    'foreignInvalid' => 'Die angekreuzten Erstattungen beim Zahlungsanbieter passen nicht zu dieser Erstattung: Sie sind zusammen höher als der Anteil der Zahlungsart, oder inzwischen anders erledigt. Nichts wurde gebucht.',
    'reasonMissing' => 'Bitte geben Sie eine Begründung an — sie geht an den Kunden.',
    'invalidQuantity' => 'Eine Menge passt nicht: nur Zahlen ab 0, höchstens was bestellt bzw. angenommen ist.',
    'tooMuch' => 'Von %s ist höchstens noch %s nicht widerrufen.',
    'wholeOnly' => '%s ist ein Abo und wird nur ganz angenommen (%s) oder gar nicht.',
    'nothingAccepted' => 'Bitte geben Sie mindestens eine Menge an.',
    'invalidShipping' => 'Der Versandanteil muss zwischen 0 und %s liegen.',
    'invalidCompensation' => 'Der Wertersatz muss zwischen 0 und %s liegen.',
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
    // "Widerruf erfassen".
    'captured' => 'Widerruf %s erfasst. Eine Eingangsbestätigung wurde nicht versandt.',
    'capturedConfirmed' => 'Widerruf %s erfasst, die Eingangsbestätigung ging an %s.',
    'capturedNotConfirmed' => 'Widerruf %s erfasst. Die Eingangsbestätigung ging nicht hinaus — den Grund nennt das Systemlog.',
    'captureChanged' => 'Nichts erfasst: Seit der Prüfung ist zu Bestellung %s ein Widerruf hinzugekommen. Bitte sehen Sie in der Liste nach, ob er schon erfasst ist.',
    'captureAnonymized' => 'Zu Bestellung %s lässt sich kein Widerruf erfassen: Die Kundendaten sind nach Ablauf der Aufbewahrungsfrist anonymisiert.',
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

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['sources'] = ['orderPage' => 'Bestellseite', 'form' => 'Widerrufsformular', 'email' => 'E-Mail', 'letter' => 'Brief', 'oral' => 'Telefon/persönlich', 'other' => 'Sonstiges'];

// "Widerruf erfassen": a withdrawal that came in outside the shop.
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['capture'] = ['Widerruf erfassen', 'Einen Widerruf erfassen, der per E-Mail, Brief oder Telefon eingegangen ist'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureTitle'] = 'Widerruf erfassen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureHelp'] = 'Für einen Widerruf, der nicht über den Shop kam, sondern per E-Mail, Brief, Telefon oder persönlich. Er wird wie ein Widerruf aus dem Shop gespeichert und danach auf „Widerruf entscheiden“ angenommen oder abgelehnt. Gespeichert lässt er sich nicht mehr ändern.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureReceivedAtHelp'] = 'Wann die Erklärung eingegangen ist, nicht wann Sie sie erfassen — bei einem Brief der Tag des Zugangs.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureItems'] = 'Positionen (bei „einzelne Positionen“)';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureColQuantity'] = 'widerrufen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureWhole'] = 'Abo: nur ganz (%s)';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureNoGoods'] = 'Keine Position ist mehr offen für einen Widerruf. Erfassen können Sie die Erklärung trotzdem — als ganze Bestellung oder als Teil in Worten.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['capturePartHelp'] = 'Bei „Teil (Freitext)“: welcher Teil, in den Worten des Kunden.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureMessage'] = ['Erklärung im Wortlaut', 'Was der Kunde geschrieben oder gesagt hat — etwa den Text der E-Mail. Bei einem Anruf: wer, wann, was.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureConfirm'] = ['Eingangsbestätigung an %s senden', 'Dieselbe Mail wie bei einem Widerruf aus dem Shop, mit dem eingegebenen Eingangszeitpunkt. Ohne Haken wird nichts versandt.'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureConfirmTo'] = 'wird an %s gesendet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureConfirmNone'] = 'wird nicht gesendet';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureReview'] = 'Weiter zur Prüfung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureReviewLegend'] = 'Prüfen und erfassen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureReviewHelp'] = 'Bitte prüfen Sie die Angaben. Nach dem Erfassen lässt sich der Widerruf nicht mehr ändern — ein Fehler wird mit einer Ablehnung samt Begründung und einer neuen Erfassung berichtigt.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureRecord'] = 'Widerruf erfassen';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureEdit'] = 'Ändern';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureClosedHint'] = 'Über den Shop wäre diese Bestellung nicht widerrufbar — etwa ohne Widerrufsfrist, storniert ohne Versand oder mit freigegebenen digitalen Inhalten. Der Widerruf wird trotzdem erfasst; ob er gilt, entscheiden Sie.';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['captureErrors'] = [
    'receivedAt' => 'Bitte geben Sie Datum und Uhrzeit des Eingangs an.',
    'future' => 'Der Eingang kann nicht in der Zukunft liegen.',
    'beforeOrder' => 'Der Eingang kann nicht vor der Bestellung liegen.',
    'source' => 'Bitte wählen Sie, wie der Widerruf eingegangen ist.',
    'name' => 'Bitte geben Sie den Namen an.',
    'part' => 'Bitte beschreiben Sie den widerrufenen Teil.',
    'items' => 'Bitte geben Sie mindestens eine Menge an — höchstens, was noch nicht widerrufen ist; ein Abo nur ganz.',
    'whole' => 'Ein Abo lässt sich nur ganz widerrufen: %s. Bitte die volle offene Menge eintragen oder das Feld leer lassen.',
];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['scopes'] = ['all' => 'ganze Bestellung', 'items' => 'einzelne Positionen', 'part' => 'Teil (Freitext)'];

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['withdrawal_legend'] = 'Erklärung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['confirmation_legend'] = 'Eingangsbestätigung';
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['state_legend'] = 'Bearbeitung';

$GLOBALS['TL_LANG']['tl_consho_withdrawal']['items'] = ['Positionen', 'Positionen des Widerrufs ID %s'];
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['show'] = ['Details', 'Details des Widerrufs ID %s anzeigen'];


// Block G (G9).
$GLOBALS['TL_LANG']['tl_consho_withdrawal']['colRestock'] = 'ins Lager';
