<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Gegen eine echte Datenbank, nicht gegen einen Mock: Es geht um Zeilen, die
 * ein UNIQUE-Index zulässt oder ablehnt — das beantwortet kein erwarteter
 * Aufruf.
 */
class TranslationStorageTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Das Testschema muss mindestens so streng sein wie die echte
        // Datenbank — siehe contao/dca/tl_consho_translation.php.
        DcaSchema::create($this->connection, 'tl_consho_translation');
    }

    public function testItWritesAndReadsAField(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters']);

        self::assertSame('Bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'title'));
    }

    public function testAnUnknownLanguageReadsAsEmptyString(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters']);

        self::assertSame('', $this->storage()->read('tl_consho_product', 1, 'fr', 'title'));
    }

    public function testWritingTwiceUpdatesInsteadOfInserting(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters']);
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters (neu)']);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));
        self::assertSame('Bitters (neu)', $this->storage()->read('tl_consho_product', 1, 'en', 'title'));
    }

    public function testAnEmptyLanguageBlockRemovesTheRow(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters']);
        $this->storage()->write('tl_consho_product', 1, 'en', []);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));
    }

    public function testAnEmptyAliasIsStoredAsNull(): void
    {
        // property_option hat kein alias unter den übersetzbaren Feldern —
        // die Spalte bleibt beim Standard NULL, und zwei Zeilen mit NULL
        // vertragen sich mit UNIQUE (ptable, language, alias), zwei leere
        // Strings nicht.
        $this->storage()->write('tl_consho_property_option', 1, 'en', ['title' => 'Rot']);
        $this->storage()->write('tl_consho_property_option', 2, 'en', ['title' => 'Blau']);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT alias FROM tl_consho_translation WHERE ptable = ? AND language = ? ORDER BY pid',
            ['tl_consho_property_option', 'en'],
        );

        self::assertNull($rows[0]['alias']);
        self::assertNull($rows[1]['alias']);
    }

    public function testAnEmptyAliasOnATableThatHasOneIsStoredAsNullToo(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters', 'alias' => '']);
        $this->storage()->write('tl_consho_product', 2, 'en', ['title' => 'Amaro', 'alias' => '']);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT alias FROM tl_consho_translation WHERE ptable = ? AND language = ? ORDER BY pid',
            ['tl_consho_product', 'en'],
        );

        self::assertNull($rows[0]['alias']);
        self::assertNull($rows[1]['alias']);
    }

    public function testReadAllReturnsExactlyTheTranslatableFields(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', [
            'title' => 'Bitters',
            'alias' => 'bitters',
            'teaser' => 'A bitter teaser',
            'metaDescription' => 'Bitters to the original recipe',
            'description' => 'A bitter description',
            'safetyWarnings' => 'Contains alcohol',
            'safetyInformation' => 'Keep out of reach of children',
            'customFieldValues' => '{"rebsorte":"Riesling"}',
        ]);

        self::assertSame(
            [
                'title' => 'Bitters',
                'alias' => 'bitters',
                'teaser' => 'A bitter teaser',
                'metaDescription' => 'Bitters to the original recipe',
                'description' => 'A bitter description',
                'safetyWarnings' => 'Contains alcohol',
                'safetyInformation' => 'Keep out of reach of children',
                'customFieldValues' => '{"rebsorte":"Riesling"}',
            ],
            $this->storage()->readAll('tl_consho_product', 1, 'en'),
        );
    }

    public function testReadAllOfAMissingRowIsEmpty(): void
    {
        self::assertSame([], $this->storage()->readAll('tl_consho_product', 1, 'en'));
    }

    public function testAFieldMissingFromTheBlockIsClearedOnUpdate(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters', 'teaser' => 'A bitter teaser']);
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters']);

        self::assertSame('', $this->storage()->read('tl_consho_product', 1, 'en', 'teaser'));
    }

    public function testUnknownKeysAreDroppedWithoutError(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters', 'notAField' => 'x']);

        self::assertSame('Bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'title'));
    }

    public function testReadingAnUnknownFieldReturnsEmptyStringInsteadOfHittingTheDatabase(): void
    {
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters']);

        self::assertSame('', $this->storage()->read('tl_consho_product', 1, 'en', 'notAField'));
    }

    public function testRowIdIsTheIdOfTheTranslationRowNotOfTheParent(): void
    {
        // Eine fremde Zeile vorweg, damit Zeilen-ID und Produkt-ID
        // auseinanderfallen — sonst bestünde der Test auch mit der falschen.
        $this->storage()->write('tl_consho_manufacturer', 9, 'en', ['title' => 'Maker']);
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Bitters']);

        self::assertSame(2, $this->storage()->rowId('tl_consho_product', 1, 'en'));
    }

    public function testRowIdOfAMissingRowIsZero(): void
    {
        self::assertSame(0, $this->storage()->rowId('tl_consho_product', 1, 'en'));
    }

    public function testItListsTheShopsOfAProduct(): void
    {
        $this->productShops();
        $this->connection->insert('tl_consho_product_shop', ['pid' => 5, 'shop' => 8]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 5, 'shop' => 7]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 6, 'shop' => 9]);

        self::assertSame([7, 8], $this->storage()->shopsOfProduct(5));
    }

    public function testANewRecordHasNoShops(): void
    {
        // Beim Anlegen ist die ID noch 0 — ohne Abfrage, auch ohne Tabelle.
        self::assertSame([], $this->storage()->shopsOfProduct(0));
    }

    private function productShops(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
    }

    private function storage(): TranslationStorage
    {
        return new TranslationStorage($this->connection);
    }
}
