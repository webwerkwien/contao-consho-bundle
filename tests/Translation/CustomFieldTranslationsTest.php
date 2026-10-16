<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use Contao\CoreBundle\Slug\Slug;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldCodec;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldValues;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\CustomFieldTranslations;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationRefused;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationWriter;

/**
 * ZW-3 (Z1; Michael, 2026-10-14: „ja, alles wie empfohlen"): Texte der
 * Zusatzfelder je Sprache — nur Textfelder, nur Sprachen des Produkts, und
 * nie als rohes JSON am Prüfweg vorbei.
 */
class CustomFieldTranslationsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_custom_field', 'tl_consho_shop', 'tl_consho_product_shop', 'tl_consho_translation'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', urlPrefix TEXT NOT NULL DEFAULT '', urlSuffix TEXT NOT NULL DEFAULT '')");
        $this->connection->insert('tl_consho_shop', ['id' => 7, 'fallbackLanguage' => 'de']);
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => 7, 'language' => 'de']);
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => 7, 'language' => 'fr']);

        $this->connection->insert('tl_consho_custom_field', ['id' => 1, 'alias' => 'jahrgang', 'title' => 'Jahrgang', 'type' => 'number']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 2, 'alias' => 'rebsorte', 'title' => 'Rebsorte', 'type' => 'text']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 3, 'alias' => 'lage', 'title' => 'Lage', 'type' => 'text']);
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'customFields' => serialize(['2', '1', '3'])]);
        $this->connection->insert('tl_consho_product', ['id' => 10, 'pid' => 0, 'productType' => 1, 'title' => 'Wein']);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 10, 'shop' => 7]);
    }

    public function testNurTextfelder(): void
    {
        self::assertSame(['rebsorte', 'lage'], array_map(static fn ($f): string => $f->alias, $this->translations()->textFieldsOf(10)));
        self::assertSame(['fr'], $this->translations()->languagesOf(10));
    }

    public function testSetzenUndLeerEntfernt(): void
    {
        $translations = $this->translations();

        self::assertTrue($translations->set(10, 'fr', ['rebsorte' => ' Riesling ', 'lage' => 'Wachau']));
        self::assertSame(['rebsorte' => 'Riesling', 'lage' => 'Wachau'], $translations->read(10, 'fr'));

        self::assertTrue($translations->set(10, 'fr', ['lage' => '']));
        self::assertSame(['rebsorte' => 'Riesling'], $translations->read(10, 'fr'), 'leer: wieder die Standardsprache');

        self::assertFalse($translations->set(10, 'fr', ['rebsorte' => 'Riesling']), 'unverändert');
    }

    public function testDerLetzteTextLeertDieSpalte(): void
    {
        $translations = $this->translations();
        $translations->set(10, 'fr', ['rebsorte' => 'Riesling']);
        $translations->set(10, 'fr', ['rebsorte' => '']);

        self::assertSame([], $translations->read(10, 'fr'));
        self::assertFalse($this->connection->fetchOne("SELECT id FROM tl_consho_translation WHERE pid = 10 AND language = 'fr'"), 'ein leerer Block ist keine Zeile');
    }

    /**
     * Review ZW-K, Fund 1: Nur „Rebsorte" übersetzt, kein französischer Titel.
     * Der Text allein verlangt keinen Alias — ohne ihn bleibt das Produkt in
     * fr unsichtbar, das ist alles. Vorher warf das zweite Speichern
     * „weder Alias noch Bezeichnung", am Übersetzungsabschnitt.
     */
    public function testNurZusatzfeldTextVerlangtKeinenAlias(): void
    {
        $this->translations()->set(10, 'fr', ['rebsorte' => 'Riesling']);

        $this->writer()->checkAlias('tl_consho_product', 10, 'fr', '', []);
        $this->translations()->set(10, 'fr', ['lage' => 'Wachau']);

        self::assertSame(['rebsorte' => 'Riesling', 'lage' => 'Wachau'], $this->translations()->read(10, 'fr'));
        self::assertNull($this->connection->fetchOne("SELECT alias FROM tl_consho_translation WHERE pid = 10 AND language = 'fr'"));

        // Ein anderes Feld ohne Titel bleibt abgelehnt.
        $this->expectException(\RuntimeException::class);
        $this->writer()->checkAlias('tl_consho_product', 10, 'fr', '', ['teaser' => 'Un vin']);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function abgelehnt(): iterable
    {
        yield 'Zahl hat keine Übersetzung' => ['fr', ['jahrgang' => '2021'], '"jahrgang" is no translatable custom field of this product\'s type (text fields only). Fields: rebsorte, lage.'];
        yield 'unbekanntes Feld' => ['fr', ['farbe' => 'rot'], '"farbe" is no translatable custom field'];
        yield 'fremde Sprache' => ['en', ['rebsorte' => 'x'], '"en" is no translation language of product 10. Offered: fr.'];
        yield 'zu lang' => ['fr', ['rebsorte' => str_repeat('a', 256)], 'rebsorte: the text is longer than 255 characters.'];
    }

    /**
     * @param array<string, string> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('abgelehnt')]
    public function testAbgelehntOhneZuSchreiben(string $language, array $input, string $reason): void
    {
        try {
            $this->translations()->set(10, $language, $input);
            self::fail('nicht abgelehnt');
        } catch (TranslationRefused $e) {
            self::assertStringStartsWith($reason, $e->getMessage());
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));
    }

    /**
     * Über den allgemeinen Weg (consho:translation:set) nie als rohes JSON —
     * dort prüfte niemand Feld und Typ.
     */
    public function testNieAlsRohesJson(): void
    {
        try {
            $this->writer()->set('tl_consho_product', 10, 'fr', ['customFieldValues' => '{"jahrgang":"abc"}']);
            self::fail('nicht abgelehnt');
        } catch (TranslationRefused $e) {
            self::assertStringContainsString('Not translatable in tl_consho_product: customFieldValues.', $e->getMessage());
            self::assertStringEndsWith('Custom field texts are set per field.', $e->getMessage());
            self::assertStringNotContainsString('Translatable: title, alias, teaser, metaDescription, description, safetyWarnings, safetyInformation, customFieldValues', $e->getMessage());
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));
    }

    private function writer(): TranslationWriter
    {
        $storage = new TranslationStorage($this->connection);
        $languages = new ShopLanguages($this->connection);

        return new TranslationWriter($storage, new AliasFactory($this->createMock(Slug::class), $this->connection), new RedirectRecorder($this->connection, new DbalProductPageRepository($this->connection), $languages), $languages);
    }

    private function translations(): CustomFieldTranslations
    {
        return new CustomFieldTranslations(new TranslationStorage($this->connection), $this->writer(), new CustomFieldValues($this->connection, new CustomFieldCodec()), new CustomFieldCodec());
    }
}
