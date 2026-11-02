<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_payment']['receivedAt'] = ['Eingegangen am', ''];
$GLOBALS['TL_LANG']['tl_consho_payment']['amount'] = ['Betrag', 'Eine Erstattung wird als negativer Betrag erfasst — deshalb gibt es keinen eigenen Erstattungs-Datensatztyp.'];
$GLOBALS['TL_LANG']['tl_consho_payment']['method'] = ['Zahlungsart', 'Wie bezahlt wurde, z. B. „Überweisung" oder „PayPal".'];
$GLOBALS['TL_LANG']['tl_consho_payment']['reference'] = ['Referenz', 'Verwendungszweck, Transaktionsnummer des Zahlungsdienstes o. Ä.'];

$GLOBALS['TL_LANG']['tl_consho_payment']['payment_legend'] = 'Zahlung';

$GLOBALS['TL_LANG']['tl_consho_payment']['new'] = ['Neue Zahlung', 'Zahlung erfassen'];
$GLOBALS['TL_LANG']['tl_consho_payment']['edit'] = ['Bearbeiten', 'Zahlung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_payment']['delete'] = ['Löschen', 'Zahlung ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_payment']['show'] = ['Details', 'Details der Zahlung ID %s anzeigen'];

$GLOBALS['TL_LANG']['tl_consho_payment']['locked'] = 'Diese Zahlung hatte bereits Folgen (Rechnung, Status oder Mail) und ist gesperrt. Eine Korrektur erfassen Sie als neue Zahlung mit negativem Betrag.';

// Erstattung über den Zahlungsanbieter (Block Zahlung Mollie, 4.4).
$GLOBALS['TL_LANG']['tl_consho_payment']['refundPayment'] = ['Erstatten', 'Zahlung ID %s über den Zahlungsanbieter zurückzahlen'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundRetry'] = ['Erneut auszahlen', 'Erstattung ID %s noch einmal beim Zahlungsanbieter anfordern'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundManual'] = ['Von Hand erledigt', 'Erstattung ID %s als von Hand erledigt vermerken'];
$GLOBALS['TL_LANG']['tl_consho_payment']['refundBack'] = 'Zurück';
$GLOBALS['TL_LANG']['tl_consho_payment']['methodRefund'] = 'Erstattung';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundLegend'] = 'Erstattung zu Bestellung';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundAmount'] = 'Betrag';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundAmountHelp'] = 'Höchstens %s — was von dieser Zahlung noch nicht zurückgezahlt ist.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReference'] = 'Referenz';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReferenceHelp'] = 'Steht an der Erstattung in der Bestellung und geht als Beschreibung an den Zahlungsanbieter.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundReferenceDefault'] = 'Erstattung %s';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundInvalidAmount'] = 'Bitte einen Betrag über 0 und höchstens %s eingeben.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundNotPossible'] = 'Diese Zahlung lässt sich nicht über den Zahlungsanbieter erstatten: Sie ist schon ganz zurückgezahlt, oder ihre Zahlungsart kann nicht erstatten. Eine Rückzahlung von Hand erfassen Sie als neue Zahlung mit negativem Betrag.';
$GLOBALS['TL_LANG']['tl_consho_payment']['refundConfirm'] = '%s werden über „%s" an den Kunden zurückgezahlt. Das lässt sich nicht rückgängig machen. Der Kunde bekommt von Consho keine Mail, und es entsteht kein Beleg — eine Rechnungskorrektur ist eigens zu erstellen.';
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
$GLOBALS['TL_LANG']['tl_consho_payment']['payoutStates'] = [
    'requested' => 'in Bearbeitung',
    'sending' => 'ohne Antwort',
    'done' => 'ausgezahlt',
    'failed' => 'abgelehnt',
    'manual' => 'von Hand',
];
