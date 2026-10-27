<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Setup\NumberRangeSetup;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalLegalReadinessIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;
use Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing\FakeInvoiceProvider;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalLegalReadinessIssueSourceTest extends TestCase
{
    private const NOTICE = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    private Connection $connection;

    private FakeInvoiceProvider|null $provider = null;

    protected function setUp(): void
    {
        // Since C-12 the issue reads its label from TL_LANG; without language
        // data it falls back to the key. Other tests load the language files
        // and leave them in place — here the fallback applies.
        unset($GLOBALS['TL_LANG']);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->executeStatement(
            "CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT 'file')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 1,
             start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, ptable TEXT NOT NULL DEFAULT 'tl_article',
             type TEXT NOT NULL DEFAULT 'text', invisible INTEGER NOT NULL DEFAULT 0,
             start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_payment_method');
        DcaSchema::create($this->connection, 'tl_consho_number_range');

        $this->vollstaendig();
    }

    public function testEinVollstaendigEingerichteterShopMeldetNichts(): void
    {
        self::assertSame([], $this->issues());
    }

    // ── Guarantee notice (§ 4 Abs 1 Z 12 FAGG, from 27.09.2026) ─────────────

    /**
     * The harmonised EU notice on the legal guarantee of conformity.
     * Consho does NOT ship the graphic — it checks whether the page exists and
     * is reachable, and links it in the checkout.
     */
    public function testEineFehlendeGewaehrleistungsSeiteWirdGemeldet(): void
    {
        $this->connection->update('tl_page', ['consho_warrantyPage' => 0], ['id' => 1]);

        self::assertSame(1, $this->only(ConfigurationFinding::WarrantyNoticeMissing)->shopId);
    }

    public function testEineUnveroeffentlichteGewaehrleistungsSeiteWirdGemeldet(): void
    {
        $this->connection->update('tl_page', ['published' => 0], ['id' => 8]);

        self::assertSame(
            ConfigurationFinding::WarrantyNoticeMissing,
            $this->only(ConfigurationFinding::WarrantyNoticeMissing)->finding,
        );
    }

    /**
     * The control case for the shared query: privacy and guarantee run
     * through the same method, but must not depend on each other.
     */
    public function testDatenschutzUndGewaehrleistungWerdenGetrenntGemeldet(): void
    {
        $this->connection->update('tl_page', ['consho_warrantyPage' => 0], ['id' => 1]);

        $findings = array_map(static fn ($i): string => $i->finding->value, $this->issues());

        self::assertContains('warrantyNoticeMissing', $findings);
        self::assertNotContains('privacyPageMissing', $findings);
    }

    /**
     * C-9: the confirmation has to contain the notice (Art. 8(7)(a) CRD,
     * § 7 Abs 3 FAGG) — without the file every confirmation would be
     * incomplete.
     */
    public function testOhneGewaehrleistungsDateiWirdGemeldet(): void
    {
        $this->connection->update('tl_page', ['consho_warrantyNotice' => null], ['id' => 1]);

        self::assertSame(1, $this->only(ConfigurationFinding::WarrantyNoticeFileMissing)->shopId);
    }

    public function testEineGeloeschteGewaehrleistungsDateiWirdGemeldet(): void
    {
        $this->connection->delete('tl_files', ['id' => 20]);

        self::assertSame(1, $this->only(ConfigurationFinding::WarrantyNoticeFileMissing)->shopId);
    }

    public function testEinOrdnerIstKeineGewaehrleistungsDatei(): void
    {
        $this->connection->update('tl_files', ['type' => 'folder'], ['id' => 20]);

        self::assertSame(1, $this->only(ConfigurationFinding::WarrantyNoticeFileMissing)->shopId);
    }

    /**
     * Page and file are two fields and two steps — what is missing is
     * reported, not both.
     */
    public function testSeiteUndDateiWerdenGetrenntGemeldet(): void
    {
        $this->connection->update('tl_page', ['consho_warrantyNotice' => null], ['id' => 1]);

        $findings = array_map(static fn ($i): string => $i->finding->value, $this->issues());

        self::assertContains('warrantyNoticeFileMissing', $findings);
        self::assertNotContains('warrantyNoticeMissing', $findings);
    }

    // ── Root pages that serve nobody ────────────────────────────────────────

    /**
     * The same rule as in C-5: an unpublished root page serves nobody, and an
     * issue about it would block a shop that is running.
     */
    public function testEinUnveroeffentlichterStartpunktWirdNichtGemeldet(): void
    {
        $this->connection->insert('tl_page', [
            'id' => 60, 'title' => 'Testpunkt', 'type' => 'root', 'published' => 0, 'consho_shop' => 1,
        ]);

        self::assertSame([], $this->issues());
    }

    /**
     * N1 (Nachlese stage 2): for the setup page also unpublished ones.
     */
    public function testFuerDieEinrichtungZaehltAuchEinUnveroeffentlichterStartpunkt(): void
    {
        $this->connection->insert('tl_page', [
            'id' => 60, 'title' => 'Testpunkt', 'type' => 'root', 'published' => 0, 'consho_shop' => 1,
        ]);

        $issues = (new DbalLegalReadinessIssueSource($this->connection, $this->handlers()))->issuesForSetup();
        $findings = array_map(static fn ($i): string => $i->finding->value, $issues);

        self::assertContains('privacyPageMissing', $findings);
        self::assertSame([], $this->issues(), 'der normale Weg bleibt bei veröffentlichten');
    }

    public function testEinZeitlichUnerreichbarerStartpunktWirdNichtGemeldet(): void
    {
        $this->connection->insert('tl_page', [
            'id' => 61, 'title' => 'Noch nicht', 'type' => 'root', 'published' => 1,
            'start' => (string) (time() + 86_400), 'consho_shop' => 1,
        ]);

        self::assertSame([], $this->issues());
    }

    public function testEinVeroeffentlichterZweiterStartpunktWirdSehrWohlGemeldet(): void
    {
        // The control case for the two above: it has neither a privacy page
        // nor legal texts.
        $this->connection->insert('tl_page', [
            'id' => 62, 'title' => 'Zweiter', 'type' => 'root', 'published' => 1, 'consho_shop' => 1,
        ]);

        self::assertNotSame([], $this->issues());
    }

    // ── Datenschutz ─────────────────────────────────────────────────────────

    public function testEineFehlendeDatenschutzseiteWirdGemeldet(): void
    {
        $this->connection->update('tl_page', ['consho_privacyPage' => 0], ['id' => 1]);

        self::assertSame(1, $this->only(ConfigurationFinding::PrivacyPageMissing)->shopId);
    }

    public function testEineUnveroeffentlichteDatenschutzseiteWirdGemeldet(): void
    {
        // Chosen and still unreachable — the link would lead nowhere.
        $this->connection->update('tl_page', ['published' => 0], ['id' => 4]);

        self::assertSame(ConfigurationFinding::PrivacyPageMissing, $this->only(ConfigurationFinding::PrivacyPageMissing)->finding);
    }

    // ── Which texts block (D-2, E1) ─────────────────────────────────────────

    /**
     * Blocking on missing terms and conditions is wrong — a shop does not have
     * to have any. Otherwise blocking only when the operator is in the EU.
     */
    public function testOhneAgbHaekchenSindKeineAgbKeinBefund(): void
    {
        $this->connection->update('tl_consho_shop', ['termsRequired' => 0], ['id' => 1]);
        $this->connection->update('tl_page', ['consho_termsElement' => 0], ['id' => 1]);

        self::assertSame([], $this->issues());
    }

    public function testGewaehlteAberUnlesbareAgbOhneHaekchenSindEinHinweis(): void
    {
        $this->connection->update('tl_consho_shop', ['termsRequired' => 0], ['id' => 1]);
        $this->connection->update('tl_content', ['invisible' => 1], ['id' => 10]);

        $issue = $this->only(ConfigurationFinding::LegalTextAdvised);

        self::assertSame('terms', $issue->context['kinds']);
        self::assertFalse($issue->finding->isWarning(), 'sperrt nicht');
    }

    /**
     * @return iterable<string, array{string, int, ConfigurationFinding|null}>
     */
    public static function widerrufsbelehrungNachSitz(): iterable
    {
        yield 'Österreich' => ['AT', 14, ConfigurationFinding::LegalTextMissing];
        yield 'Norwegen (EWR)' => ['NO', 14, ConfigurationFinding::LegalTextMissing];
        yield 'Schweiz' => ['CH', 14, ConfigurationFinding::LegalTextAdvised];
        yield 'Schweiz, kürzere Frist' => ['CH', 7, ConfigurationFinding::LegalTextAdvised];
        yield 'ohne Widerrufsfunktion' => ['AT', 0, null];
    }

    /**
     * @dataProvider widerrufsbelehrungNachSitz
     */
    public function testDieWiderrufsbelehrungSperrtNurInEuUndEwr(string $country, int $days, ConfigurationFinding|null $expected): void
    {
        $this->connection->update('tl_consho_shop', ['country' => $country, 'withdrawalDays' => $days], ['id' => 1]);
        $this->connection->update('tl_page', ['consho_withdrawalElement' => 0], ['id' => 1]);

        // Period 0: no function — then the element is not missing either.
        $issues = array_values(array_filter($this->issues(), static fn (ConfigurationIssue $i): bool => \in_array($i->finding, [ConfigurationFinding::LegalTextMissing, ConfigurationFinding::LegalTextAdvised], true)));

        self::assertSame(null === $expected ? [] : [$expected], array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $issues));
    }

    // ── Function "Withdraw from contract" (D-2, Spec D 10) ──────────────────

    /**
     * @return iterable<string, array{string, int, ConfigurationFinding|null}>
     */
    public static function funktionNachSitz(): iterable
    {
        yield 'Österreich' => ['AT', 14, ConfigurationFinding::WithdrawalFunctionMissing];
        yield 'Island (EWR)' => ['IS', 14, ConfigurationFinding::WithdrawalFunctionMissing];
        yield 'Schweiz' => ['CH', 14, ConfigurationFinding::WithdrawalFunctionAdvised];
        yield 'ohne Widerrufsfunktion' => ['AT', 0, null];
    }

    /**
     * @dataProvider funktionNachSitz
     */
    public function testOhneSeiteFehltDieFunktion(string $country, int $days, ConfigurationFinding|null $expected): void
    {
        $this->connection->update('tl_consho_shop', ['country' => $country, 'withdrawalDays' => $days], ['id' => 1]);
        $this->connection->update('tl_page', ['consho_withdrawalFormPage' => 0], ['id' => 1]);

        $issues = array_values(array_filter($this->issues(), static fn (ConfigurationIssue $i): bool => \in_array($i->finding, [ConfigurationFinding::WithdrawalFunctionMissing, ConfigurationFinding::WithdrawalFunctionAdvised], true)));

        self::assertSame(null === $expected ? [] : [$expected], array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $issues));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, int}>
     */
    public static function unerreichbareFunktion(): iterable
    {
        yield 'Seite unveröffentlicht' => ['tl_page', ['published' => 0], 12];
        yield 'Artikel unveröffentlicht' => ['tl_article', ['published' => 0], 13];
        yield 'Element unsichtbar' => ['tl_content', ['invisible' => 1], 14];
        yield 'anderes Element' => ['tl_content', ['type' => 'text'], 14];

        // D-2: the first version checked neither protection nor time window.
        yield 'Seite geschützt, Gäste bestellen' => ['tl_page', ['protected' => 1], 12];
        yield 'Artikel abgelaufen' => ['tl_article', ['stop' => '1000'], 13];
        yield 'Element noch nicht sichtbar' => ['tl_content', ['start' => '9999999999'], 14];
    }

    /**
     * Without guest orders every buyer is logged in — he reads a protected
     * page, as with the legal texts.
     */
    public function testEineGeschuetzteSeiteGenuegtOhneGastbestellung(): void
    {
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);
        $this->connection->update('tl_page', ['protected' => 1], ['id' => 12]);

        self::assertSame([], $this->issues());
    }

    /**
     * @param array<string, mixed> $change
     *
     * @dataProvider unerreichbareFunktion
     */
    public function testEineUnerreichbareFunktionFehlt(string $table, array $change, int $id): void
    {
        $this->connection->update($table, $change, ['id' => $id]);

        self::assertSame('Shop DE', $this->only(ConfigurationFinding::WithdrawalFunctionMissing)->context['pages']);
    }

    public function testDasElementDarfEineEbeneTieferStehen(): void
    {
        $this->connection->update('tl_content', ['pid' => 15, 'ptable' => 'tl_content'], ['id' => 14]);
        $this->connection->insert('tl_content', ['id' => 15, 'pid' => 13, 'type' => 'element_group']);

        self::assertSame([], $this->issues());
    }

    // ── Rechtstexte ─────────────────────────────────────────────────────────

    public function testEinNichtGewaehlterRechtstextIstFehlendUndNichtUnerreichbar(): void
    {
        $this->connection->update('tl_page', ['consho_termsElement' => 0], ['id' => 1]);

        $issue = $this->only(ConfigurationFinding::LegalTextMissing);

        self::assertSame('terms', $issue->context['kinds']);
        self::assertSame(1, $issue->shopId);
    }

    /**
     * C-12: the backend shows the label from the language file, not the
     * internal key — "affected: terms" is what the operator read before.
     */
    public function testDieArtKommtMitIhrerBeschriftung(): void
    {
        $this->connection->update('tl_page', ['consho_termsElement' => 0], ['id' => 1]);
        $GLOBALS['TL_LANG']['CONSHO']['checkout']['legal']['terms'] = 'AGB';

        try {
            self::assertSame('AGB', $this->only(ConfigurationFinding::LegalTextMissing)->context['kinds']);
        } finally {
            unset($GLOBALS['TL_LANG']);
        }
    }

    /**
     * 🔴 The difference that justifies the two issues: here an element IS
     * chosen, it is just not readable. "Terms missing" would send the operator
     * to a field in which something visibly stands.
     */
    public function testEinUnsichtbaresElementIstUnerreichbarUndNichtFehlend(): void
    {
        $this->connection->update('tl_content', ['invisible' => 1], ['id' => 10]);

        $issue = $this->only(ConfigurationFinding::LegalTextUnreachable);

        self::assertSame('terms', $issue->context['kinds']);
    }

    public function testEinGeloeschtesElementIstUnerreichbar(): void
    {
        $this->connection->delete('tl_content', ['id' => 10]);

        self::assertSame('terms', $this->only(ConfigurationFinding::LegalTextUnreachable)->context['kinds']);
    }

    public function testEinElementVomFalschenTypIstUnerreichbar(): void
    {
        $this->connection->update('tl_content', ['type' => 'accordion'], ['id' => 10]);

        self::assertSame('terms', $this->only(ConfigurationFinding::LegalTextUnreachable)->context['kinds']);
    }

    public function testEineUnveroeffentlichteRechtstextSeiteIstUnerreichbar(): void
    {
        $this->connection->update('tl_page', ['published' => 0], ['id' => 3]);

        self::assertSame(ConfigurationFinding::LegalTextUnreachable, $this->only(ConfigurationFinding::LegalTextUnreachable)->finding);
    }

    /**
     * A legal text behind a login counts as not provided (§ 4 Abs. 1 FAGG:
     * "in a clear and comprehensible manner" — before the contract, not after
     * the login).
     */
    public function testEineGeschuetzteRechtstextSeiteIstUnerreichbar(): void
    {
        $this->connection->update('tl_page', ['protected' => 1], ['id' => 3]);

        self::assertSame(ConfigurationFinding::LegalTextUnreachable, $this->only(ConfigurationFinding::LegalTextUnreachable)->finding);
    }

    public function testBeideTexteWerdenInEinemBefundZusammengefasst(): void
    {
        // One issue per shop, not per text: they are set up in the same place
        // and fixed in one go.
        $this->connection->update('tl_page', ['consho_termsElement' => 0, 'consho_withdrawalElement' => 0], ['id' => 1]);

        self::assertSame('terms, withdrawal', $this->only(ConfigurationFinding::LegalTextMissing)->context['kinds']);
    }

    /**
     * 🔴 The most likely case of all: the terms text sits in an article that
     * is still a draft. The page does not show it, the checkout stayed open.
     */
    public function testEinUnveroeffentlichterArtikelMachtDenTextUnerreichbar(): void
    {
        $this->connection->update('tl_article', ['published' => 0], ['id' => 7]);

        self::assertSame('terms, withdrawal', $this->only(ConfigurationFinding::LegalTextUnreachable)->context['kinds']);
    }

    public function testEinElementAusserhalbSeinesFenstersIstUnerreichbar(): void
    {
        // The content element has start/stop fields of its own, not just
        // `invisible`.
        $this->connection->update('tl_content', ['stop' => (string) (time() - 60)], ['id' => 10]);

        self::assertSame('terms', $this->only(ConfigurationFinding::LegalTextUnreachable)->context['kinds']);
    }

    /**
     * ⚠️ `protected` only counts where guests may order. If guest orders are
     * off, every buyer is logged in and reads the page — a permanent block for
     * that would be an issue against a working shop, just as in C-5.
     */
    public function testEineGeschuetzteSeiteSperrtNichtWennNiemandAlsGastBestellt(): void
    {
        $this->connection->update('tl_page', ['protected' => 1], ['id' => 3]);
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);

        self::assertSame([], $this->issues());
    }

    public function testEineGeschuetzteSeiteSperrtSehrWohlBeiGastbestellung(): void
    {
        // The control case: with guest orders the buyer reads the terms before
        // logging in — or not at all.
        $this->connection->update('tl_page', ['protected' => 1], ['id' => 3]);

        self::assertSame(ConfigurationFinding::LegalTextUnreachable, $this->only(ConfigurationFinding::LegalTextUnreachable)->finding);
    }

    /**
     * The number in the sentence counts ROOT PAGES, not root page/text pairs.
     * With one root page missing two texts it used to read "2 (Shop DE)" —
     * two announced, one listed.
     */
    public function testDieZahlNenntStartpunkteUndNichtPaare(): void
    {
        $this->connection->update('tl_page', ['consho_termsElement' => 0, 'consho_withdrawalElement' => 0], ['id' => 1]);

        $issue = $this->only(ConfigurationFinding::LegalTextMissing);

        self::assertSame('1', $issue->context['count']);
        self::assertSame('Shop DE', $issue->context['pages']);
        self::assertSame('terms, withdrawal', $issue->context['kinds']);
    }

    // ── Rechnungsabsender ───────────────────────────────────────────────────

    public function testEinUnvollstaendigerRechnungsabsenderWirdGemeldet(): void
    {
        $this->connection->update('tl_consho_shop', ['invoiceStreet' => '', 'invoiceCity' => ''], ['id' => 1]);

        $issue = $this->only(ConfigurationFinding::InvoiceSenderIncomplete);

        self::assertSame('2', $issue->context['count']);
        self::assertSame('invoiceStreet, invoiceCity', $issue->context['fields']);
    }

    /**
     * The legal form is optional — in Austria and Germany it is mostly part of
     * the company name already, and requiring it twice would be wrong more
     * often than right.
     */
    public function testEineFehlendeRechtsformWirdNichtGemeldet(): void
    {
        self::assertSame([], $this->issues());
    }

    public function testEinLeerzeichenIstKeineAngabe(): void
    {
        $this->connection->update('tl_consho_shop', ['invoiceCity' => '   '], ['id' => 1]);

        self::assertSame('invoiceCity', $this->only(ConfigurationFinding::InvoiceSenderIncomplete)->context['fields']);
    }

    // ── Wer die Rechnung ausstellt (Block R, R19) ───────────────────────────

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function invoicing(): iterable
    {
        yield 'off' => ['off', true];
        yield 'a module that issues itself' => ['fake_books', true];
        yield 'a module that is not installed' => ['removed_books', true];
        yield 'a module that passes Consho\'s invoices on' => ['fake_books', false];
        yield 'Consho' => ['consho', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invoicing')]
    public function testBelegkreiseUndAbsenderNurWoConshoNummeriert(string $invoicing, bool $exempt): void
    {
        $this->provider = new FakeInvoiceProvider(issues: 'fake_books' === $invoicing && $exempt);
        $this->connection->update('tl_consho_shop', ['invoicing' => $invoicing, 'invoiceStreet' => ''], ['id' => 1]);
        $this->connection->delete('tl_consho_number_range', ['type' => 'invoice']);
        $this->connection->delete('tl_consho_number_range', ['type' => 'order']);

        $findings = array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $this->issues());

        // The order number stays Consho's whoever issues the invoice.
        self::assertSame($exempt ? 'order' : 'order, invoice', $this->only(ConfigurationFinding::NumberRangeMissing)->context['types']);
        self::assertSame(!$exempt, \in_array(ConfigurationFinding::InvoiceSenderIncomplete, $findings, true));
    }

    // ── Nummernkreise ───────────────────────────────────────────────────────

    /**
     * 🔴 EVERY shop that takes orders needs the order number range —
     * regardless of when a document is created.
     *
     * `OrderFactory` draws an order number on every purchase, and C-7 is the
     * first caller that ever did. If the range were missing, the visitor would
     * get a 500 after address, shipping and payment method — the readiness
     * exists precisely to say so beforehand.
     */
    public function testOhneBestellnummernkreisWirdGemeldet(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'order']);

        $issue = $this->only(ConfigurationFinding::NumberRangeMissing);

        self::assertSame('order', $issue->context['types']);
        self::assertSame(1, $issue->shopId);
    }

    public function testDerBestellnummernkreisFehltAuchOhneBelegAusloeser(): void
    {
        // The control case for the control case: invoice and cancellation depend
        // on the trigger, the order number does not. A shop that issues its
        // documents only on payment must still be able to take orders.
        $this->connection->delete('tl_consho_number_range', ['type' => 'order']);
        $this->connection->update('tl_consho_payment_method', ['type' => 'later'], ['id' => 9]);

        self::assertSame('order', $this->only(ConfigurationFinding::NumberRangeMissing)->context['types']);
    }

    public function testOhneRechnungsnummernkreisWirdGemeldet(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'invoice']);

        $issue = $this->only(ConfigurationFinding::NumberRangeMissing);

        self::assertSame('invoice', $issue->context['types']);
        self::assertSame(1, $issue->shopId);
        // Block I: the setup button reads the types from this issue.
        self::assertSame(['invoice'], NumberRangeSetup::missing(1, [$issue]));
    }

    /**
     * ⚠️ The cancellation range counts too, although nothing is cancelled in C:
     * as soon as an invoice can be created, the way back has to exist as well
     * (C-10, 15.2 "both number ranges without gaps"). Noticing that at the
     * cancellation would mean having an invoice that cannot be taken back.
     */
    public function testOhneStornoNummernkreisWirdEbenfallsGemeldet(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'cancellation']);

        self::assertSame('cancellation', $this->only(ConfigurationFinding::NumberRangeMissing)->context['types']);
    }

    /**
     * 🔴 E-2: until here it said "if no chosen payment method triggers at the
     * order, the shop needs nothing — the document is created later". Since
     * D-1 later means: on payment or on shipping, and then the document failed
     * for lack of a number. With "on invoice" it would have been the
     * operator's shipping report.
     */
    public function testAuchEinSpaeterAusloeserBrauchtRechnungUndStorno(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'invoice']);
        $this->connection->delete('tl_consho_number_range', ['type' => 'cancellation']);
        $this->connection->update('tl_consho_payment_method', ['type' => 'later'], ['id' => 9]);

        self::assertSame('invoice, cancellation', $this->only(ConfigurationFinding::NumberRangeMissing)->context['types']);
    }

    /**
     * The control case: a payment method whose module was removed issues
     * nothing and is not offered either — it requires no range.
     */
    public function testEineArtOhneModulVerlangtKeineBelegkreise(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'invoice']);
        $this->connection->delete('tl_consho_number_range', ['type' => 'cancellation']);
        $this->connection->update('tl_consho_payment_method', ['type' => 'uninstalled'], ['id' => 9]);

        self::assertNotContains(ConfigurationFinding::NumberRangeMissing, array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $this->issues()));
    }

    public function testEineUnveroeffentlichteZahlungsartLoestNichtAus(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'invoice']);
        $this->connection->update('tl_consho_payment_method', ['published' => 0], ['id' => 9]);

        self::assertSame([], $this->issues());
    }

    // ── Helfer ──────────────────────────────────────────────────────────────

    private function only(ConfigurationFinding $finding): ConfigurationIssue
    {
        $issues = array_values(array_filter($this->issues(), static fn (ConfigurationIssue $i): bool => $i->finding === $finding));

        self::assertCount(1, $issues, $finding->value.' genau einmal erwartet');

        return $issues[0];
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        $provider = $this->provider ?? new FakeInvoiceProvider();
        $policy = new InvoicingPolicy($this->connection, new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])));

        return (new DbalLegalReadinessIssueSource($this->connection, $this->handlers(), $policy))->issues();
    }

    /**
     * `later` stands for a document that is only created on payment or
     * shipping — Block D has such ones.
     */
    private function handlers(): PaymentHandlers
    {
        // The real prepayment handler for the main case: `documentTrigger()`
        // IS the decision the issue reads, and a double would only check
        // that the test guessed it right.
        $later = $this->createMock(PaymentHandlerInterface::class);
        $later->method('documentTrigger')->willReturn(DocumentTrigger::OnPayment);

        $services = ['prepayment' => new PrepaymentHandler(), 'later' => $later];

        $locator = new class($services) implements ServiceProviderInterface {
            /**
             * @param array<string, PaymentHandlerInterface> $services
             */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): PaymentHandlerInterface
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }

            public function getProvidedServices(): array
            {
                return array_map(static fn (): string => PaymentHandlerInterface::class, $this->services);
            }
        };

        return new PaymentHandlers($locator);
    }

    private function vollstaendig(): void
    {
        $this->connection->insert('tl_consho_shop', [
            'id' => 1, 'title' => 'Testshop', 'paymentMethods' => serialize(['9']),
            // Both texts required (D-2, E1): tick on, seat in the EU, period 14.
            'termsRequired' => 1, 'country' => 'AT', 'withdrawalDays' => 14,
            'invoiceCompany' => 'web.example.net', 'invoiceStreet' => 'Teststraße 1',
            'invoicePostal' => '1100', 'invoiceCity' => 'Wien', 'invoiceCountry' => 'AT',
        ]);

        $this->connection->insert('tl_page', [
            'id' => 1, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1,
            'consho_privacyPage' => 4, 'consho_warrantyPage' => 8,
            'consho_termsElement' => 10, 'consho_withdrawalElement' => 11,
            'consho_warrantyNotice' => self::NOTICE,
            'consho_withdrawalFormPage' => 12,
            'published' => 1,
        ]);

        // D-2: the "Withdraw from contract" page with its element.
        $this->connection->insert('tl_page', ['id' => 12, 'title' => 'Vertrag widerrufen', 'published' => 1]);
        $this->connection->insert('tl_article', ['id' => 13, 'pid' => 12]);
        $this->connection->insert('tl_content', ['id' => 14, 'pid' => 13, 'type' => 'consho_withdrawal']);
        $this->connection->insert('tl_files', ['id' => 20, 'uuid' => self::NOTICE, 'type' => 'file']);
        $this->connection->insert('tl_page', ['id' => 3, 'title' => 'Rechtliches', 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 4, 'title' => 'Datenschutz', 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 8, 'title' => 'Gewährleistung', 'published' => 1]);

        $this->connection->insert('tl_article', ['id' => 7, 'pid' => 3]);
        $this->connection->insert('tl_content', ['id' => 10, 'pid' => 7, 'type' => 'text']);
        $this->connection->insert('tl_content', ['id' => 11, 'pid' => 7, 'type' => 'text']);

        $this->connection->insert('tl_consho_payment_method', ['id' => 9, 'type' => 'prepayment', 'published' => 1]);
        // Three ranges: EVERY shop that takes orders needs `order` — invoice
        // and cancellation only where a chosen payment method already issues
        // the invoice at the order.
        $this->connection->insert('tl_consho_number_range', ['id' => 1, 'pid' => 1, 'type' => 'invoice']);
        $this->connection->insert('tl_consho_number_range', ['id' => 2, 'pid' => 1, 'type' => 'cancellation']);
        $this->connection->insert('tl_consho_number_range', ['id' => 3, 'pid' => 1, 'type' => 'order']);
    }
}
