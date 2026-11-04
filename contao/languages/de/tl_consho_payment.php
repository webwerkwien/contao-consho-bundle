<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_payment']['receivedAt'] = ['Eingegangen am', ''];
$GLOBALS['TL_LANG']['tl_consho_payment']['amount'] = ['Betrag', 'Der eingegangene Betrag. Geld zurück läuft über „Erstatten“ an der Zahlung, einen Widerruf oder eine Stornierung.'];
$GLOBALS['TL_LANG']['tl_consho_payment']['amountNotPositive'] = 'Hier wird nur eingegangenes Geld erfasst, bitte einen Betrag über 0 eingeben. Geld zurück läuft über „Erstatten“ an der Zahlung, einen Widerruf oder eine Stornierung. Eine gesperrte Zahlung, die zu hoch erfasst wurde, berichtigen Sie über „Korrigieren“.';
$GLOBALS['TL_LANG']['tl_consho_payment']['method'] = ['Zahlungsart', 'Wie bezahlt wurde, z. B. „Überweisung" oder „PayPal".'];
$GLOBALS['TL_LANG']['tl_consho_payment']['reference'] = ['Referenz', 'Verwendungszweck, Transaktionsnummer des Zahlungsdienstes o. Ä.'];

$GLOBALS['TL_LANG']['tl_consho_payment']['payment_legend'] = 'Zahlung';

$GLOBALS['TL_LANG']['tl_consho_payment']['new'] = ['Neue Zahlung', 'Zahlung erfassen'];
$GLOBALS['TL_LANG']['tl_consho_payment']['edit'] = ['Bearbeiten', 'Zahlung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_payment']['delete'] = ['Löschen', 'Zahlung ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_payment']['show'] = ['Details', 'Details der Zahlung ID %s anzeigen'];

$GLOBALS['TL_LANG']['tl_consho_payment']['locked'] = 'Diese Zahlung hatte bereits Folgen (Rechnung, Status oder Mail) und ist gesperrt. War sie zu hoch erfasst, berichtigen Sie sie über „Korrigieren“ an der Zahlung.';

// Erstattung über den Zahlungsanbieter (Block Zahlung Mollie, 4.4).
$GLOBALS['TL_LANG']['tl_consho_payment']['refundPayment'] = ['Erstatten', 'Zahlung ID %s ganz oder teilweise zurückzahlen'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundRetry'] = ['Erneut auszahlen', 'Erstattung ID %s noch einmal beim Zahlungsanbieter anfordern'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundManual'] = ['Von Hand erledigt', 'Erstattung ID %s als von Hand erledigt vermerken'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundBack'] = 'Zurück';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodRefund'] = 'Erstattung';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodChargeback'] = 'Rückbuchung';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodChargebackReversal'] = 'Rückbuchung aufgehoben';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodCorrection'] = 'Korrektur';

