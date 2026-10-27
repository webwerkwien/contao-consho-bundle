<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\CoreBundle\Slug\Slug;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldCodec;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldDisplay;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldValues;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldView;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\CustomFieldTranslations;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationWriter;

/**
 * ZW-4 (Z1–Z3): what the
 * product page shows under "Angaben" (details) — only filled fields, in the
 * order of the type, title and text in the language of the page, number and
 * date formatted by language, the variant with its inheritance.
 */
class CustomFieldDisplayTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $queries;

    /** @var array<string, mixed>|null */
    private array|null $savedLang = null;

    protected function setUp(): void
    {
        $this->queries = new QueryCounter();
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new Middleware($this->queries)]));

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_custom_field', 'tl_consho_shop', 'tl_consho_product_shop', 'tl_consho_translation'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_consho_shop', ['id' => 7, 'fallbackLanguage' => 'de']);
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => 7, 'language' => 'de']);
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => 7, 'language' => 'fr']);

        $this->connection->insert('tl_consho_custom_field', ['id' => 1, 'alias' => 'jahrgang', 'title' => 'Jahrgang', 'type' => 'number']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 2, 'alias' => 'rebsorte', 'title' => 'Rebsorte', 'type' => 'text']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 3, 'alias' => 'bio', 'title' => 'Bio', 'type' => 'boolean']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 4, 'alias' => 'abfuellung', 'title' => 'Abfüllung', 'type' => 'date']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 5, 'alias' => 'lage', 'title' => 'Lage', 'type' => 'text']);
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'customFields' => serialize(['2', '1', '3', '4', '5'])]);

        $this->connection->insert('tl_consho_product', ['id' => 10, 'pid' => 0, 'productType' => 1, 'customFieldValues' => '{"rebsorte":"Grüner Veltliner","jahrgang":"1234.5","bio":"0","abfuellung":"2024-03-01","lage":"Ried Klaus"}']);
        $this->connection->insert('tl_consho_product', ['id' => 11, 'pid' => 10, 'productType' => 0, 'customFieldValues' => '{"lage":"Ried Achleiten"}']);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 10, 'shop' => 7]);

        // French: the field title, and the texts of the main product.
        $this->connection->insert('tl_consho_translation', ['ptable' => 'tl_consho_custom_field', 'pid' => 2, 'language' => 'fr', 'title' => 'Cépage']);
        $this->connection->insert('tl_consho_translation', ['ptable' => 'tl_consho_product', 'pid' => 10, 'language' => 'fr', 'customFieldValues' => '{"rebsorte":"Grüner Veltliner (fr)","lage":"Lieu-dit Klaus"}']);

        $this->savedLang = $GLOBALS['TL_LANG'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TL_LANG'] = $this->savedLang;
    }

    public function testInDerStandardspracheMitFormat(): void
    {
        $views = $this->display()->forArticle(10, 'de', 'de');

        self::assertSame(['rebsorte', 'jahrgang', 'bio', 'abfuellung', 'lage'], array_map(static fn (CustomFieldView $v): string => $v->alias, $views));
        self::assertSame(['Grüner Veltliner', '1234,5', '', '01.03.2024', 'Ried Klaus'], array_map(static fn (CustomFieldView $v): string => $v->formatted, $views));
        self::assertFalse($views[2]->yes, 'Nein ist ein Wert, kein leeres Feld');
    }

    /**
     * A vintage is not a number with thousands separators, and a whole number
     * gets no decimal places added.
     */
    public function testEinJahrgangBleibtEinJahrgang(): void
    {
        $this->connection->update('tl_consho_product', ['customFieldValues' => '{"jahrgang":"2021"}'], ['id' => 10]);

        self::assertSame('2021', $this->display()->forArticle(10, 'de', 'de')[0]->formatted);

        // The given decimal places stay, a trailing zero too.
        $this->connection->update('tl_consho_product', ['customFieldValues' => '{"jahrgang":"0.250"}'], ['id' => 10]);
        self::assertSame('0,250', $this->display()->forArticle(10, 'de', 'de')[0]->formatted);
    }

    /**
     * Beyond 15 digits a floating-point number rounds. The
     * digits stay as they were entered — only the decimal separator is that of
     * the language.
     */
    public function testEineLangeZahlVerliertKeineStelle(): void
    {
        $this->connection->update('tl_consho_product', ['customFieldValues' => '{"jahrgang":"9007199254740993"}'], ['id' => 10]);
        self::assertSame('9007199254740993', $this->display()->forArticle(10, 'de', 'de')[0]->formatted);

        $this->connection->update('tl_consho_product', ['customFieldValues' => '{"jahrgang":"-123456789012345.678"}'], ['id' => 10]);
        self::assertSame('-123456789012345,678', $this->display()->forArticle(10, 'de', 'de')[0]->formatted);
    }

    /**
     * An impossible date — the value dates from the
     * time when the field was a text field — does not silently become 1 March.
     */
    public function testEinUnmoeglichesDatumBleibtStehen(): void
    {
        $this->connection->update('tl_consho_product', ['customFieldValues' => '{"abfuellung":"2024-02-30"}'], ['id' => 10]);

        self::assertSame('2024-02-30', $this->display()->forArticle(10, 'de', 'de')[0]->formatted);
    }

    /**
     * ZW-K: the field used to be a text field. The old value
     * is shown as it is — not as "0".
     */
    public function testEinAlterTextUnterEinemZahlenfeld(): void
    {
        $this->connection->update('tl_consho_product', ['customFieldValues' => '{"jahrgang":"zweitausend"}'], ['id' => 10]);

        self::assertSame('zweitausend', $this->display()->forArticle(10, 'de', 'de')[0]->formatted);
    }

    /**
     * ZW-C: the catalogue JSON asks per item. A type without
     * custom fields costs two queries (type, fields of the type) — the values
     * are not read at all, even if old ones are still there.
     */
    public function testEinTypOhneZusatzfelderKostetZweiAbfragen(): void
    {
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'customFields' => '']);
        $this->connection->insert('tl_consho_product', ['id' => 30, 'pid' => 0, 'productType' => 2, 'customFieldValues' => '{"jahrgang":"2021"}']);
        $display = $this->display();
        $this->queries->count = 0;

        self::assertSame([], $display->forArticle(30, 'fr', 'de'));
        self::assertSame(2, $this->queries->count);
    }

    public function testNurBefuellteFelder(): void
    {
        $this->connection->update('tl_consho_product', ['customFieldValues' => '{"jahrgang":"2021"}'], ['id' => 10]);

        self::assertSame(['jahrgang'], array_map(static fn (CustomFieldView $v): string => $v->alias, $this->display()->forArticle(10, 'de', 'de')));
        self::assertSame([], $this->display()->forArticle(99, 'de', 'de'));
    }

    /**
     * Z1: title and text translated, otherwise fallback to the default language;
     * number and date by the language of the page.
     */
    public function testInDerUebersetzung(): void
    {
        $views = $this->byAlias($this->display()->forArticle(10, 'fr', 'de'));

        self::assertSame(['Cépage', 'Grüner Veltliner (fr)'], [$views['rebsorte']->title, $views['rebsorte']->formatted]);
        self::assertSame(['Jahrgang', '1234,5'], [$views['jahrgang']->title, $views['jahrgang']->formatted], 'ohne Übersetzung der deutsche Titel');
        self::assertSame('1 mars 2024', $views['abfuellung']->formatted);
    }

    /**
     * Z2: the variant inherits; the French translation of the main product
     * translates its text — not the one the variant overrides.
     */
    public function testDieVarianteUndIhreUebersetzung(): void
    {
        $de = $this->byAlias($this->display()->forArticle(11, 'de', 'de'));
        self::assertSame(['Grüner Veltliner', 'Ried Achleiten'], [$de['rebsorte']->formatted, $de['lage']->formatted]);

        $fr = $this->byAlias($this->display()->forArticle(11, 'fr', 'de'));
        self::assertSame('Grüner Veltliner (fr)', $fr['rebsorte']->formatted, 'geerbt samt Übersetzung');
        self::assertSame('Ried Achleiten', $fr['lage']->formatted, 'nicht „Lieu-dit Klaus"');

        // The variant's own translation counts before the main product's —
        // also for a text it inherits.
        $this->connection->insert('tl_consho_translation', ['ptable' => 'tl_consho_product', 'pid' => 11, 'language' => 'fr', 'customFieldValues' => '{"lage":"Lieu-dit Achleiten","rebsorte":"Veltliner (variante)"}']);
        $fr = $this->byAlias($this->display()->forArticle(11, 'fr', 'de'));
        self::assertSame(['Lieu-dit Achleiten', 'Veltliner (variante)'], [$fr['lage']->formatted, $fr['rebsorte']->formatted]);
    }

    /**
     * The real list from the template, with the real German texts.
     */
    public function testDieVorlage(): void
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../contao/languages/de/default.php';

        $html = $this->render($this->part('<dl class="consho-detail__custom-fields"', '</dl>'), ['customFields' => $this->display()->forArticle(10, 'de', 'de')]);

        self::assertStringContainsString('aria-label="Angaben"', $html);
        self::assertStringContainsString('<dt>Jahrgang</dt>', $html);
        self::assertStringContainsString('<dd>1234,5</dd>', $html);
        self::assertStringContainsString('<dt>Bio</dt>', $html);
        self::assertStringContainsString('<dd>Nein</dd>', $html);
        self::assertStringContainsString('<dd>01.03.2024</dd>', $html);
    }

    /**
     * @param list<CustomFieldView> $views
     *
     * @return array<string, CustomFieldView>
     */
    private function byAlias(array $views): array
    {
        return array_column(array_map(static fn (CustomFieldView $v): array => ['a' => $v->alias, 'v' => $v], $views), 'v', 'a');
    }

    private function display(): CustomFieldDisplay
    {
        $values = new CustomFieldValues($this->connection, new CustomFieldCodec());
        $storage = new TranslationStorage($this->connection);
        $languages = new ShopLanguages($this->connection);
        $writer = new TranslationWriter($storage, new AliasFactory($this->createMock(Slug::class), $this->connection), new RedirectRecorder($this->connection, new DbalProductPageRepository($this->connection), $languages), $languages);

        return new CustomFieldDisplay($values, new CustomFieldTranslations($storage, $writer, $values, new CustomFieldCodec()), $storage);
    }

    private function part(string $from, string $to): string
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__.'/../../contao/templates/content_element/consho_product_detail.html.twig'));
        $start = strpos($source, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($source, $to, $start);
        self::assertNotFalse($end, $to);

        return substr($source, $start, $end - $start + \strlen($to));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $part, array $vars): string
    {
        $twig = new Environment(new ArrayLoader(['part' => $part]));
        $twig->addFilter(new TwigFilter('trans', static function (string $id): string {
            $value = $GLOBALS['TL_LANG'];

            foreach (explode('.', $id) as $key) {
                if (!\is_array($value) || !isset($value[$key])) {
                    return $id;
                }

                $value = $value[$key];
            }

            return \is_string($value) ? $value : $id;
        }));

        return $twig->render('part', $vars);
    }
}

/**
 * Counts the statements DBAL's logging middleware reports.
 */
final class QueryCounter extends AbstractLogger
{
    public int $count = 0;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (str_starts_with((string) $message, 'Executing')) {
            ++$this->count;
        }
    }
}
