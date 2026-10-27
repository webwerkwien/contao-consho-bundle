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
        // Every query is recorded — only that way can it be shown that a
        // single-language shop triggers not a single query on products.
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

        // From the DCA, so as strict as the database: the safety fields may be
        // NULL, the assignment and the translation are unique.
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_translation');

        DcaSchema::createCore($this->connection, 'tl_page');

        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->shop(1, 'Kräuterhaus', 'de', ['de', 'en']);
    }

    // ── What is reported ─────────────────────────────────────────────────

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
         * A row without an alias comes about, for instance, when restoring a
         * version whose alias has been taken in the meantime (M-5). The product
         * then has no address in that language — it is missing there. A message
         * about safety notices would send the operator to the wrong place.
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
        // The finding this report exists for: published, translated
        // — and invisible all the same.
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
        // The default language is deliberately not at the front of the
        // alphabet: it must never be reported, wherever it is in the list.
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

    // ── What is not reported ─────────────────────────────────────────────

    public function testAnUnpublishedProductIsNotReported(): void
    {
        // A draft is not a gap. Otherwise the report drowns in noise, and
        // nobody reads it any more — and the warning goes under with it.
        $this->product(1, published: false);

        self::assertSame([], $this->report());
    }

    public function testAProductOutsideItsPublicationWindowIsNotReported(): void
    {
        // Deliberately invisible, in every language — the same exception as B-8.
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
         * Variants inherit the texts of the main product and have no translation
         * row of their own. The main product is counted — once, not once per
         * size. Otherwise every shop with variants would report a multiple of
         * what there is to translate.
         */
        $this->product(1);
        // With a shop selection of its own, as a restricting variant carries:
        // without it, a variant counted along would already drop out at the
        // assignment, and the test would not notice if it were counted.
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
        // Already invisible in the default language — no translation makes it
        // visible, so the missing translation is not the reason either.
        $this->product(1);
        $this->product(2, pid: 1, published: false, shops: []);

        self::assertSame([], $this->report());
    }

    public function testAMonolingualShopReportsNothingAndReadsNoProduct(): void
    {
        // The criterion of the whole specification: for a
        // single-language shop nothing changes — not even on the backend start
        // page, which runs this on every request.
        $this->connection->executeStatement("DELETE FROM tl_page WHERE language = 'en'");
        $this->product(1, safetyWarnings: 'Enthält Alkohol');

        $this->queries->exchangeArray([]);

        self::assertSame([], $this->report());
        self::assertSame([], array_values(array_filter(
            $this->queries->getArrayCopy(),
            static fn (string $sql): bool => str_contains($sql, 'tl_consho_product'),
        )));
    }

    // ── Agreement with visibility ────────────────────────────────────────

    public function testTheReportAgreesWithWhatTheFrontEndHides(): void
    {
        /*
         * 🔴 A report that says "missing" where the frontend shows the product —
         * or stays silent where it is missing — is worse than none. Hence every
         * case VisibilityCriteria knows is here, and the yardstick is the
         * criteria themselves: reported is exactly what is visible in the default
         * language and invisible in the translation language.
         */
        $this->product(1);                                                          // no row
        $this->product(2);
        $this->translate(2, 'en', alias: 'two');                                    // translated
        $this->product(3);
        $this->translate(3, 'en', alias: null);                                     // row without alias
        $this->product(4, safetyWarnings: 'W');
        $this->translate(4, 'en', alias: 'four');                                   // warning notice missing
        $this->product(5, safetyInformation: 'I');
        $this->translate(5, 'en', alias: 'five', safetyWarnings: 'x');              // information missing
        $this->product(6, safetyWarnings: 'W', safetyInformation: 'I');
        $this->translate(6, 'en', alias: 'six', safetyWarnings: 'w', safetyInformation: 'i'); // complete
        $this->product(7, safetyWarnings: 'W');                                     // no row, warning notice
        $this->product(8, published: false);                                        // draft
        $this->product(9, stop: self::NOW - 1);                                     // expired
        $this->product(10, shops: [2]);                                             // other shop
        $this->product(11);
        $this->product(12, pid: 11, published: false, shops: []);                  // only inactive variants
        $this->product(13);
        $this->product(14, pid: 13, shops: [1]);                                    // variant with a selection of its own, main product missing
        $this->product(15, safetyWarnings: 'W');
        $this->translate(15, 'en', alias: 'fifteen', safetyWarnings: 'w');
        $this->product(16, pid: 15, shops: []);                                     // variant, everything translated
        $this->product(17, safetyWarnings: 'W');
        $this->translate(17, 'en', alias: '');                                      // empty alias

        $hidden = array_values(array_diff($this->visible('de'), $this->visible('en')));
        $withAddress = array_map(intval(...), $this->connection->fetchFirstColumn(
            "SELECT pid FROM tl_consho_translation WHERE language = 'en' AND alias IS NOT NULL AND alias != ''",
        ));

        // The yardstick must not be empty, otherwise every report agrees with it.
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

    // ── Helpers ──────────────────────────────────────────────────────────

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
            // 0 remains the test parameter for "not set", but the column stores
            // '' for it — just like the real DB.
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
