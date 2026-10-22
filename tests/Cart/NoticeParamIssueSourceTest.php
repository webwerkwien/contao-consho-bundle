<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Cart\NoticeParamIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Payment\ReturnParametersInterface;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;

/**
 * Der Befund zu QUERY_PARAMS_ALLOW_LIST (C-3c).
 *
 * Was diese Tests NICHT zeigen: dass Contao die Variable tatsächlich so liest.
 * Das steht in ContaoCache des manager-bundle und ist hier nachgelesen, nicht
 * nachgestellt — ein Test dafür prüfte unsere eigene Abschrift (Fall 23g).
 * Geprüft wird die Entscheidung: Wann melden wir, und wann nicht.
 */
class NoticeParamIssueSourceTest extends TestCase
{
    public function testEineNichtGesetzteVariableIstNullUndKeinLeererText(): void
    {
        // ⚠️ Der Fall, der auf c5 das ganze Backend lahmgelegt hat:
        // `%env(default::NAME)%` löst für eine nicht gesetzte Variable zu
        // NULL auf, nicht zu "". Mit `string` als Typ starb der Dienst mit
        // einem TypeError — und weil er für die Systemmeldungen im Kopfmenü
        // gebaut wird, starb damit JEDE Backend-Seite, nicht nur die
        // Startseite.
        //
        // Kein Test hat das gesehen: Alle bauen die Klasse direkt und nie den
        // Container. Deshalb steht der Fall jetzt hier.
        self::assertSame([], (new NoticeParamIssueSource(null))->issues());
    }

    public function testOhneAllowListGibtEsKeinenBefund(): void
    {
        // Der Normalfall und der sichere: Ohne Liste entfernt Contao nur
        // seine Deny-List, und darin stehen ausschließlich Tracking-Parameter.
        // array_filter wirft leere Einträge weg, „,," ist also so gut wie
        // nichts.
        self::assertSame([], (new NoticeParamIssueSource(''))->issues());
        self::assertSame([], (new NoticeParamIssueSource(',,'))->issues());
    }

    public function testEineListeAusLeerzeichenIstKeineLeereListe(): void
    {
        // „   " überlebt array_filter (nicht leer, nur unsichtbar) und ist
        // damit eine gesetzte Allow-List, deren einziges Muster „^(?:   )$"
        // nichts trifft: Contao entfernt JEDEN Parameter. Das gehört gemeldet.
        // Beim ersten Schreiben stand hier die Erwartung „kein Befund" —
        // dieselbe freundliche Lesart, die schon beim Leerzeichen nach dem
        // Komma danebenlag.
        self::assertCount(1, (new NoticeParamIssueSource('   '))->issues());
    }

    public function testEineListeMitAllenParameternGibtKeinenBefund(): void
    {
        $quellen = [
            implode(',', NoticeParamIssueSource::PARAMS),
            'page,'.implode(',', NoticeParamIssueSource::PARAMS).',order',
        ];

        foreach ($quellen as $liste) {
            self::assertSame([], (new NoticeParamIssueSource($liste))->issues(), $liste);
        }
    }

    /**
     * Review C-10: Bis dahin prüfte der Befund nur consho_notice. Dieselbe
     * Liste entfernt aber auch den Checkout-Schritt und das Token der
     * Bestellseite — der Befund nennt genau, was fehlt.
     */
    public function testNurDerHinweisParameterReichtNichtMehr(): void
    {
        $issues = (new NoticeParamIssueSource('page,'.CartNotice::PARAM))->issues();

        self::assertCount(1, $issues);
        // D-2: dazu die Erklärung der Bestellseite und das Ergebnis des Widerrufsformulars.
        self::assertSame('consho_step, consho_purchase, consho_ordered, consho_order, consho_method, consho_session, consho_signature, consho_result, consho_withdraw, consho_withdrawal, consho_cancel, consho_cancellation, consho_hash, consho_expires, consho_open_subscription, consho_cancel_subscription, consho_my_subscriptions, consho_pause_subscription', $issues[0]->context['param']);
    }

    /**
     * Die Namen des Checkouts stehen dort als Literale. Ändert sich einer,
     * muss er hier mitwandern — sonst prüfte der Befund einen Namen, den
     * niemand mehr liest.
     */
    public function testDieParameterSindDieDieDerCheckoutLiest(): void
    {
        $checkout = (string) file_get_contents(__DIR__.'/../../src/Controller/ContentElement/CheckoutController.php');

        foreach (['consho_step', 'consho_purchase', 'consho_ordered'] as $param) {
            self::assertStringContainsString(\sprintf("query->getString('%s'", $param), $checkout, $param);
        }

        preg_match_all("#query->getString\\('(consho_[a-z_]+)'#", $checkout, $read);

        foreach ($read[1] as $param) {
            self::assertContains($param, NoticeParamIssueSource::PARAMS, $param.' liest der Checkout, der Befund prüft ihn nicht.');
        }
    }

