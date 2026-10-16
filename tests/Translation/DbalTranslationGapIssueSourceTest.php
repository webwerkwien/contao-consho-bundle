<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Translation\DbalTranslationGapIssueSource;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalTranslationGapIssueSourceTest extends TestCase
{
    private const NOW = 1_700_000_000;

    private Connection $connection;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $queries;

    protected function setUp(): void
    {
        // Mitgeschrieben wird jede Abfrage — nur so lässt sich belegen, dass
        // ein einsprachiger Shop keine einzige Abfrage auf Produkte auslöst.
        $this->queries = new \ArrayObject();
        $logger = new class($this->queries) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $queries */
            public function __construct(private readonly \ArrayObject $queries)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries->append((string) $context['sql']);
                }
            }
        };

        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new Middleware($logger)]),
        );

        // Aus der DCA, also so streng wie die Datenbank: Die Sicherheitsfelder
        // dürfen NULL sein, die Zuordnung und die Übersetzung sind eindeutig.
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_translation');

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                consho_currency TEXT NOT NULL DEFAULT ''
            )",
        );

        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->shop(1, 'Kräuterhaus', 'de', ['de', 'en']);
    }

    // ── Was gemeldet wird ────────────────────────────────────────────────

    public function testAProductWithoutATranslationIsReportedAsMissing(): void
    {
        $this->product(1);

        self::assertSame(
            [['translationMissing', ['shop' => 'Kräuterhaus', 'language' => 'en', 'count' => '1']]],
            $this->report(),
        );
        self::assertFalse(ConfigurationFinding::TranslationMissing->isWarning());
    }

    public function testATranslatedProductIsNotReported(): void
    {
        $this->product(1);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame([], $this->report());
    }

    public function testATranslationRowWithoutAnAliasCountsAsMissing(): void
    {
        /*
         * Eine Zeile ohne Alias entsteht etwa beim Zurückholen einer Version,
         * wenn der Alias inzwischen vergeben ist (M-5). Das Produkt hat dann
         * keine Adresse in dieser Sprache — es fehlt dort. Eine Meldung über
         * Sicherheitshinweise schickte den Betreiber an die falsche Stelle.
         */
        $this->product(1);
        $this->translate(1, 'en', alias: null);
        $this->product(2);
        $this->translate(2, 'en', alias: '');

        self::assertSame(
            [['translationMissing', ['shop' => 'Kräuterhaus', 'language' => 'en', 'count' => '2']]],
            $this->report(),
        );
    }

    public function testMissingSafetyWarningsAreAWarning(): void
    {
        // Der Befund, für den es diesen Bericht gibt: veröffentlicht, übersetzt
        // — und trotzdem unsichtbar.
        $this->product(1, safetyWarnings: 'Enthält Alkohol');
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame(
            [['translationSafetyMissing', ['shop' => 'Kräuterhaus', 'language' => 'en', 'count' => '1']]],
            $this->report(),
        );
        self::assertTrue(ConfigurationFinding::TranslationSafetyMissing->isWarning());
    }

    public function testMissingSafetyInformationIsAWarningToo(): void
    {
        $this->product(1, safetyInformation: 'Kühl lagern');
        $this->translate(1, 'en', alias: 'herbal-bitters', safetyWarnings: '');

        self::assertSame(
            [['translationSafetyMissing', ['shop' => 'Kräuterhaus', 'language' => 'en', 'count' => '1']]],
            $this->report(),
        );
    }

    public function testTranslatedSafetyNoticesAreNotReported(): void
    {
        $this->product(1, safetyWarnings: 'Enthält Alkohol', safetyInformation: 'Kühl lagern');
        $this->translate(1, 'en', alias: 'herbal-bitters', safetyWarnings: 'Contains alcohol', safetyInformation: 'Keep cool');

        self::assertSame([], $this->report());
    }

    public function testBothKindsAreCountedSeparately(): void
    {
        $this->product(1);
        $this->product(2);
        $this->product(3, safetyWarnings: 'Enthält Alkohol');
        $this->translate(3, 'en', alias: 'herbal-bitters');

        self::assertSame(
            [
                ['translationSafetyMissing', ['shop' => 'Kräuterhaus', 'language' => 'en', 'count' => '1']],
                ['translationMissing', ['shop' => 'Kräuterhaus', 'language' => 'en', 'count' => '2']],
            ],
            $this->report(),
        );
    }

    public function testEachTranslationLanguageIsReportedOnItsOwn(): void
    {
        // Die Standardsprache steht absichtlich nicht vorn im Alphabet: Sie
        // darf nie gemeldet werden, gleich wo sie in der Liste steht.
        $this->connection->executeStatement('DELETE FROM tl_page');
        $this->connection->executeStatement('DELETE FROM tl_consho_shop');
        $this->shop(1, 'Kräuterhaus', 'en', ['de', 'en', 'fr']);

        $this->product(1);
        $this->translate(1, 'fr', alias: 'amer-aux-herbes');
        $this->product(2);

        self::assertSame(
            [
                ['translationMissing', ['shop' => 'Kräuterhaus', 'language' => 'de', 'count' => '2']],
                ['translationMissing', ['shop' => 'Kräuterhaus', 'language' => 'fr', 'count' => '1']],
            ],
            $this->report(),
        );
    }

    public function testEachShopIsReportedOnItsOwn(): void
    {
        $this->shop(2, 'Teehaus', 'de', ['de', 'it']);

        $this->product(1);
        $this->product(2, shops: [2]);

        self::assertSame(
            [
                ['translationMissing', ['shop' => 'Kräuterhaus', 'language' => 'en', 'count' => '1']],
                ['translationMissing', ['shop' => 'Teehaus', 'language' => 'it', 'count' => '1']],
            ],
            $this->report(),
        );
    }

    // ── Was nicht gemeldet wird ──────────────────────────────────────────

    public function testAnUnpublishedProductIsNotReported(): void
    {
        // Ein Entwurf ist keine Lücke. Sonst ertrinkt der Bericht in Rauschen,
        // und niemand liest ihn mehr — womit auch die Warnung untergeht.
        $this->product(1, published: false);

        self::assertSame([], $this->report());
    }

    public function testAProductOutsideItsPublicationWindowIsNotReported(): void
    {
        // Absichtlich unsichtbar, in jeder Sprache — dieselbe Ausnahme wie B-8.
        $this->product(1, stop: self::NOW - 1);
        $this->product(2, start: self::NOW + 100);

        self::assertSame([], $this->report());
    }

    public function testAProductOfNoShopIsNotReportedHere(): void
    {
        $this->product(1, shops: []);

        self::assertSame([], $this->report());
    }

    public function testVariantsAreNotCountedOnTheirOwn(): void
    {
        /*
         * Varianten erben die Texte des Hauptprodukts und haben keine eigene
         * Übersetzungszeile. Gezählt wird das Hauptprodukt — einmal, nicht
         * einmal je Größe. Sonst meldete jeder Shop mit Varianten ein
         * Vielfaches dessen, was zu übersetzen ist.
         */
        $this->product(1);
        // Mit eigener Shop-Auswahl, wie sie eine eingrenzende Variante trägt:
        // Ohne sie fiele eine mitgezählte Variante schon an der Zuordnung
        // heraus, und der Test merkte nicht, wenn sie mitgezählt würde.
        $this->product(2, pid: 1, shops: [1]);
        $this->product(3, pid: 1, shops: [1]);

        $this->product(4);
        $this->translate(4, 'en', alias: 'herbal-bitters');
        $this->product(5, pid: 4, shops: []);

        self::assertSame(
            [['translationMissing', ['shop' => 'Kräuterhaus', 'language' => 'en', 'count' => '1']]],
            $this->report(),
        );
    }

    public function testAMainProductWithOnlyInactiveVariantsIsNotReported(): void
    {
        // Schon in der Standardsprache unsichtbar — keine Übersetzung macht es
        // sichtbar, also ist die fehlende Übersetzung auch nicht der Grund.
        $this->product(1);
        $this->product(2, pid: 1, published: false, shops: []);

        self::assertSame([], $this->report());
    }

    public function testAMonolingualShopReportsNothingAndReadsNoProduct(): void
    {
        // Die Prüfbedingung der ganzen Spezifikation: Für einen einsprachigen
        // Shop ändert sich nichts — auch nicht auf der Backend-Startseite, die
        // das bei jedem Aufruf ausführt.
        $this->connection->executeStatement("DELETE FROM tl_page WHERE language = 'en'");
        $this->product(1, safetyWarnings: 'Enthält Alkohol');

        $this->queries->exchangeArray([]);

        self::assertSame([], $this->report());
        self::assertSame([], array_values(array_filter(
            $this->queries->getArrayCopy(),
            static fn (string $sql): bool => str_contains($sql, 'tl_consho_product'),
        )));
    }

    // ── Übereinstimmung mit der Sichtbarkeit ─────────────────────────────

    public function testTheReportAgreesWithWhatTheFrontEndHides(): void
    {
        /*
         * 🔴 Ein Bericht, der „fehlt" sagt, wo das Frontend das Produkt zeigt —
         * oder schweigt, wo es fehlt —, ist schlimmer als keiner. Deshalb hier
         * jeder Fall, den VisibilityCriteria kennt, und als Maßstab die
         * Kriterien selbst: gemeldet wird genau, was in der Standardsprache
         * sichtbar und in der Übersetzungssprache unsichtbar ist.
         */
        $this->product(1);                                                          // keine Zeile
        $this->product(2);
        $this->translate(2, 'en', alias: 'two');                                    // übersetzt
        $this->product(3);
        $this->translate(3, 'en', alias: null);                                     // Zeile ohne Alias
        $this->product(4, safetyWarnings: 'W');
        $this->translate(4, 'en', alias: 'four');                                   // Warnhinweis fehlt
        $this->product(5, safetyInformation: 'I');
        $this->translate(5, 'en', alias: 'five', safetyWarnings: 'x');              // Information fehlt
        $this->product(6, safetyWarnings: 'W', safetyInformation: 'I');
        $this->translate(6, 'en', alias: 'six', safetyWarnings: 'w', safetyInformation: 'i'); // vollständig
        $this->product(7, safetyWarnings: 'W');                                     // keine Zeile, Warnhinweis
        $this->product(8, published: false);                                        // Entwurf
        $this->product(9, stop: self::NOW - 1);                                     // abgelaufen
        $this->product(10, shops: [2]);                                             // anderer Shop
        $this->product(11);
        $this->product(12, pid: 11, published: false, shops: []);                  // nur inaktive Varianten
        $this->product(13);
        $this->product(14, pid: 13, shops: [1]);                                    // Variante mit eigener Auswahl, Hauptprodukt fehlt
        $this->product(15, safetyWarnings: 'W');
        $this->translate(15, 'en', alias: 'fifteen', safetyWarnings: 'w');
        $this->product(16, pid: 15, shops: []);                                     // Variante, alles übersetzt
        $this->product(17, safetyWarnings: 'W');
        $this->translate(17, 'en', alias: '');                                      // leerer Alias

        $hidden = array_values(array_diff($this->visible('de'), $this->visible('en')));
        $withAddress = array_map(intval(...), $this->connection->fetchFirstColumn(
            "SELECT pid FROM tl_consho_translation WHERE language = 'en' AND alias IS NOT NULL AND alias != ''",
        ));

        // Der Maßstab darf nicht leer sein, sonst stimmt jeder Bericht mit ihm überein.
        self::assertSame([1, 3, 4, 5, 7, 13, 17], $hidden);

        $counts = [];

        foreach ($this->report() as [$finding, $context]) {
            $counts[$finding] = (int) $context['count'];
        }

        self::assertSame(
            [
                'translationSafetyMissing' => \count(array_intersect($hidden, $withAddress)),
                'translationMissing' => \count(array_diff($hidden, $withAddress)),
            ],
            $counts,
        );
    }

    // ── Helfer ───────────────────────────────────────────────────────────

    /**
     * @return list<array{string, array<string, string>}>
     */
    private function report(): array
    {
        $source = new DbalTranslationGapIssueSource(
            $this->connection,
            new ShopLanguages($this->connection),
            new VisibilityCriteria(),
            static fn (): int => self::NOW,
        );

        return array_map(
            static fn (ConfigurationIssue $issue): array => [$issue->finding->value, $issue->context],
            $source->issues(),
        );
    }

    /**
     * @return list<int>
     */
    private function visible(string $language, int $shop = 1, string $fallback = 'de'): array
    {
        $condition = (new VisibilityCriteria())->mainProduct($shop, self::NOW, 'p', $language, $fallback);

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT p.id FROM tl_consho_product p WHERE '.$condition->sql.' ORDER BY p.id',
            $condition->params,
        ));
    }

    /**
     * @param list<string> $languages
     */
    private function shop(int $id, string $title, string $fallback, array $languages): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'title' => $title, 'fallbackLanguage' => $fallback]);

        foreach ($languages as $language) {
            $this->connection->insert('tl_page', ['type' => 'root', 'language' => $language, 'consho_shop' => $id]);
        }
    }

    /**
     * @param list<int> $shops
     */
    private function product(
        int $id,
        int $pid = 0,
        bool $published = true,
        int $start = 0,
        int $stop = 0,
        string $safetyWarnings = '',
        string $safetyInformation = '',
        array $shops = [1],
    ): void {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => $pid,
            'title' => 'Produkt '.$id,
            'published' => $published ? 1 : 0,
            // 0 bleibt der Testparameter für "nicht gesetzt", die Spalte
            // speichert dafür aber '' — genau wie die echte DB.
            'start' => $start > 0 ? (string) $start : '',
            'stop' => $stop > 0 ? (string) $stop : '',
            'safetyWarnings' => $safetyWarnings,
            'safetyInformation' => $safetyInformation,
        ]);

        foreach ($shops as $shop) {
            $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => $shop]);
        }
    }

    private function translate(int $pid, string $language, ?string $alias, string $safetyWarnings = '', string $safetyInformation = ''): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => $pid,
            'ptable' => 'tl_consho_product',
            'language' => $language,
            'alias' => $alias,
            'safetyWarnings' => $safetyWarnings,
            'safetyInformation' => $safetyInformation,
        ]);
    }
}
