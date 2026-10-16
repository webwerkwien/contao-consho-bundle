<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use Contao\CoreBundle\Slug\Slug;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationRefused;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationWriter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Connect Stufe 2, CW-2: einen Sprachblock setzen ohne Maske — dieselben
 * Regeln wie dort (Spezifikation Mehrsprachigkeit 7.3), aber jede Sprache und
 * jedes Feld ausdrücklich geprüft, weil kein Formular sie vorgibt.
 */
class TranslationWriterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            // pid and published: a translated manufacturer alias reaches
            // RedirectRecorder, which looks for the product pages.
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', urlPrefix TEXT NOT NULL DEFAULT '', urlSuffix TEXT NOT NULL DEFAULT '')",
        );
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_manufacturer');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_translation');

        $this->shop(7, 'de', 'de', 'en');
        $this->shop(8, 'fr', 'fr', 'it');
        $this->connection->insert('tl_consho_product', ['id' => 5, 'pid' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 6, 'pid' => 5]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 5, 'shop' => 7]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testSetztDenBlockUndErzeugtDenAlias(): void
    {
        self::assertTrue($this->writer()->set('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'teaser' => 'Short']));

        self::assertSame('bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'alias'));
        self::assertSame('Short', $this->storage()->read('tl_consho_product', 5, 'en', 'teaser'));
    }

    public function testNichtGenannteFelderBleiben(): void
    {
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters', 'teaser' => 'Kept']);

        $this->writer()->set('tl_consho_product', 5, 'en', ['title' => 'Herbal Bitters']);

        self::assertSame('Herbal Bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'title'));
        self::assertSame('Kept', $this->storage()->read('tl_consho_product', 5, 'en', 'teaser'));
        self::assertSame('bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'alias'));
    }

    public function testUnveraendertMeldetKeineAenderung(): void
    {
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        self::assertFalse($this->writer()->set('tl_consho_product', 5, 'en', ['title' => 'Bitters']));
    }

    public function testAllesLeerLoeschtDenBlock(): void
    {
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        self::assertTrue($this->writer()->set('tl_consho_product', 5, 'en', ['title' => '', 'alias' => '']));
        self::assertSame(0, $this->storage()->rowId('tl_consho_product', 5, 'en'));
    }

    public function testEineSpracheOhneShopWirdAbgelehnt(): void
    {
        // Produkt 5 ist nur im Shop 7 (de, en) — Italienisch verkauft er nicht.
        $this->expectException(TranslationRefused::class);
        $this->expectExceptionMessage('"it"');

        $this->writer()->set('tl_consho_product', 5, 'it', ['title' => 'Amaro']);
    }

    public function testDieRueckfallspracheIstKeineUebersetzung(): void
    {
        $this->expectException(TranslationRefused::class);

        $this->writer()->set('tl_consho_product', 5, 'de', ['title' => 'Bitter']);
    }

    public function testEineVarianteOhneEigeneShopsHatDieSprachenDesHauptprodukts(): void
    {
        self::assertSame(['en'], $this->writer()->languagesOf('tl_consho_product', 6));
        self::assertSame(['en'], $this->writer()->languagesOf('tl_consho_product', 6, 5));
    }

    public function testAndereTabellenHabenDieSprachenAllerShops(): void
    {
        self::assertSame(['en', 'it'], $this->writer()->languagesOf('tl_consho_manufacturer', 3));
        self::assertTrue($this->writer()->set('tl_consho_manufacturer', 3, 'it', ['title' => 'Produttore']));
    }

    public function testEinShopHatSeineEigenenSprachen(): void
    {
        self::assertSame(['it'], $this->writer()->languagesOf('tl_consho_shop', 8));
    }

    public function testEinUnbekanntesFeldWirdAbgelehnt(): void
    {
        $this->expectException(TranslationRefused::class);
        $this->expectExceptionMessage('sku');

        $this->writer()->set('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'sku' => 'X']);
    }

    public function testEineTabelleOhneUebersetzungWirdAbgelehnt(): void
    {
        $this->expectException(TranslationRefused::class);
        $this->expectExceptionMessage('tl_consho_price has no translatable fields.');

        $this->writer()->set('tl_consho_price', 5, 'en', ['title' => 'X']);
    }

    public function testEinVergebenerAliasWirdAbgelehnt(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 9, 'pid' => 0]);
        $this->storage()->write('tl_consho_product', 9, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        $this->expectException(TranslationRefused::class);

        $this->writer()->set('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);
    }

    public function testDerEigeneAliasIstNichtVergeben(): void
    {
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        self::assertTrue($this->writer()->set('tl_consho_product', 5, 'en', ['teaser' => 'New', 'alias' => ' bitters ']));
        self::assertSame('bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'alias'));
    }

    /**
     * Spezifikation 7.3: Pflicht, sobald in der Sprache etwas ausgefüllt ist
     * und es keinen Titel gibt, aus dem er entstehen könnte.
     */
    public function testOhneTitelUndAliasWirdAbgelehnt(): void
    {
        $this->expectException(TranslationRefused::class);

        $this->writer()->set('tl_consho_product', 5, 'en', ['teaser' => 'Only a teaser']);
    }

    /**
     * Ein Hersteller bietet jede Sprache an — ohne die Prüfung entstünde eine
     * Übersetzungszeile mit pid 0.
     */
    public function testOhneDatensatzWirdAbgelehnt(): void
    {
        $this->expectException(TranslationRefused::class);
        $this->expectExceptionMessage('saved record');

        $this->writer()->set('tl_consho_manufacturer', 0, 'en', ['title' => 'X']);
    }

    private function writer(): TranslationWriter
    {
        return new TranslationWriter(
            $this->storage(),
            new AliasFactory($this->countingSlug(), $this->connection),
            new RedirectRecorder($this->connection, new DbalProductPageRepository($this->connection), new ShopLanguages($this->connection)),
            new ShopLanguages($this->connection),
        );
    }

    private function storage(): TranslationStorage
    {
        return new TranslationStorage($this->connection);
    }

    private function countingSlug(): Slug
    {
        $slug = $this->createMock(Slug::class);
        $slug->method('generate')->willReturnCallback(
            static function (string $text, array $options, callable $duplicateCheck): string {
                $base = strtolower(str_replace(' ', '-', $text));
                $candidate = $base;

                for ($count = 2; $duplicateCheck($candidate); ++$count) {
                    $candidate = $base.'-'.$count;
                }

                return $candidate;
            },
        );

        return $slug;
    }

    private function shop(int $id, string $fallback, string ...$languages): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'fallbackLanguage' => $fallback]);

        foreach ($languages as $language) {
            $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => $id, 'language' => $language]);
        }
    }
}
