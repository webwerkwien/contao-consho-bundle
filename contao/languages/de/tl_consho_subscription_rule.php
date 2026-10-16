<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['title'] = ['Bezeichnung', 'Nur im Backend, etwa „Monatsabo". Der Kunde liest die Bedingungen, die Consho aus den Werten bildet.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['termLength'] = ['Mindestlaufzeit', 'Zahl, die Einheit daneben. Ein Jahresabo: 1 Jahr.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['termUnit'] = ['Einheit', ''];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['termUnits'] = ['month' => 'Monate', 'year' => 'Jahre'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['billingInterval'] = ['Zahlungsintervall (Monate)', '0: eine Zahlung für die ganze Laufzeit. Sonst alle so viele Monate eine Folgebestellung; das Intervall muss die Laufzeit ohne Rest teilen. Der Betrag je Zahlung steht in den Preisen des Produkts.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['renewal'] = ['Am Ende der Mindestlaufzeit', 'Entscheidet, wie lange der Kunde danach gebunden ist. Beispiel 12 Monate Mindestlaufzeit, ab Monat 13: „endet" — vorbei, der Kunde wird erinnert und kann neu kaufen; „volle Mindestlaufzeit" — wieder 12 Monate fest, kündbar zum Ende jeder Runde; „jederzeit kündbar" — läuft weiter, mit höchstens 28 Tagen Frist kündbar. Vor jeder Verlängerung geht eine Erinnerung an den Kunden.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['renewals'] = [
    'ends' => 'endet — der Kunde wird erinnert und kann neu kaufen',
    'sameTerm' => 'verlängert sich jeweils um die volle Mindestlaufzeit — erneut fest gebunden (für Geschäftskunden: unter „Kundengruppen" beschränken)',
    'indefinite' => 'läuft weiter, jederzeit kündbar — mit höchstens 28 Tagen Frist',
];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['noticeDays'] = ['Kündigungsfrist (Tage)', 'Vor dem Ende der Mindestlaufzeit; bei „jederzeit kündbar" auch danach. Dort höchstens 28 Tage.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['reminderDays'] = ['Erinnerung (Tage vorher)', 'So viele Tage vor dem Ende der Mindestlaufzeit geht die Erinnerung. Muss vor der Kündigungsfrist liegen, damit der Kunde noch kündigen kann.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['graceDays'] = ['Nachfrist (Tage)', 'Bleibt eine Folgebestellung so lange nach dem Zahlungsziel offen, ruht der Zugang bis zum Zahlungseingang.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['pauseMonths'] = ['Pause (Monate je Vertragsjahr)', 'So viele Monate darf der Kunde sein Abo unter „Meine Abos" je Vertragsjahr pausieren, in ganzen Perioden ab der nächsten Periode. Die Laufzeit verschiebt sich um die Pause. 0: keine Pause.'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['groups'] = ['Kundengruppen', 'Leer: alle Kunden mit Kundenkonto. Ein Abo braucht immer ein Kundenkonto.'];

$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['errors'] = [
    'intervalDividesTerm' => 'Das Zahlungsintervall muss die Mindestlaufzeit ohne Rest teilen (bei 12 Monaten: 1, 2, 3, 4, 6 oder 12), oder 0 für eine Zahlung.',
    'noticeTooLong' => 'Bei „läuft weiter, jederzeit kündbar" darf die Kündigungsfrist höchstens 28 Tage betragen.',
    'indefiniteMonthlyOnly' => '„Läuft weiter, jederzeit kündbar" geht nur mit monatlicher Zahlung (Zahlungsintervall 1) — sonst müsste nach jeder Kündigung anteilig erstattet werden. Für ein Jahresabo „verlängert sich jeweils um die volle Mindestlaufzeit" oder „endet" wählen.',
    'reminderBeforeNotice' => 'Die Erinnerung muss früher kommen als die Kündigungsfrist beginnt — sonst kann der Kunde nach der Erinnerung nicht mehr kündigen.',
    'pauseShorterThanPeriod' => 'Pausiert wird in ganzen Perioden: Die Pause muss mindestens ein Zahlungsintervall lang sein.',
];

$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['title_legend'] = 'Bezeichnung';
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['term_legend'] = 'Laufzeit und Zahlung';
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['renewal_legend'] = 'Verlängerung, Kündigung, Verzug';
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['scope_legend'] = 'Gilt für';

$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['new'] = ['Neue Aboregel', 'Aboregel anlegen'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['edit'] = ['Bearbeiten', 'Aboregel ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['copy'] = ['Duplizieren', 'Aboregel ID %s duplizieren'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['delete'] = ['Löschen', 'Aboregel ID %s löschen'];
$GLOBALS['TL_LANG']['tl_consho_subscription_rule']['show'] = ['Details', 'Details der Aboregel ID %s anzeigen'];
