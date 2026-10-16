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
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalLegalReadinessIssueSourceTest extends TestCase
{
    private const NOTICE = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    private Connection $connection;

    protected function setUp(): void
    {
        // Seit C-12 liest der Befund die Beschriftung aus TL_LANG; ohne
        // Sprachdaten fällt er auf den Schlüssel zurück. Andere Tests laden
        // die Sprachdateien und lassen sie stehen — hier gilt der Rückfall.
        unset($GLOBALS['TL_LANG']);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'regular',
             published INTEGER NOT NULL DEFAULT 1, protected INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '',
             consho_shop INTEGER NOT NULL DEFAULT 0, consho_privacyPage INTEGER NOT NULL DEFAULT 0, consho_warrantyPage INTEGER NOT NULL DEFAULT 0, consho_termsElement INTEGER NOT NULL DEFAULT 0, consho_withdrawalElement INTEGER NOT NULL DEFAULT 0,
             consho_withdrawalFormPage INTEGER NOT NULL DEFAULT 0,
             consho_warrantyNotice BLOB NULL)",
        );
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

    // ── Gewährleistungs-Mitteilung (§ 4 Abs 1 Z 12 FAGG, ab 27.09.2026) ─────

    /**
     * Die harmonisierte EU-Mitteilung zum gesetzlichen Gewährleistungsrecht.
     * Consho bringt die Grafik NICHT mit (Michael, 2026-09-22: „ich bin
     * dagegen, dass wir Rechtstexte setzen, aber alles technisch Notwendige
     * müssen wir bereitstellen") — es prüft, ob die Seite da und erreichbar
     * ist, und verlinkt sie im Checkout.
     *
     * Vorbild: ein Partnershop, dort am 2026-09-17 umgesetzt.
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
     * Die Gegenprobe zur gemeinsamen Abfrage: Datenschutz und Gewährleistung
     * laufen durch dieselbe Methode, dürfen aber nicht voneinander abhängen.
     */
    public function testDatenschutzUndGewaehrleistungWerdenGetrenntGemeldet(): void
    {
        $this->connection->update('tl_page', ['consho_warrantyPage' => 0], ['id' => 1]);

        $findings = array_map(static fn ($i): string => $i->finding->value, $this->issues());

        self::assertContains('warrantyNoticeMissing', $findings);
        self::assertNotContains('privacyPageMissing', $findings);
    }

    /**
     * C-9: Die Bestätigung muss die Mitteilung enthalten (Art. 8 Abs. 7 lit. a
     * VRRL, § 7 Abs 3 FAGG) — ohne Datei wäre jede Bestätigung unvollständig.
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
     * Seite und Datei sind zwei Felder und zwei Handgriffe — gemeldet wird,
     * was fehlt, nicht beides.
     */
    public function testSeiteUndDateiWerdenGetrenntGemeldet(): void
    {
        $this->connection->update('tl_page', ['consho_warrantyNotice' => null], ['id' => 1]);

        $findings = array_map(static fn ($i): string => $i->finding->value, $this->issues());

        self::assertContains('warrantyNoticeFileMissing', $findings);
        self::assertNotContains('warrantyNoticeMissing', $findings);
    }

    // ── Startpunkte, die niemanden bedienen ─────────────────────────────────

    /**
     * Dieselbe Regel wie bei der Abnahme von C-5: Ein unveröffentlichter
     * Startpunkt bedient niemanden, und ein Befund über ihn sperrte einen
     * Shop, der läuft.
     */
    public function testEinUnveroeffentlichterStartpunktWirdNichtGemeldet(): void
    {
        $this->connection->insert('tl_page', [
            'id' => 60, 'title' => 'Testpunkt', 'type' => 'root', 'published' => 0, 'consho_shop' => 1,
        ]);

        self::assertSame([], $this->issues());
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
        // Die Gegenprobe zu den beiden darüber: Er hat weder Datenschutzseite
        // noch Rechtstexte.
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
        // Gewählt und trotzdem unerreichbar — der Link ginge ins Leere.
        $this->connection->update('tl_page', ['published' => 0], ['id' => 4]);

        self::assertSame(ConfigurationFinding::PrivacyPageMissing, $this->only(ConfigurationFinding::PrivacyPageMissing)->finding);
    }

    // ── Welche Texte sperren (D-2, E1) ──────────────────────────────────────

    /**
     * Michael, 2026-09-27: „Sperre bei AGB ist schon falsch, man muss keine
     * haben. Sonst nur sperrend, wenn man in der EU Betreiber ist."
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

        // Frist 0: keine Funktion — dann fehlt auch das Element nicht.
        $issues = array_values(array_filter($this->issues(), static fn (ConfigurationIssue $i): bool => \in_array($i->finding, [ConfigurationFinding::LegalTextMissing, ConfigurationFinding::LegalTextAdvised], true)));

        self::assertSame(null === $expected ? [] : [$expected], array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $issues));
    }

    // ── Funktion „Vertrag widerrufen" (D-2, Spec D 10) ──────────────────────

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

        // Fable-Review D-2: die erste Fassung prüfte weder Schutz noch Zeitfenster.
        yield 'Seite geschützt, Gäste bestellen' => ['tl_page', ['protected' => 1], 12];
        yield 'Artikel abgelaufen' => ['tl_article', ['stop' => '1000'], 13];
        yield 'Element noch nicht sichtbar' => ['tl_content', ['start' => '9999999999'], 14];
    }

    /**
     * Ohne Gastbestellung ist jeder Käufer angemeldet — eine geschützte Seite
     * liest er, wie bei den Rechtstexten.
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
     * C-12: Im Backend steht die Beschriftung aus der Sprachdatei, nicht der
     * interne Schlüssel — „betroffen: terms" las der Betreiber vorher.
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
     * 🔴 Der Unterschied, der die beiden Befunde rechtfertigt: Hier IST ein
     * Element gewählt, es ist nur nicht lesbar. „AGB fehlen" schickte den
     * Betreiber an ein Feld, in dem sichtbar etwas steht.
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
     * Ein Rechtstext hinter einer Anmeldung gilt als nicht erteilt (§ 4 Abs. 1
     * FAGG: „in klarer und verständlicher Weise" — vor dem Vertrag, nicht nach
     * dem Login).
     */
    public function testEineGeschuetzteRechtstextSeiteIstUnerreichbar(): void
    {
        $this->connection->update('tl_page', ['protected' => 1], ['id' => 3]);

        self::assertSame(ConfigurationFinding::LegalTextUnreachable, $this->only(ConfigurationFinding::LegalTextUnreachable)->finding);
    }

    public function testBeideTexteWerdenInEinemBefundZusammengefasst(): void
    {
        // Ein Befund je Shop, nicht je Text: Sie werden am selben Ort
        // eingestellt und in einem Zug erledigt.
        $this->connection->update('tl_page', ['consho_termsElement' => 0, 'consho_withdrawalElement' => 0], ['id' => 1]);

        self::assertSame('terms, withdrawal', $this->only(ConfigurationFinding::LegalTextMissing)->context['kinds']);
    }

    /**
     * 🔴 Von beiden Reviews am 2026-09-22 gefunden, und der wahrscheinlichste
     * Fall überhaupt: Der AGB-Text liegt in einem Artikel, der noch im
     * Entwurf steht. Die Seite zeigt ihn nicht, der Checkout blieb offen.
     */
    public function testEinUnveroeffentlichterArtikelMachtDenTextUnerreichbar(): void
    {
        $this->connection->update('tl_article', ['published' => 0], ['id' => 7]);

        self::assertSame('terms, withdrawal', $this->only(ConfigurationFinding::LegalTextUnreachable)->context['kinds']);
    }

    public function testEinElementAusserhalbSeinesFenstersIstUnerreichbar(): void
    {
        // Das Inhaltselement hat eigene Start-/Stopp-Felder, nicht nur
        // `invisible`.
        $this->connection->update('tl_content', ['stop' => (string) (time() - 60)], ['id' => 10]);

        self::assertSame('terms', $this->only(ConfigurationFinding::LegalTextUnreachable)->context['kinds']);
    }

    /**
     * ⚠️ `protected` zählt nur, wo Gäste bestellen dürfen. Ist die
     * Gastbestellung aus, ist jeder Käufer angemeldet und liest die Seite —
     * eine Dauersperre dafür wäre ein Befund gegen einen funktionierenden
     * Shop, genau wie bei der Abnahme von C-5.
     */
    public function testEineGeschuetzteSeiteSperrtNichtWennNiemandAlsGastBestellt(): void
    {
        $this->connection->update('tl_page', ['protected' => 1], ['id' => 3]);
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);

        self::assertSame([], $this->issues());
    }

    public function testEineGeschuetzteSeiteSperrtSehrWohlBeiGastbestellung(): void
    {
        // Die Gegenprobe: mit Gastbestellung liest der Käufer die AGB vor dem
        // Anmelden — oder eben gar nicht.
        $this->connection->update('tl_page', ['protected' => 1], ['id' => 3]);

        self::assertSame(ConfigurationFinding::LegalTextUnreachable, $this->only(ConfigurationFinding::LegalTextUnreachable)->finding);
    }

    /**
     * Die Zahl im Satz nennt STARTPUNKTE, nicht Startpunkt-Text-Paare. Bei
     * einem Startpunkt mit zwei fehlenden Texten stand vorher „2 (Shop DE)" —
     * zwei angekündigt, einer aufgezählt (Review 2026-09-22).
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
     * Die Rechtsform ist freiwillig — in Österreich und Deutschland steckt sie
     * meist schon im Firmenwortlaut, und sie zweimal zu verlangen wäre öfter
     * falsch als richtig.
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

    // ── Nummernkreise ───────────────────────────────────────────────────────

    /**
     * 🔴 Den Bestellnummernkreis braucht JEDER Shop, der bestellen lässt —
     * unabhängig davon, wann ein Beleg entsteht (F4, 2026-09-23).
     *
     * `OrderFactory` zieht bei jedem Kauf eine Bestellnummer, und C-7 ist der
     * erste Aufrufer, der das je getan hat. Fehlte der Kreis, bekäme der
     * Besucher nach Adresse, Versand- und Zahlungsart einen 500er — die
     * Bereitschaft ist genau dafür da, das vorher zu sagen.
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
        // Die Gegenprobe zur Gegenprobe: Rechnung und Storno hängen am
        // Auslöser, die Bestellnummer nicht. Ein Shop, der seine Belege erst
        // bei Zahlung ausstellt, muss trotzdem bestellen können.
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
        // Block I: der Knopf der Einrichtung liest die Typen aus diesem Befund.
        self::assertSame(['invoice'], NumberRangeSetup::missing(1, [$issue]));
    }

    /**
     * ⚠️ Der Storno-Kreis zählt mit, obwohl in C nichts storniert wird: Sobald
     * eine Rechnung entstehen kann, muss auch der Weg zurück bestehen (C-10,
     * 15.2 „beide Nummernkreise lückenlos"). Das beim Storno zu bemerken hieße,
     * eine Rechnung zu haben, die sich nicht zurücknehmen lässt.
     */
    public function testOhneStornoNummernkreisWirdEbenfallsGemeldet(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'cancellation']);

        self::assertSame('cancellation', $this->only(ConfigurationFinding::NumberRangeMissing)->context['types']);
    }

    /**
     * 🔴 E-2: Bis hierher hieß es „löst keine gewählte Zahlungsart bei der
     * Bestellung aus, braucht der Shop nichts — der Beleg entsteht dann
     * später". Später heißt seit D-1: bei Zahlung oder beim Versand, und
     * dann scheiterte der Beleg an der fehlenden Nummer. Mit „Auf Rechnung"
     * wäre es die Versandmeldung des Betreibers gewesen.
     */
    public function testAuchEinSpaeterAusloeserBrauchtRechnungUndStorno(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'invoice']);
        $this->connection->delete('tl_consho_number_range', ['type' => 'cancellation']);
        $this->connection->update('tl_consho_payment_method', ['type' => 'later'], ['id' => 9]);

        self::assertSame('invoice, cancellation', $this->only(ConfigurationFinding::NumberRangeMissing)->context['types']);
    }

    /**
     * Die Gegenprobe: Eine Zahlungsart, deren Modul entfernt wurde, stellt
     * nichts aus und wird auch nicht angeboten — sie verlangt keinen Kreis.
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
        return (new DbalLegalReadinessIssueSource($this->connection, $this->handlers()))->issues();
    }

    /**
     * `later` steht für einen Beleg, der erst bei Zahlung oder Versand
     * entsteht — in Block D gibt es solche.
     */
    private function handlers(): PaymentHandlers
    {
        // Der echte Vorkasse-Handler für den Hauptfall: `documentTrigger()`
        // IST die Entscheidung, die der Befund liest, und ein Doppel prüfte
        // nur, dass der Test sie richtig erraten hat.
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
            // Beide Texte verlangt (D-2, E1): Häkchen an, Sitz in der EU, Frist 14.
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
        ]);

        // D-2: die Seite „Vertrag widerrufen" mit ihrem Element.
        $this->connection->insert('tl_page', ['id' => 12, 'title' => 'Vertrag widerrufen']);
        $this->connection->insert('tl_article', ['id' => 13, 'pid' => 12]);
        $this->connection->insert('tl_content', ['id' => 14, 'pid' => 13, 'type' => 'consho_withdrawal']);
        $this->connection->insert('tl_files', ['id' => 20, 'uuid' => self::NOTICE, 'type' => 'file']);
        $this->connection->insert('tl_page', ['id' => 3, 'title' => 'Rechtliches']);
        $this->connection->insert('tl_page', ['id' => 4, 'title' => 'Datenschutz']);
        $this->connection->insert('tl_page', ['id' => 8, 'title' => 'Gewährleistung']);

        $this->connection->insert('tl_article', ['id' => 7, 'pid' => 3]);
        $this->connection->insert('tl_content', ['id' => 10, 'pid' => 7, 'type' => 'text']);
        $this->connection->insert('tl_content', ['id' => 11, 'pid' => 7, 'type' => 'text']);

        $this->connection->insert('tl_consho_payment_method', ['id' => 9, 'type' => 'prepayment', 'published' => 1]);
        // Drei Kreise: `order` braucht JEDER Shop, der bestellen lässt (F4,
        // 2026-09-23) — Rechnung und Storno nur dort, wo eine gewählte
        // Zahlungsart die Rechnung schon bei der Bestellung ausstellt.
        $this->connection->insert('tl_consho_number_range', ['id' => 1, 'pid' => 1, 'type' => 'invoice']);
        $this->connection->insert('tl_consho_number_range', ['id' => 2, 'pid' => 1, 'type' => 'cancellation']);
        $this->connection->insert('tl_consho_number_range', ['id' => 3, 'pid' => 1, 'type' => 'order']);
    }
}
