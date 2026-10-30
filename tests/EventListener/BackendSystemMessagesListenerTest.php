<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Config\AcknowledgementStore;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\SetupIssueSource;
use Webwerkwien\ContaoConshoBundle\EventListener\BackendSystemMessagesListener;

class BackendSystemMessagesListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['findings'] = [
            'taxRateMissing' => 'Für %country% fehlt ein Satz der Klasse „%taxClass%".',
            'duplicateVatId' => 'Zwei Konfigurationen mit %vatId%: %shops%.',
            'mailTransportMissing' => 'Shop „%shop%" hat keine Mail-Konfiguration.',
            'duplicateGtin' => 'GTIN %gtin% an %count% Produkten.',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    /**
     * No findings must produce NO box, not an empty one. An empty panel on the
     * start page reads as "something is broken here" every single day.
     */
    public function testWithoutFindingsItRendersNothingAtAll(): void
    {
        self::assertSame('', $this->listenerWith([])());
    }

    /**
     * Dashboard DB2: whoever may open the dashboard sees the warnings on the
     * start page and, instead of the notices, one line with a link.
     */
    public function testMitDashboardStehenDieHinweiseDortUndHierNurIhreZahl(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['dashboard'] = ['notesElsewhere' => '%d Hinweise im Shop-Dashboard.', 'open' => 'Öffnen'];

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/contao?do=consho_dashboard');

        $listener = new BackendSystemMessagesListener(
            [$this->sourceOf([
                new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'X']),
                new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']),
                new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '1', 'count' => '2']),
            ])],
            $this->store(),
            security: $this->security(true),
            router: $router,
        );

        $html = $listener();

        self::assertStringContainsString('Für IT fehlt', $html, 'Warnungen bleiben');
        self::assertStringNotContainsString('ATU1', $html);
        self::assertStringNotContainsString('consho_ack', $html);
        self::assertStringContainsString('2 Hinweise im Shop-Dashboard.', $html);
        self::assertStringContainsString('href="/contao?do=consho_dashboard"', $html);
        self::assertStringContainsString('ATU1', $listener->panel(), 'im Dashboard steht der Hinweis');
    }

    public function testOhneDashboardBleibenDieHinweiseAufDerStartseite(): void
    {
        $html = (new BackendSystemMessagesListener(
            [$this->sourceOf([new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B'])])],
            $this->store(),
            security: $this->security(false),
        ))();

        self::assertStringContainsString('ATU1', $html, 'sonst sähe er sie nirgends');
        self::assertStringContainsString('consho_ack', $html);
    }

    /**
     * Acknowledging — a notice is saved and the page reloaded as a GET; a
     * warning and a fingerprint that describes nothing current are not. One
     * case per exit.
     */
    public function testEinQuittierterHinweisWirdGespeichertUndDieSeiteNeuGeladen(): void
    {
        $note = new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']);
        $stored = [];

        try {
            $this->acknowledging([$note], $note->fingerprint(), $stored)->panel();
            self::fail('Nach dem Speichern wird die Seite neu geladen.');
        } catch (RedirectResponseException $e) {
            self::assertSame('/contao?do=consho_dashboard', $e->getResponse()->headers->get('Location'));
        }

        self::assertSame([[$note->fingerprint(), 3]], $stored);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nichtZuQuittieren(): iterable
    {
        yield 'eine Warnung' => ['warning'];
        yield 'ein Fingerabdruck ohne aktuellen Befund' => ['stale'];
    }

    #[DataProvider('nichtZuQuittieren')]
    public function testNichtZuQuittieren(string $case): void
    {
        $warning = new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'X']);
        $note = new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']);
        $submitted = 'warning' === $case ? $warning->fingerprint() : sha1('längst behoben');
        $stored = [];

        $html = $this->acknowledging([$warning, $note], $submitted, $stored)->panel();

        self::assertSame([], $stored);
        self::assertStringContainsString('ATU1', $html, 'die Seite rendert normal weiter');
    }

    public function testDieHinweiszeileZaehltQuittierteNichtMit(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['dashboard'] = ['notesElsewhere' => '%d Hinweise im Shop-Dashboard.'];
        $quittiert = new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']);
        $offen = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '1', 'count' => '2']);

        $html = (new BackendSystemMessagesListener(
            [$this->sourceOf([$quittiert, $offen])],
            $this->store([$quittiert->fingerprint()]),
            security: $this->security(true),
        ))();

        self::assertStringContainsString('1 Hinweise im Shop-Dashboard.', $html);
    }

    /**
     * The dashboard boxes (DB3): what the list shows is counted — warnings
     * always, notices only unseen ones.
     */
    public function testDieKaestenZaehlenWasDieListeZeigt(): void
    {
        $quittiert = new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']);
        $offen = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '1', 'count' => '2']);
        $warnung = new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'X']);
        // Unequal numbers: otherwise a swapped counter goes unnoticed.
        $zweite = new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'FR', 'taxClass' => 'X']);

        $summary = (new BackendSystemMessagesListener(
            [$this->sourceOf([$quittiert, $offen, $warnung, $zweite])],
            $this->store([$quittiert->fingerprint()]),
            security: $this->security(true),
        ))->summary();

        self::assertSame([2, 1], [$summary['warnings'], $summary['notes']]);
        self::assertStringStartsWith('<div class="tl_error">', $summary['warningsHtml']);
        self::assertSame(2, substr_count($summary['warningsHtml'], '<p'), 'die Liste zeigt, was der Kasten zählt');
        self::assertSame(1, substr_count($summary['notesHtml'], 'name="consho_ack"'));
    }

    /**
     * @param list<ConfigurationIssue>        $issues
     * @param list<array{0: string, 1: int}> $stored
     * @param list<string>                   $modules
     */
    private function acknowledging(array $issues, string $submitted, array &$stored, array $modules = ['consho_shop', 'consho_tax', 'consho_product']): BackendSystemMessagesListener
    {
        $store = $this->createMock(AcknowledgementStore::class);
        $store->method('fingerprints')->willReturn([]);
        $store->method('acknowledge')->willReturnCallback(static function (ConfigurationIssue $issue, int $userId) use (&$stored): void {
            $stored[] = [$issue->fingerprint(), $userId];
        });

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 3 : null);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $subject = null): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && \in_array($subject, $modules, true));

        $requests = new RequestStack();
        $requests->push(Request::create('/contao?do=consho_dashboard', 'POST', ['consho_ack' => $submitted]));

        return new BackendSystemMessagesListener([$this->sourceOf($issues)], $store, security: $security, requests: $requests);
    }

    /**
     * An editor with setup and products — the modules in which the findings of
     * these tests are fixed (H1).
     *
     * @param list<string> $modules
     */
    private function security(bool $dashboard, array $modules = ['consho_shop', 'consho_tax', 'consho_product'], bool $admin = false): Security
    {
        $granted = $dashboard ? [...$modules, 'consho_dashboard'] : $modules;

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $subject = null): bool => ('ROLE_ADMIN' === $attribute && $admin)
            || (ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && \in_array($subject, $granted, true)));

        return $security;
    }

    /**
     * H1: a finding appears only to those who may open one of the modules in
     * which it is fixed — warnings included (H2).
     */
    public function testNurWerDasModulOeffnenDarfSiehtDenBefund(): void
    {
        $warning = new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'X']);
        $note = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '4006381333931', 'count' => '2']);

        $nurProdukte = (new BackendSystemMessagesListener([$this->sourceOf([$warning, $note])], $this->store([]), security: $this->security(false, ['consho_product'])))->panel();
        $nurSteuern = (new BackendSystemMessagesListener([$this->sourceOf([$warning, $note])], $this->store([]), security: $this->security(false, ['consho_tax'])))->panel();

        self::assertStringContainsString('4006381333931', $nurProdukte);
        self::assertStringNotContainsString('Für IT', $nurProdukte, 'die Warnung gehört in Shop oder Steuern');
        self::assertStringContainsString('Für IT fehlt', $nurSteuern, 'eines der beiden Module genügt');
        self::assertStringNotContainsString('4006381333931', $nurSteuern);
    }

    public function testWasKeinModulBehebtSiehtNurDerAdministrator(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['findings']['testPaymentActive'] = 'Test-Zahlung ist an.';
        $issue = new ConfigurationIssue(ConfigurationFinding::TestPaymentActive, []);

        $redakteur = new BackendSystemMessagesListener([$this->sourceOf([$issue])], $this->store([]), security: $this->security(false));
        $admin = new BackendSystemMessagesListener([$this->sourceOf([$issue])], $this->store([]), security: $this->security(false, [], true));

        self::assertSame('', $redakteur->panel());
        self::assertStringContainsString('Test-Zahlung ist an.', $admin->panel());
    }

    /**
     * H4: the line on the start page counts only what the dashboard shows this
     * user.
     */
    public function testDieHinweiszeileZaehltNurSichtbare(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['dashboard'] = ['notesElsewhere' => '%d Hinweise im Shop-Dashboard.'];
        $sichtbar = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '1', 'count' => '2']);
        $verborgen = new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']);

        $html = (new BackendSystemMessagesListener([$this->sourceOf([$sichtbar, $verborgen])], $this->store([]), security: $this->security(true, ['consho_product'])))();

        self::assertStringContainsString('1 Hinweise im Shop-Dashboard.', $html);
    }

    /**
     * Acknowledging applies to everyone — so a notice this user does not see
     * may not be clicked away by hand either.
     */
    public function testEinVerborgenerHinweisLaesstSichNichtQuittieren(): void
    {
        $verborgen = new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']);
        $stored = [];

        $this->acknowledging([$verborgen], $verborgen->fingerprint(), $stored, ['consho_product'])->panel();

        self::assertSame([], $stored);
    }

    /**
     * H3: every finding has an answer, and every module named exists — a typo
     * in the module name hid the finding from everyone except the
     * administrator.
     */
    public function testJederBefundNenntBestehendeModule(): void
    {
        preg_match_all("/^\s*'(consho_[a-z_]+)' => \[/m", (string) file_get_contents(__DIR__.'/../../contao/config/config.php'), $matches);
        // Contao's own: site structure, articles, and the themes with their front end modules (Nachlese KA5),
        // the file manager (block GARAN: a label in a folder that is not public),
        // and the members (net price groups from a verified VAT ID).
        $modules = [...$matches[1], 'page', 'article', 'themes', 'files', 'member'];

        foreach (ConfigurationFinding::cases() as $finding) {
            foreach ($finding->fixedIn() as $module) {
                self::assertContains($module, $modules, $finding->value);
            }
        }

        self::assertSame([], ConfigurationFinding::TestPaymentActive->fixedIn(), 'nur der Administrator');
    }

    public function testAMissingTaxRateNamesCountryAndClass(): void
    {
        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'Ermäßigt']),
        ])();

        self::assertStringContainsString('IT', $html);
        self::assertStringContainsString('Ermäßigt', $html);
    }

    /**
     * A missing rate breaks an order; a shared VAT ID is worth a question. The
     * two must not look the same, or the urgent one drowns.
     */
    public function testWarningsAndNotesUseDifferentBoxes(): void
    {
        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'X']),
            new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']),
        ])();

        self::assertStringContainsString('tl_error', $html);
        self::assertStringContainsString('tl_info', $html);
    }

    public function testOnlyNotesRenderNoErrorBox(): void
    {
        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::DuplicateVatId, ['vatId' => 'ATU1', 'shops' => 'A, B']),
        ])();

        self::assertStringNotContainsString('tl_error', $html);
        self::assertStringContainsString('tl_info', $html);
    }

    /**
     * The shop title comes from a text field an operator fills in. A name
     * containing "<" must not take the back end start page apart.
     */
    public function testValuesAreEscaped(): void
    {
        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::MailTransportMissing, ['shop' => '<script>alert(1)</script>']),
        ])();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * 🔴 A shop named "Shop (DE)" appeared as "Shop &#40;DE&#41;".
     *
     * Contao stores the brackets encoded; specialchars() alone turns the `&`
     * into `&amp;`, and the operator reads the entity. They then look for a
     * shop that is called that.
     */
    public function testEntitiesAusDerDatenbankWerdenLesbar(): void
    {
        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::MailTransportMissing, ['shop' => 'Testshop 2 &#40;DE&#41;']),
        ])();

        self::assertStringContainsString('Testshop 2 (DE)', $html);
        self::assertStringNotContainsString('&amp;#40;', $html);
    }

    /**
     * A missing translation must look wrong rather than look like an empty
     * message — an unlabelled bullet tells nobody anything.
     */
    public function testAnUntranslatedFindingFallsBackToItsCode(): void
    {
        unset($GLOBALS['TL_LANG']['CONSHO']['findings']['taxRateMissing']);

        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'X']),
        ])();

        self::assertStringContainsString('taxRateMissing', $html);
    }

    /**
     * @param list<ConfigurationIssue> $issues
     */
    private function listenerWith(array $issues): BackendSystemMessagesListener
    {
        $source = $this->createMock(ConfigurationIssueSource::class);
        $source->method('issues')->willReturn($issues);

        return new BackendSystemMessagesListener([$source], $this->store());
    }

    public function testANoteCarriesAWayToTakeNoteOfIt(): void
    {
        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '4006381333931', 'count' => '2']),
        ])();

        self::assertStringContainsString('consho_ack', $html);
    }

    public function testDerHinweisZuMehrerenShopsSperrtNichtsUndLaesstSichWegklicken(): void
    {
        // 🔴 "Notice" must be more than a word in the code: several shops are
        // allowed and intended (Block A, 4.0), while a warning would lock the
        // shop's checkout under 11.2. Proven on the only machinery that exists
        // for it today — the finding lands in the notice box and carries the
        // button that only notices carry.
        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::MultipleShopsShareMembers, ['shops' => 'Kräuterhaus, Almhütte']),
        ])();

        self::assertStringNotContainsString('tl_error', $html);
        self::assertStringContainsString('tl_info', $html);
        self::assertStringContainsString('consho_ack', $html);
    }

    public function testAWarningDoesNot(): void
    {
        // 🔴 A missing tax rate makes orders from that country fail at
        // checkout. Nobody gets to silence that from the start page.
        $html = $this->listenerWith([
            new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'Bücher']),
        ])();

        self::assertStringNotContainsString('consho_ack', $html);
    }

    public function testAnAcknowledgedNoteIsGone(): void
    {
        $issue = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '4006381333931', 'count' => '2']);

        $listener = new BackendSystemMessagesListener(
            [$this->sourceOf([$issue])],
            $this->store([$issue->fingerprint()]),
        );

        self::assertSame('', $listener());
    }

    public function testTheSameFindingInANewStateComesBack(): void
    {
        // ⚠️ The rule the whole feature stands on. Somebody judged "on 2
        // products"; three is a different case and has to be seen again.
        $judged = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '4006381333931', 'count' => '2']);
        $now = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '4006381333931', 'count' => '3']);

        $listener = new BackendSystemMessagesListener(
            [$this->sourceOf([$now])],
            $this->store([$judged->fingerprint()]),
        );

        self::assertStringContainsString('4006381333931', $listener());
    }

    public function testTheOrderOfContextKeysDoesNotInvalidateAnAcknowledgement(): void
    {
        $a = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '1', 'count' => '2']);
        $b = new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['count' => '2', 'gtin' => '1']);

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    /**
     * @param list<ConfigurationIssue> $issues
     */
    /**
     * N1: the setup page also reads the root page findings of unpublished root
     * pages; issues() stays with the published ones, even after a call of
     * issuesForSetup().
     */
    public function testFuerDieEinrichtungFragtErDieVarianteDerQuelle(): void
    {
        $live = new ConfigurationIssue(ConfigurationFinding::CurrencyMissing, [], 1);
        $prepared = new ConfigurationIssue(ConfigurationFinding::CartPageMissing, [], 1);

        $setup = $this->createMock(SetupIssueSource::class);
        $setup->method('issues')->willReturn([]);
        $setup->method('issuesForSetup')->willReturn([$prepared]);

        $listener = new BackendSystemMessagesListener([$this->sourceOf([$live]), $setup], $this->store());

        self::assertSame([$live, $prepared], $listener->issuesForSetup());
        self::assertSame([$live], $listener->issues());
    }

    private function sourceOf(array $issues): ConfigurationIssueSource
    {
        $source = $this->createMock(ConfigurationIssueSource::class);
        $source->method('issues')->willReturn($issues);

        return $source;
    }

    /**
     * @param list<string> $fingerprints
     */
    private function store(array $fingerprints = []): AcknowledgementStore
    {
        $store = $this->createMock(AcknowledgementStore::class);
        $store->method('fingerprints')->willReturn($fingerprints);

        return $store;
    }

    /**
     * Abo-7: between unpacking and contao:migrate a source reads a
     * column that is not there yet. It is logged and skipped; the others
     * still speak, and the back end page does not answer 500.
     */
    public function testAFailingSourceIsLoggedAndTheOthersStillSpeak(): void
    {
        $broken = $this->createMock(ConfigurationIssueSource::class);
        $broken->method('issues')->willThrowException(new \RuntimeException('no such column consho_cancellationPage'));

        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->errors[] = (string) $message;
            }
        };

        $listener = new BackendSystemMessagesListener(
            [$broken, $this->sourceOf([new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, ['country' => 'IT', 'taxClass' => 'X'])])],
            $this->store(),
            logger: $logger,
        );

        self::assertStringContainsString('IT', $listener());
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('no such column consho_cancellationPage', $logger->errors[0]);
    }

    public function testGathersTheFindingsOfEverySource(): void
    {
        // Since A-11 there are two: configuration and product safety. A
        // listener reading only the first would drop the GPSR findings without
        // anything failing.
        $configuration = $this->createMock(ConfigurationIssueSource::class);
        $configuration->method('issues')->willReturn([
            new ConfigurationIssue(ConfigurationFinding::TaxRateMissing, []),
        ]);

        $safety = $this->createMock(ConfigurationIssueSource::class);
        $safety->method('issues')->willReturn([
            new ConfigurationIssue(ConfigurationFinding::ProductManufacturerMissing, ['count' => '7']),
        ]);

        $html = (new BackendSystemMessagesListener([$configuration, $safety], $this->store()))();

        // The first is translated by the fixture, the second falls back to its
        // bare code — both have to reach the box.
        self::assertStringContainsString('fehlt ein Satz der Klasse', $html);
        self::assertStringContainsString('productManufacturerMissing', $html);
    }
}