// Korrektur einer gesperrten Zahlung (Geldflüsse E8).
$GLOBALS['TL_LANG']['tl_consho_payment']['correctPayment'] = ['Korrigieren', 'Gesperrte Zahlung ID %s berichtigen, ohne dass Geld fließt'];
$GLOBALS['TL_LANG']['tl_consho_payment']['correctLegend'] = 'Korrektur zu Bestellung';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctAmountHelp'] = 'Höchstens %s. Um so viel war die Zahlung zu hoch erfasst.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctReason'] = 'Grund';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctReasonHelp'] = 'Pflichtfeld. Steht an der Korrektur in der Bestellung, der Kunde sieht ihn nicht.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctInvalid'] = 'Bitte einen Betrag über 0 und höchstens %s sowie einen Grund eingeben.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctNotPossible'] = 'Diese Zahlung lässt sich nicht korrigieren: Sie ist noch nicht gesperrt (dann einfach bearbeiten), schon ganz zurückgezahlt oder korrigiert, oder eine Gutscheinzahlung.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctConfirm'] = 'Die Zahlung über %s zählt danach nur noch %s. Grund: %s. Es fließt kein Geld, der Kunde bekommt keine Mail, und es entsteht kein Beleg. Die Korrektur ist danach selbst gesperrt.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctStateChanged'] = 'Die Zahlung hat sich inzwischen geändert. Bitte die Liste neu ansehen.';
$GLOBALS['TL_LANG']['tl_consho_payment']['correctDone'] = 'Die Zahlung ist korrigiert.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_correct'] = 'Weiter';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_correctConfirm'] = 'Jetzt korrigieren';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundLegend'] = 'Erstattung zu Bestellung';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundAmount'] = 'Betrag';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundAmountHelp'] = 'Höchstens %s — was von dieser Zahlung noch nicht zurückgezahlt ist.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReference'] = 'Referenz';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReferenceHelp'] = 'Steht an der Erstattung in der Bestellung. An den Zahlungsanbieter geht sie nicht.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReferenceDefault'] = 'Erstattung %s';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundInvalidAmount'] = 'Bitte einen Betrag über 0 und höchstens %s eingeben.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundNotPossible'] = 'Von dieser Zahlung ist nichts mehr zurückzuzahlen: Sie ist schon ganz zurückgezahlt oder korrigiert. Eine Gutscheinzahlung geht über einen Widerruf oder eine Stornierung auf den Gutschein zurück.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWaysInvalid'] = 'So lässt sich der Betrag nicht aufteilen: Die gewählten Erstattungen beim Zahlungsanbieter sind zusammen höher als der Betrag, oder von dieser Zahlung ist nicht mehr so viel übrig (höchstens %s).';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWays'] = 'Wege';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayProviderOption'] = 'Über „%s“ auszahlen';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayForeignOption'] = '%s, schon beim Zahlungsanbieter zurückgezahlt (%s)';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWaysHelp'] = 'Was keiner dieser Wege abdeckt, zahlen Sie von Hand zurück. Eine Erstattung, die schon beim Zahlungsanbieter gemacht wurde, nicht noch einmal auszahlen lassen.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWaysHandOnly'] = 'Diese Zahlungsart kann nicht über den Zahlungsanbieter erstatten. Consho bucht die Erstattung, zurückzahlen müssen Sie von Hand.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayForeign'] = '%s sind beim Zahlungsanbieter schon zurückgezahlt (%s) und werden hier erfasst.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayProvider'] = '%s werden über „%s" an den Kunden zurückgezahlt.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundWayHand'] = '%s zahlen Sie von Hand zurück.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundConfirm'] = 'Das lässt sich nicht rückgängig machen. Der Kunde bekommt von Consho keine Mail, und es entsteht kein Beleg — eine Rechnungskorrektur ist eigens zu erstellen.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundRetryConfirm'] = 'Die Erstattung über %s wird noch einmal bei „%s" angefordert. Den Grund der Ablehnung nennt das Fehlerprotokoll — erst beheben, dann erneut auszahlen.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundManualConfirm'] = 'Die Erstattung über %s („%s") wird als erledigt vermerkt und danach nie mehr beim Zahlungsanbieter angefordert. Vorher im Konto beim Anbieter nachsehen, ob das Geld schon zurückging — sonst von Hand zurückzahlen.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundPayoutReference'] = 'Kennung beim Anbieter: %s.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_refund'] = 'Weiter';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_confirm'] = 'Jetzt zurückzahlen';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_retry'] = 'Erneut auszahlen';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundSubmit_manual'] = 'Als erledigt vermerken';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundResult'] = [
    'done' => 'Der Zahlungsanbieter hat die Erstattung ausgeführt.',
    'requested' => 'Der Zahlungsanbieter bearbeitet die Erstattung noch; Consho fragt stündlich nach.',
    'failed' => 'Der Zahlungsanbieter hat die Erstattung abgelehnt. Sie ist gebucht, aber nicht ausgezahlt; den Grund nennt das Fehlerprotokoll.',
    'manual' => 'Was kein Zahlungsanbieter zurückzahlen kann, ist gebucht und von Hand zurückzuzahlen.',
    'sending' => 'Vom Zahlungsanbieter kam keine Antwort. Im Konto beim Anbieter nachsehen, ob das Geld zurückging.',
];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundStateChanged'] = 'Die Erstattung hat sich inzwischen geändert. Bitte die Liste neu ansehen.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundManualDone'] = 'Die Erstattung ist als erledigt vermerkt.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderChoice'] = 'Erstattung beim Zahlungsanbieter';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderNone'] = 'Beim Zahlungsanbieter liegt keine Erstattung über diesen Betrag, die Consho noch nicht kennt. Der Vermerk geht ohne Zuordnung.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderWithout'] = 'Keine davon — anders zurückgezahlt';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderStates'] = ['processing' => 'in Bearbeitung', 'refunded' => 'erstattet'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundProviderUnavailable'] = 'Die Erstattungen beim Zahlungsanbieter stehen hier nicht zur Auswahl: Das Modul bietet keine Liste an, oder der Anbieter hat nicht geantwortet. Der Vermerk geht ohne Zuordnung.';
$GLOBALS['TL_LANG']['tl_consho_payment']['payoutStates'] = [
    'requested' => 'in Bearbeitung',
    'sending' => 'ohne Antwort',
    'done' => 'ausgezahlt',
    'failed' => 'abgelehnt',
    'manual' => 'von Hand',
];