    /**
     * Review C-11, Befund 2: Jeder Adressparameter des Test-Anbieters steht
     * in der Liste — gelesen aus seinen Konstanten, nicht abgeschrieben.
     */
    public function testDieParameterDesTestAnbietersZaehlenMit(): void
    {
        foreach ((new \ReflectionClass(TestPaymentHandler::class))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'PARAM_')) {
                self::assertContains($constant->getValue(), NoticeParamIssueSource::PARAMS, $constant->getName());
            }
        }

        // Eine Liste, die nur die alten fünf nennt, ist jetzt ein Befund.
        $issues = (new NoticeParamIssueSource('consho_notice,consho_step,consho_purchase,consho_ordered,consho_order'))->issues();
        self::assertCount(1, $issues);
        self::assertStringContainsString('consho_result', (string) $issues[0]->context['param']);
    }

    public function testEinLeerzeichenNachDemKommaWirdGemeldet(): void
    {
        // ⚠️ Hier stand ursprünglich die Erwartung, „ page , consho_notice "
        // sei in Ordnung — die freundliche Lesart, und die falsche
        // (Abschlussreview C-3c). ContaoCache::readEnvCsv() ist
        // array_filter(explode(',', …)) und trimmt NICHT: Aus „ consho_notice"
        // wird das Muster „^(?: consho_notice)$", mit dem Leerzeichen darin,
        // und das trifft nicht. Contao entfernt den Parameter also — genau der
        // Fall, für den es diesen Befund gibt.
        //
        // Der alte Test hätte die Lücke festgeschrieben: Er war grün, weil
        // Prüfling und Erwartung denselben Irrtum teilten.
        self::assertCount(1, (new NoticeParamIssueSource('page, '.CartNotice::PARAM))->issues());
    }

    public function testGrossKleinschreibungZaehltNicht(): void
    {
        // Der Subscriber vergleicht über einen Regex mit /i. Eine Liste mit
        // „Consho_Notice" funktioniert also — wir dürften sie nicht als
        // fehlend melden, sonst schickten wir den Betreiber auf die Suche
        // nach einem Fehler, den es nicht gibt.
        self::assertSame([], (new NoticeParamIssueSource('page,'.strtoupper(implode(',', NoticeParamIssueSource::PARAMS))))->issues());
    }

    public function testEinRegexEintragDerDenParameterTrifftGiltAlsErlaubt(): void
    {
        // Die Einträge sind Regex-Bruchstücke, keine Namen. „consho_.*" lässt
        // den Parameter durch; ein Namensvergleich hätte ihn als fehlend
        // gemeldet und den Betreiber auf die Suche nach nichts geschickt.
        self::assertSame([], (new NoticeParamIssueSource('page,consho_.*'))->issues());
        self::assertSame([], (new NoticeParamIssueSource('.*'))->issues());
    }

    public function testEinUnbrauchbaresMusterWirdGemeldetStattVerschluckt(): void
    {
        // Eine eckige Klammer zu viel zerlegt das Muster — und damit auch den
        // Subscriber. Was Contao dann tut, ist nicht unsere Vorhersage; laut
        // zu sein ist hier besser als stillzuhalten.
        self::assertCount(1, (new NoticeParamIssueSource('page,[unfertig'))->issues());
    }

    public function testEineListeOhneDenParameterWirdGemeldet(): void
    {
        $issues = (new NoticeParamIssueSource('page,order'))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::NoticeParamStripped, $issues[0]->finding);
        self::assertSame(['param' => implode(', ', NoticeParamIssueSource::PARAMS)], $issues[0]->context);
    }

    public function testEinTeiltrefferReichtNicht(): void
    {
        // „notice" ist nicht „consho_notice": Der Subscriber vergleicht den
        // ganzen Namen, nicht einen Teil davon. Ein Vergleich per str_contains
        // hielte diese Liste für ausreichend und schwiege.
        $issues = (new NoticeParamIssueSource('notice,consho'))->issues();

        self::assertCount(1, $issues);
    }

    public function testDerBefundIstEineWarnungDieNichtSperrt(): void
    {
        // Seit dem Review von C-10 eine Warnung: Ohne Schritt und Token geht
        // der Checkout nicht weiter und keine Bestellseite auf. Sperren kann
        // er trotzdem nicht — die Liste ist die Entscheidung des Betreibers
        // über seinen Cache, nicht etwas, das der Shop richten könnte.
        self::assertTrue(ConfigurationFinding::NoticeParamStripped->isWarning());

        $blocking = (new \ReflectionClassConstant(\Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness::class, 'BLOCKING'))->getValue();
        self::assertNotContains(ConfigurationFinding::NoticeParamStripped, $blocking);
    }

    /**
     * Nachlese K3: Ein Zahlungsmodul nennt die Rückkehr-Parameter seines
     * Anbieters (etwa Stripes `session_id`) — sie kommen in denselben Befund.
     */
    public function testRueckkehrParameterEinesZahlungsmoduls(): void
    {
        $modul = new class implements ReturnParametersInterface {
            public static function returnParameters(): array
            {
                return ['session_id'];
            }
        };

        $issues = (new NoticeParamIssueSource('consho_.*', [$modul]))->issues();

        self::assertCount(1, $issues);
        self::assertSame(['param' => 'session_id'], $issues[0]->context);
        self::assertSame([], (new NoticeParamIssueSource('consho_.*,session_id', [$modul]))->issues());
        self::assertSame([], (new NoticeParamIssueSource('consho_.*', [new \stdClass()]))->issues(), 'ein Modul ohne die Schnittstelle nennt nichts');
    }
}