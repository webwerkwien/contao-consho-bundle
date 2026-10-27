<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Cart\NoticeParamIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Payment\ReturnParametersInterface;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;

/**
 * The finding about QUERY_PARAMS_ALLOW_LIST (C-3c).
 *
 * What these tests do NOT show: that Contao really reads the variable that way.
 * That is in ContaoCache of the manager-bundle and was read up there, not
 * reproduced — a test for it would check our own transcript.
 * What is checked is the decision: when do we report, and when not.
 */
class NoticeParamIssueSourceTest extends TestCase
{
    public function testEineNichtGesetzteVariableIstNullUndKeinLeererText(): void
    {
        // ⚠️ The case that took down the whole back end on a real installation:
        // `%env(default::NAME)%` resolves to NULL for an unset variable,
        // not to "". With `string` as the type the service died with
        // a TypeError — and because it is built for the system messages in the header menu,
        // EVERY back-end page died with it, not just the
        // start page.
        //
        // No test saw it: they all build the class directly and never the
        // container. That is why the case is here now.
        self::assertSame([], (new NoticeParamIssueSource(null))->issues());
    }

    public function testOhneAllowListGibtEsKeinenBefund(): void
    {
        // The normal case and the safe one: without a list Contao removes only
        // its deny list, and that contains tracking parameters exclusively.
        // array_filter throws away empty entries, so ",," is as good as
        // nothing.
        self::assertSame([], (new NoticeParamIssueSource(''))->issues());
        self::assertSame([], (new NoticeParamIssueSource(',,'))->issues());
    }

    public function testEineListeAusLeerzeichenIstKeineLeereListe(): void
    {
        // "   " survives array_filter (not empty, just invisible) and is
        // therefore a set allow list whose only pattern "^(?:   )$"
        // matches nothing: Contao removes EVERY parameter. That must be reported.
        // When this was first written, the expectation here was "no finding" —
        // the same friendly reading that was already wrong about the space after the
        // comma.
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
     * C-10: until then the finding checked only consho_notice. The same
     * list, however, also removes the checkout step and the token of the
     * order page — the finding names exactly what is missing.
     */
    public function testNurDerHinweisParameterReichtNichtMehr(): void
    {
        $issues = (new NoticeParamIssueSource('page,'.CartNotice::PARAM))->issues();

        self::assertCount(1, $issues);
        // D-2: plus the order page's declaration and the result of the withdrawal form.
        self::assertSame('consho_step, consho_purchase, consho_ordered, consho_order, consho_method, consho_session, consho_signature, consho_result, consho_withdraw, consho_withdrawal, consho_cancel, consho_cancellation, consho_hash, consho_expires, consho_open_subscription, consho_cancel_subscription, consho_my_subscriptions, consho_pause_subscription', $issues[0]->context['param']);
    }

    /**
     * The checkout's names appear there as literals. If one changes,
     * it has to move along here — otherwise the finding would check a name
     * nobody reads any more.
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
     * C-11: every address parameter of the test provider is
     * in the list — read from its constants, not copied out.
     */
    public function testDieParameterDesTestAnbietersZaehlenMit(): void
    {
        foreach ((new \ReflectionClass(TestPaymentHandler::class))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'PARAM_')) {
                self::assertContains($constant->getValue(), NoticeParamIssueSource::PARAMS, $constant->getName());
            }
        }

        // A list that names only the old five is now a finding.
        $issues = (new NoticeParamIssueSource('consho_notice,consho_step,consho_purchase,consho_ordered,consho_order'))->issues();
        self::assertCount(1, $issues);
        self::assertStringContainsString('consho_result', (string) $issues[0]->context['param']);
    }

    public function testEinLeerzeichenNachDemKommaWirdGemeldet(): void
    {
        // ⚠️ Originally the expectation here was that " page , consho_notice "
        // is fine — the friendly reading, and the wrong one.
        // ContaoCache::readEnvCsv() is
        // array_filter(explode(',', …)) and does NOT trim: " consho_notice"
        // becomes the pattern "^(?: consho_notice)$", with the space in it,
        // and that does not match. So Contao removes the parameter — exactly the
        // case this finding exists for.
        //
        // The old test would have cemented the gap: it was green because
        // the code under test and the expectation shared the same mistake.
        self::assertCount(1, (new NoticeParamIssueSource('page, '.CartNotice::PARAM))->issues());
    }

    public function testGrossKleinschreibungZaehltNicht(): void
    {
        // The subscriber compares via a regex with /i. A list with
        // "Consho_Notice" therefore works — we must not report it as
        // missing, or we would send the operator searching
        // for an error that does not exist.
        self::assertSame([], (new NoticeParamIssueSource('page,'.strtoupper(implode(',', NoticeParamIssueSource::PARAMS))))->issues());
    }

    public function testEinRegexEintragDerDenParameterTrifftGiltAlsErlaubt(): void
    {
        // The entries are regex fragments, not names. "consho_.*" lets
        // the parameter through; a name comparison would have reported it as missing
        // and sent the operator searching for nothing.
        self::assertSame([], (new NoticeParamIssueSource('page,consho_.*'))->issues());
        self::assertSame([], (new NoticeParamIssueSource('.*'))->issues());
    }

    public function testEinUnbrauchbaresMusterWirdGemeldetStattVerschluckt(): void
    {
        // One square bracket too many breaks the pattern — and with it the
        // subscriber. What Contao does then is not ours to predict; being loud
        // is better here than keeping quiet.
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
        // "notice" is not "consho_notice": the subscriber compares the
        // whole name, not a part of it. A comparison via str_contains
        // would consider this list sufficient and stay silent.
        $issues = (new NoticeParamIssueSource('notice,consho'))->issues();

        self::assertCount(1, $issues);
    }

    public function testDerBefundIstEineWarnungDieNichtSperrt(): void
    {
        // A warning since C-10: without step and token the
        // checkout does not proceed and no order page opens. It still cannot
        // block — the list is the operator's decision
        // about their cache, not something the shop could fix.
        self::assertTrue(ConfigurationFinding::NoticeParamStripped->isWarning());

        $blocking = (new \ReflectionClassConstant(\Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness::class, 'BLOCKING'))->getValue();
        self::assertNotContains(ConfigurationFinding::NoticeParamStripped, $blocking);
    }

    /**
     * Nachlese K3: a payment module names the return parameters of its
     * provider (Stripe's `session_id`, for one) — they go into the same finding.
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