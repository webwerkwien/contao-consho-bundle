<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_order']['orderNumber'] = ['Bestellnummer', 'Wird beim Anlegen vergeben. Lücken sind hier erlaubt.'];
$GLOBALS['TL_LANG']['tl_consho_order']['orderStatus'] = ['Status', 'Der einzige gepflegte Zustand. Zahlung und Lieferung ergeben sich aus den erfassten Datensätzen.'];
$GLOBALS['TL_LANG']['tl_consho_order']['shop'] = ['Shop', 'Zu welcher Shop-Konfiguration diese Bestellung gehört.'];
$GLOBALS['TL_LANG']['tl_consho_order']['createdAt'] = ['Bestellt am', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['language'] = ['Sprache', 'Die Sprache, in der bestellt wurde. Stammt vom Startpunkt und bleibt unveraendert — auch wenn die Mail Jahre spaeter erneut versendet wird.'];
$GLOBALS['TL_LANG']['tl_consho_order']['member'] = ['Mitglied', 'Leer bei einer Gastbestellung — die Kundendaten stehen trotzdem vollständig hier.'];

$GLOBALS['TL_LANG']['tl_consho_order']['salutation'] = ['Anrede', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['firstname'] = ['Vorname', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['lastname'] = ['Nachname', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['email'] = ['E-Mail', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['phone'] = ['Telefon', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['company'] = ['Firma', ''];

$GLOBALS['TL_LANG']['tl_consho_order']['billingStreet'] = ['Straße und Hausnummer', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['billingPostal'] = ['PLZ', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['billingCity'] = ['Ort', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['billingState'] = ['Bundesland', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['billingCountry'] = ['Land', ''];

$GLOBALS['TL_LANG']['tl_consho_order']['shippingCompany'] = ['Firma', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingFirstname'] = ['Vorname', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingLastname'] = ['Nachname', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingStreet'] = ['Straße und Hausnummer', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingPostal'] = ['PLZ', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingCity'] = ['Ort', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingState'] = ['Bundesland', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingPhone'] = ['Telefon für den Paketdienst', 'Nur gespeichert, wenn die gewählte Versandart sie braucht.'];
$GLOBALS['TL_LANG']['tl_consho_order']['shippingCountry'] = ['Land', ''];

$GLOBALS['TL_LANG']['tl_consho_order']['vatId'] = ['UID-Nummer', 'Wie sie bei dieser Bestellung angegeben wurde.'];
$GLOBALS['TL_LANG']['tl_consho_order']['vatIdStatus'] = ['Prüfergebnis', 'Der Stand zum Zeitpunkt der Bestellung — nicht der heutige.'];
$GLOBALS['TL_LANG']['tl_consho_order']['vatIdRequestIdentifier'] = ['VIES-Nachweiskennung', 'Beleg der Abfrage für eine Betriebsprüfung.'];
$GLOBALS['TL_LANG']['tl_consho_order']['vatIdName'] = ['Name bei VIES', 'Der Name, den VIES zur UID geliefert hat, wo der Mitgliedstaat ihn herausgibt. Mit der Rechnungsadresse vergleichen: VIES bestätigt, dass es die Nummer gibt, nicht, dass sie dem Käufer gehört.'];

$GLOBALS['TL_LANG']['tl_consho_order']['currency'] = ['Währung', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['netTotal'] = ['Summe netto', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['taxTotal'] = ['Steuer', ''];
$GLOBALS['TL_LANG']['tl_consho_order']['grandTotal'] = ['Gesamtsumme', ''];

$GLOBALS['TL_LANG']['tl_consho_order']['shippingMethodTitle'] = ['Versandart', 'Wie sie zum Zeitpunkt der Bestellung hieß.'];
$GLOBALS['TL_LANG']['tl_consho_order']['paymentInstructions'] = ['Zahlungshinweis', 'Wie er dem Kunden beim Kauf gezeigt wurde, in der Sprache der Bestellung.'];
$GLOBALS['TL_LANG']['tl_consho_order']['paymentMethodTitle'] = ['Zahlungsart', 'Wie sie zum Zeitpunkt der Bestellung hieß.'];
$GLOBALS['TL_LANG']['tl_consho_order']['termsAcceptedAt'] = ['AGB zugestimmt', 'Wann der Kunde das Häkchen gesetzt hat. Der Wortlaut, dem er zugestimmt hat, steht unter „Rechtstexte".'];
$GLOBALS['TL_LANG']['tl_consho_order']['minimumAge'] = ['Mindestalter', 'Die Grenze, die für diese Bestellung galt. Mitgespeichert, weil eine Grenze sich ändern kann.'];
$GLOBALS['TL_LANG']['tl_consho_order']['dateOfBirth'] = ['Geburtsdatum', 'Nachweis für eine altersbeschränkte Bestellung — an der Bestellung, nicht am Warenkorb.'];

$GLOBALS['TL_LANG']['tl_consho_order']['order_legend'] = 'Bestellung';
$GLOBALS['TL_LANG']['tl_consho_order']['customer_legend'] = 'Kunde';
$GLOBALS['TL_LANG']['tl_consho_order']['billing_legend'] = 'Rechnungsadresse';
$GLOBALS['TL_LANG']['tl_consho_order']['shipping_legend'] = 'Lieferadresse';
$GLOBALS['TL_LANG']['tl_consho_order']['method_legend'] = 'Versand und Zahlung';
$GLOBALS['TL_LANG']['tl_consho_order']['consent_legend'] = 'Zustimmung und Alter';
$GLOBALS['TL_LANG']['tl_consho_order']['tax_legend'] = 'Umsatzsteuer';
$GLOBALS['TL_LANG']['tl_consho_order']['total_legend'] = 'Summen';

$GLOBALS['TL_LANG']['tl_consho_order']['items'] = ['Positionen', 'Positionen der Bestellung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_order']['documents'] = ['Dokumente', 'Dokumente der Bestellung ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['legal'] =['Rechtstexte', 'Rechtstexte der Bestellung ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['withdrawals'] = ['Widerrufe', 'Widerrufe der Bestellung ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['complaints'] = ['Reklamationen', 'Reklamationen der Bestellung ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['complaintsNone'] = 'Noch keine Reklamation — hier eine erfassen';
$GLOBALS['TL_LANG']['tl_consho_order']['payments'] = ['Zahlungen', 'Zahlungen der Bestellung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_order']['deliveries'] = ['Lieferungen', 'Lieferungen der Bestellung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_order']['edit'] = ['Bearbeiten', 'Bestellung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_order']['show'] = ['Details', 'Details der Bestellung ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_consho_order']['resendConfirmation'] = ['Bestätigung erneut senden', 'Die Bestellbestätigung der Bestellung ID %s noch einmal an den Kunden senden'];
$GLOBALS['TL_LANG']['tl_consho_order']['resendSent'] = 'Die Bestätigung der Bestellung %s ging erneut an %s.';
$GLOBALS['TL_LANG']['tl_consho_order']['resendFailed'] = 'Die Bestätigung der Bestellung %s konnte nicht gesendet werden. Den Grund nennt das Systemlog.';

// E8: postpone the payment due date
$GLOBALS['TL_LANG']['tl_consho_order']['deferPayment'] = ['Zahlungsziel verschieben', 'Das Zahlungsziel der Bestellung ID %s auf einen späteren Tag legen'];
$GLOBALS['TL_LANG']['tl_consho_order']['followUps'] = ['Nachträge', 'Rückbuchungen und Erstattungen, die der Zahlungsanbieter zur Bestellung ID %s nachträglich gemeldet hat'];
$GLOBALS['TL_LANG']['tl_consho_order']['moneyFlows'] = ['Geldflüsse', 'Saldo und alle Geldbewegungen der Bestellung ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['followUpPage'] = [
    'back' => 'Zurück',
    'legend' => 'Nachträge',
    'none' => 'Der Zahlungsanbieter hat zu dieser Bestellung nichts nachgemeldet.',
    'kinds' => ['chargeback' => 'Rückbuchung', 'refund' => 'Erstattung beim Zahlungsanbieter'],
    'states' => [
        'standing' => 'zurückgebucht',
        'reversed' => 'Rückbuchung aufgehoben',
        'settled' => 'nachgezahlt',
        'open' => 'noch nicht erfasst',
        'recorded' => 'in Consho erfasst',
        'assigned' => 'einer Erstattungszeile zugeordnet',
    ],
    'reason' => 'Grund',
    // Geldflüsse (3.6).
    'balanceLegend' => 'Saldo',
    'movementsLegend' => 'Bewegungen',
    'noMovements' => 'Zu dieser Bestellung ist noch kein Geld geflossen.',
    'balance' => [
        'claim' => 'Forderung',
        'claimCancelled' => 'Forderung (storniert)',
        'received' => 'Bezahlt',
        'refunded' => 'Zurückgezahlt',
        'chargedBack' => 'Zurückgebucht',
        'outside' => 'Beim Zahlungsanbieter erstattet, noch nicht erfasst',
        'outstanding' => 'Offen',
        'tooMuch' => 'Zu viel bezahlt',
        'settled' => 'Ausgeglichen',
    ],
    'movements' => [
        'payment' => 'Zahlung',
        'refund' => 'Rückzahlung',
        'correction' => 'Korrektur',
        'chargeback' => 'Rückbuchung',
        'chargebackReversal' => 'Rückbuchung aufgehoben',
        'foreignRefund' => 'Erstattung beim Zahlungsanbieter',
    ],
    'providerId' => 'Kennung beim Zahlungsanbieter: %s',
    'of' => 'zur Zahlung über %s vom %s',
    'locked' => 'gesperrt',
    'withdrawalLink' => 'Widerruf %s',
    'complaintLink' => 'Reklamation %s',
    'documentLink' => 'Rechnungskorrektur',
    'newPayment' => 'Zahlung erfassen',
    'assignPayment' => 'Zahlung zuordnen',
    'assignPaymentLabel' => 'Zahlung',
    'assignOption' => '%s vom %s, offen %s',
    'assignPaymentHelp' => 'Diese Rückzahlung nennt keine Zahlung, deshalb zeigt „Erstatten" an den Zahlungen zu viel als offen. Die Zahlung wählen, von der das Geld zurückging. Es fließt dabei kein Geld.',
    'noPaymentToAssign' => 'Diese Rückzahlung nennt keine Zahlung, und keine Zahlung der Bestellung hat noch so viel offen. Den Betrag der Rückzahlung prüfen.',
    'record' => 'In Consho erfassen',
    'assign' => 'Einer Zeile zuordnen',
    'row' => 'Erstattungszeile',
    'noDocuments' => 'Erfassen bucht nur das Geld, etwa für eine Überzahlung. Eine Gutschrift oder Steuerkorrektur entsteht dabei nicht — gehört die Erstattung zu einer Stornierung, die Bestellung zuerst stornieren.',
    'recordCancelled' => 'Die Bestellung ist storniert, die Storno-Rechnung ist ausgestellt. Erfassen bucht die Erstattung als Rückzahlung dieser Stornierung.',
    'toWithdrawal' => 'Zum Widerruf %s',
    'toWithdrawalHelp' => 'Zu dieser Bestellung wartet ein Widerruf auf seine Erstattung. Gehört diese Erstattung dazu, dort zurückzahlen: Sie ist dort schon angekreuzt, und der Widerruf erstellt die Rechnungskorrektur. Erfassen ist bis dahin nicht möglich, sonst bliebe dem Widerruf nichts mehr zu erstatten.',
    'toComplaint' => 'Zur Reklamation %s',
    'toComplaintHelp' => 'Zu dieser Bestellung wartet eine Reklamation (Rücktritt oder Preisminderung) auf ihre Erstattung. Gehört diese Erstattung dazu, dort zurückzahlen: Sie ist dort schon angekreuzt, und die Reklamation erstellt die Rechnungskorrektur. Erfassen ist bis dahin nicht möglich, sonst bliebe der Reklamation nichts mehr zu erstatten.',
    'noRows' => 'Keine offene Erstattungszeile über diesen Betrag, der sie zugeordnet werden könnte.',
    'claim' => [
        'legend' => 'Zahlung einfordern',
        'help' => 'Der Kunde bekommt eine Zahlungsaufforderung über den offenen Betrag mit diesem Tag und dem Link zum Bezahlen. Der Tag wird zum Zahlungsziel der Bestellung: Erst danach greifen der Hinweis auf überfällige Zahlungen, das Ruhen von Abos und digitalen Inhalten.',
        'day' => 'Zahlbar bis',
        'submit' => 'Zahlung einfordern',
    ],
    'done' => [
        'record' => 'Die Erstattung ist in Consho erfasst.',
        'assign' => 'Die Erstattung ist der Zeile zugeordnet.',
        'assignPayment' => 'Die Rückzahlung ist der Zahlung zugeordnet.',
        'claim' => 'Die Zahlungsaufforderung ist versendet.',
    ],
    'refused' => [
        'notClaimable' => 'Zu dieser Bestellung ist nichts einzufordern: Die Rückbuchung ist aufgehoben oder nachgezahlt.',
        'invalidDate' => 'Bitte ein gültiges Datum angeben.',
        'notFuture' => 'Bitte einen Tag in der Zukunft wählen.',
        'mailNotSent' => 'Das Zahlungsziel ist gesetzt, die Zahlungsaufforderung konnte aber nicht versendet werden. Den Grund nennt das Systemlog.',
        'stateChanged' => 'Die Erstattung wurde inzwischen erfasst oder zugeordnet.',
        'withdrawalOpen' => 'Zu dieser Bestellung wartet ein Widerruf auf seine Erstattung. Die Erstattung bitte dort zurückzahlen.',
        'complaintOpen' => 'Zu dieser Bestellung wartet eine Reklamation auf ihre Erstattung. Die Erstattung bitte dort zurückzahlen.',
        'exceeds' => 'Die Erstattung ist höher als das, was von der Zahlung noch nicht zurückgezahlt ist. Bitte im Konto beim Zahlungsanbieter nachsehen.',
        'rowNotAssignable' => 'Diese Zeile kann der Erstattung nicht zugeordnet werden: anderer Betrag, oder sie ist schon erledigt.',
        'paymentNotAssignable' => 'Die Rückzahlung kann dieser Zahlung nicht zugeordnet werden: Sie hat nicht mehr so viel offen, oder die Rückzahlung ist schon zugeordnet.',
    ],
];
$GLOBALS['TL_LANG']['tl_consho_order']['deferBack'] = 'Zurück';
$GLOBALS['TL_LANG']['tl_consho_order']['deferLegend'] = 'Zahlungsziel';
$GLOBALS['TL_LANG']['tl_consho_order']['deferDate'] = 'Neues Zahlungsziel';
$GLOBALS['TL_LANG']['tl_consho_order']['deferHelp'] = 'Bisher bis %s. Nur ein späterer Tag; die Rechnung behält ihr Datum, der Kunde bekommt keine Mail. Ruht ein Abo nur wegen dieser Bestellung, läuft es danach sofort wieder.';
$GLOBALS['TL_LANG']['tl_consho_order']['deferSubmit'] = 'Verschieben';
$GLOBALS['TL_LANG']['tl_consho_order']['deferDone'] = 'Das Zahlungsziel ist verschoben.';
$GLOBALS['TL_LANG']['tl_consho_order']['deferNotPossible'] = 'Diese Bestellung hat kein offenes Zahlungsziel: Sie ist bezahlt, storniert oder ohne Zahlungsziel.';
$GLOBALS['TL_LANG']['tl_consho_order']['deferRefused'] = [
    'notDeferrable' => 'Diese Bestellung hat kein offenes Zahlungsziel.',
    'invalidDate' => 'Bitte ein gültiges Datum wählen.',
    'notLater' => 'Das neue Zahlungsziel muss nach dem bisherigen liegen.',
];

// D-1: a status must not claim what payments and deliveries do not bear out.
$GLOBALS['TL_LANG']['tl_consho_order']['statusRefused'] = 'Der Status „%s" kann nicht gesetzt werden: %s.';
$GLOBALS['TL_LANG']['tl_consho_order']['digitalAddressUnconfirmed'] = 'Die digitalen Güter dieser Bestellung gingen an %s — eine Adresse, die noch niemand bestätigt hat. Die Mail ist hier der Lieferweg: Meldet sich der Kunde, er habe nichts bekommen, zuerst die Adresse prüfen.';
$GLOBALS['TL_LANG']['tl_consho_order']['statusUnmet'] = [
    'paymentSettled' => 'es sind noch %s offen',
    'refundDue' => '%s sind zu erstatten',
    'deliverySettled' => 'nicht als versandt gemeldet: %s',
    'nothingShipped' => 'es ist bereits Ware versandt — das ist ein Widerruf oder eine Retoure, keine Stornierung',
];
// dev#1: a cancellation cancels the invoice along with it — only where that works in full and numbered.
$GLOBALS['TL_LANG']['tl_consho_order']['cancelInvoiceRefused'] = [
    'corrected' => 'zur Rechnung gibt es schon eine Rechnungskorrektur. Ein Storno höbe die ganze Rechnung auf und schriebe den korrigierten Teil ein zweites Mal gut — die übrigen Positionen über „Widerrufe“ abwickeln',
    'numberRangeMissing' => 'der Shop hat keinen Nummernkreis für Stornonummern, die Rechnung bliebe stehen. Unter „Shop-Konfigurationen → Nummernkreise“ anlegen oder auf der Seite „Einrichtung“ „Fehlende Nummernkreise anlegen“',
];
$GLOBALS['TL_LANG']['tl_consho_order']['resendAnonymized'] = 'Die Bestätigung der Bestellung %s wurde nicht gesendet: Die Kundendaten sind nach Ablauf der Aufbewahrungsfrist anonymisiert.';
$GLOBALS['TL_LANG']['tl_consho_order']['resendNoOrderPage'] = 'Die Bestätigung der Bestellung %s wurde nicht gesendet: Der Startpunkt des Shops hat keine Bestellseite, der Link in der Mail zeigte ins Leere.';

/*
 * The labels of the three signals in the list row. They go into the
 * title attribute — colour alone does not carry the meaning, and red-green
 * of all things is the most common colour vision deficiency.
 */
$GLOBALS['TL_LANG']['tl_consho_order']['signalsColumn'] = 'Status';
$GLOBALS['TL_LANG']['tl_consho_order']['kindColumn'] = 'Art';

$GLOBALS['TL_LANG']['tl_consho_order']['customerColumn'] = 'Kunde';

$GLOBALS['TL_LANG']['tl_consho_order']['signals'] = [
    'status' => [
        'none' => 'Kein Status gesetzt',
    ],
    // What the order is, by the kinds of its product lines.
    'kind' => [
        'physical' => 'Ware',
        'digital' => 'Digitales',
        'service' => 'Dienstleistung',
        'voucher' => 'Gutschein',
        'mixed' => 'Gemischt: %s',    ],
    'payment' => [
        // Keyed by PaymentState, not by colour: "erstattet" is grey like "offen".
        'open' => 'Zahlung: offen',
        'partial' => 'Zahlung: teilweise eingegangen',
        'paid' => 'Zahlung: erfolgt',
        'overpaid' => 'Zahlung: überzahlt — Betrag muss zurück',
        'refunded' => 'Zahlung: erstattet',
        'partlyRefunded' => 'Zahlung: erfolgt, teilweise erstattet',
        'nothingDue' => 'Zahlung: nichts fällig',
    ],
    'delivery' => [
        'open' => 'Lieferung: offen',
        'progress' => 'Lieferung: teilweise versendet',
        'attention' => 'Lieferung: prüfen',
        'done' => 'Lieferung: vollständig',
        // Keyed by the kind of what is still open (OrderLabelListener).
        // Released, a service or digital line counts as delivered (DigitalRelease reports it).
        'service' => 'Leistung: noch nicht freigeschaltet',
        'digital' => 'Digitales: noch nicht freigeschaltet',
        'voucher' => 'Gutschein: noch nicht ausgegeben',
        'nothingLeft' => 'Lieferung: nichts mehr offen',
        // In place of "service" and "digital" where the release is still to come.
        'serviceAwaitingPayment' => 'Leistung: wartet auf die Zahlung',
        'serviceReleasedOn' => 'Leistung: wird am %s freigeschaltet',
        'digitalAwaitingPayment' => 'Digitales: wartet auf die Zahlung',
        'digitalReleasedOn' => 'Digitales: wird am %s freigeschaltet',
    ],
];
$GLOBALS['TL_LANG']['tl_consho_order']['remainderDeliveryDays'] = ['Restlieferung nach Teillieferung (Werktage)', 'Leer: der Grundwert des Shops. Gilt für die Rechnung, die mit der ersten Sendung entsteht.'];
$GLOBALS['TL_LANG']['tl_consho_order']['digitalConsentAt'] = ['Vorzeitigem Beginn zugestimmt', 'Wann der Kunde zugestimmt hat, dass digitale Inhalte vor Ablauf der Widerrufsfrist bereitgestellt werden, und bestätigt hat, dass er dadurch sein Widerrufsrecht verliert. Leer: nicht zugestimmt oder nicht gefragt — digitale Positionen werden dann erst nach Ablauf der Frist freigegeben.'];
$GLOBALS['TL_LANG']['tl_consho_order']['servicePeriodFrom'] = ['Leistungszeitraum von', 'Bei einer Folgebestellung: der Beginn der abgerechneten Periode.'];
$GLOBALS['TL_LANG']['tl_consho_order']['servicePeriodUntil'] = ['Leistungszeitraum bis', 'Bei einer Folgebestellung: das Ende der abgerechneten Periode.'];
$GLOBALS['TL_LANG']['tl_consho_order']['subscription'] = ['Folgebestellung zum Abo', 'Leer bei einem Kauf. Gesetzt, wenn die Bestellung eine Periode eines Abos abrechnet.'];
$GLOBALS['TL_LANG']['tl_consho_order']['businessCustomer'] = ['Geschäftskunde', 'Beim Kauf als Unternehmer gezählt: Netto-Kundengruppe oder geprüfte UID. Einem Geschäftskunden wird die Zustimmung nicht abverlangt.'];
$GLOBALS['TL_LANG']['tl_consho_order']['entitlements'] = ['Berechtigungen', 'Berechtigungen aus den digitalen Positionen der Bestellung ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order']['captureWithdrawal'] = ['Widerruf erfassen', 'Widerruf erfassen'];
$GLOBALS['TL_LANG']['tl_consho_order']['addressesFrozen'] = 'Ein Teil der Kunden- und Adressangaben ist schreibgeschützt: Die Lieferadresse steht auf einer erfassten Lieferung oder einem Lieferschein, Kunde und Rechnungsadresse auf einer Rechnung. Eine Rechnung wird mit einer Rechnungskorrektur berichtigt, nicht hier.';
