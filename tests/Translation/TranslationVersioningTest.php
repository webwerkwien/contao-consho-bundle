<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationVersioning;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Against an SQLite database with tl_version in the core's shape.
 *
 * The dangerous error of this class is silent: the restore reports
 * success, the main table is right, and the translations are empty or
 * wrong. That is why every test checks the state of the rows, not calls.
 */
class TranslationVersioningTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '')",
        );
        DcaSchema::create($this->connection, 'tl_consho_shop');

        // As strict as the real table — both unique indexes.
        DcaSchema::create($this->connection, 'tl_consho_translation');

        // Like vendor/contao/core-bundle/contao/dca/tl_version.php.
        $this->connection->executeStatement(
            "CREATE TABLE tl_version (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER NOT NULL DEFAULT 0,
                tstamp INTEGER NOT NULL DEFAULT 0,
                version INTEGER NOT NULL DEFAULT 1,
                fromTable TEXT NOT NULL DEFAULT '',
                userid INTEGER NOT NULL DEFAULT 0,
                username TEXT NULL,
                description TEXT NOT NULL DEFAULT '',
                editUrl TEXT NULL,
                active INTEGER NOT NULL DEFAULT 0,
                data BLOB NULL
            )",
        );
    }

    public function testTheSnapshotIsFlatAndUsesTheMasksFieldNames(): void
    {
        // The shape is the whole point: compare() labels via
        // $GLOBALS['TL_DCA'][table]['fields'][k]['label'], and the form creates
        // exactly these field names. Stored as a nested lump, the comparison
        // shows an unreadable block diff.
        $this->translate('tl_consho_product', 1, 'en', title: 'Herbal Bitters', alias: 'herbal-bitters');

        $snapshot = $this->versioning()->snapshot('tl_consho_product', 1);

        self::assertSame('Herbal Bitters', $snapshot['title__en']);
        self::assertSame('herbal-bitters', $snapshot['alias__en']);
        self::assertSame('', $snapshot['teaser__en']);
        self::assertArrayNotHasKey('en', $snapshot);
        self::assertArrayNotHasKey('id__en', $snapshot);
    }

    public function testTwoLanguagesLandSideBySide(): void
    {
        $this->translate('tl_consho_product', 1, 'en', title: 'Herbal Bitters', alias: 'herbal-bitters');
        $this->translate('tl_consho_product', 1, 'fr', title: 'Amer aux herbes', alias: 'amer');

        $snapshot = $this->versioning()->snapshot('tl_consho_product', 1);

        self::assertSame('Herbal Bitters', $snapshot['title__en']);
        self::assertSame('Amer aux herbes', $snapshot['title__fr']);
    }

    public function testOnlyTheFieldsOfTheTableAreInTheSnapshot(): void
    {
        // A property option only has the title. A teaser__en in the snapshot
        // would be a line without a field behind it in the comparison.
        $this->translate('tl_consho_property_option', 1, 'en', title: 'Red', alias: null);

        self::assertSame(['title__en' => 'Red'], $this->versioning()->snapshot('tl_consho_property_option', 1));
    }

    public function testAnotherTableWithTheSameIdIsNotInTheSnapshot(): void
    {
        $this->translate('tl_consho_manufacturer', 1, 'en', title: 'Maker', alias: 'maker');

        self::assertSame([], $this->versioning()->snapshot('tl_consho_product', 1));
    }

    public function testAMonolingualInstallationHasAnEmptySnapshot(): void
    {
        // Touchstone of the specification (section 2): without a second
        // language the version row stays as it was before M-5.
        $this->shop(7, 'de', 'de');

        self::assertSame([], $this->versioning()->snapshot('tl_consho_product', 1));
    }

    public function testAnOfferedLanguageWithoutARowIsInTheSnapshotAsEmpty(): void
    {
        // The empty field is a statement: "in this state there was no
        // English". If the key were missing, the version could not be told
        // apart from one before M-5 — and a restore would leave a translation
        // created later in place.
        $this->shop(7, 'de', 'de', 'en');

        $snapshot = $this->versioning()->snapshot('tl_consho_product', 1);

        self::assertSame('', $snapshot['title__en']);
        self::assertSame('', $snapshot['alias__en']);
    }

    public function testALanguageNoLongerOfferedIsStillInTheSnapshot(): void
    {
        // The row exists; it is only invisible as long as no shop carries the
        // language. A snapshot reflects what is stored.
        $this->shop(7, 'de', 'de');
        $this->translate('tl_consho_product', 1, 'fr', title: 'Amer aux herbes', alias: 'amer');

        self::assertSame('Amer aux herbes', $this->versioning()->snapshot('tl_consho_product', 1)['title__fr']);
    }

    public function testStoringPutsItIntoTheExistingVersionRow(): void
    {
        $this->translate('tl_consho_product', 1, 'en', title: 'Herbal Bitters', alias: 'herbal-bitters');
        $this->version('tl_consho_product', pid: 1, version: 2, data: ['id' => 1, 'title' => 'Alt']);
        $this->version('tl_consho_product', pid: 1, version: 3, data: ['id' => 1, 'title' => 'Kräuterbitter']);

        $this->versioning()->store('tl_consho_product', 1, 3);

        $data = $this->versionData('tl_consho_product', 1, 3);

        self::assertSame('Kräuterbitter', $data['title']);
        self::assertSame('Herbal Bitters', $data['title__en']);
        self::assertSame(['id' => 1, 'title' => 'Alt'], $this->versionData('tl_consho_product', 1, 2));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_version'));
    }

    public function testStoringOnAMonolingualInstallationLeavesTheVersionRowUntouched(): void
    {
        $this->shop(7, 'de', 'de');
        $this->version('tl_consho_product', pid: 1, version: 1, data: ['id' => 1, 'title' => 'Kräuterbitter']);
        $before = $this->connection->fetchOne('SELECT data FROM tl_version');

        $this->versioning()->store('tl_consho_product', 1, 1);

        self::assertSame($before, $this->connection->fetchOne('SELECT data FROM tl_version'));
    }

    public function testRestoringWritesTheTranslationsBack(): void
    {
        // At this point Contao's restore() has already stripped the
        // translation keys via array_intersect_key() — so the class is passed
        // no data at all and has to read tl_version itself.
        $this->translate('tl_consho_product', 1, 'en', title: 'Something Else', alias: 'something-else');
        $this->version('tl_consho_product', pid: 1, version: 3, data: [
            'id' => 1,
            'title' => 'Kräuterbitter',
            'title__en' => 'Herbal Bitters',
            'alias__en' => 'herbal-bitters',
            'teaser__en' => 'Bitter.',
            'metaDescription__en' => 'Herbal bitters to the original recipe',
        ]);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame(
            ['title' => 'Herbal Bitters', 'alias' => 'herbal-bitters', 'teaser' => 'Bitter.', 'metaDescription' => 'Herbal bitters to the original recipe', 'description' => '', 'safetyWarnings' => '', 'safetyInformation' => '', 'customFieldValues' => ''],
            $this->storage()->readAll('tl_consho_product', 1, 'en'),
        );
    }

    public function testRestoringEmptiesALanguageTheVersionHadAsEmpty(): void
    {
        $this->translate('tl_consho_product', 1, 'en', title: 'Herbal Bitters', alias: 'herbal-bitters');
        $this->version('tl_consho_product', pid: 1, version: 3, data: [
            'id' => 1,
            'title__en' => '',
            'alias__en' => '',
        ]);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame(0, $this->storage()->rowId('tl_consho_product', 1, 'en'));
    }

    public function testRestoringRemovesALanguageThatTheVersionDidNotHave(): void
    {
        // Otherwise a language created later would survive the restore to a
        // state in which it did not exist yet.
        $this->translate('tl_consho_product', 1, 'fr', title: 'Amer aux herbes', alias: 'amer');
        $this->version('tl_consho_product', pid: 1, version: 3, data: [
            'id' => 1,
            'title__en' => 'Herbal Bitters',
            'alias__en' => 'herbal-bitters',
        ]);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame(0, $this->storage()->rowId('tl_consho_product', 1, 'fr'));
        self::assertSame('Herbal Bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'title'));
    }

    public function testRestoringAVersionFromBeforeTranslationsWereVersionedLeavesThemAlone(): void
    {
        // A version without any translation key dates from before M-5 (or from
        // a time without a translation language). It knows nothing about
        // translations — deleting them would mean destroying text that is in no
        // version and cannot be restored from anywhere.
        $this->translate('tl_consho_product', 1, 'en', title: 'Herbal Bitters', alias: 'herbal-bitters');
        $this->version('tl_consho_product', pid: 1, version: 3, data: ['id' => 1, 'title' => 'Kräuterbitter']);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame('Herbal Bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'title'));
    }

    public function testRestoringBringsBackALanguageNoLongerOffered(): void
    {
        // Restored instead of skipped: the row is invisible as long as no shop
        // carries the language, but not lost. Skipped, it would be gone exactly
        // when the language comes back.
        $this->shop(7, 'de', 'de');
        $this->version('tl_consho_product', pid: 1, version: 3, data: [
            'id' => 1,
            'title__fr' => 'Amer aux herbes',
            'alias__fr' => 'amer',
        ]);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame('Amer aux herbes', $this->storage()->read('tl_consho_product', 1, 'fr', 'title'));
    }

    public function testAnAliasTakenMeanwhileIsEmptiedInsteadOfFailing(): void
    {
        // Like Contao with its own unique field (Versions::restore, #698):
        // clear instead of failing on the index. An SQL error in the callback
        // would be an error page — and the main table would already be at the
        // old state.
        $this->translate('tl_consho_product', 2, 'en', title: 'Other Bitters', alias: 'herbal-bitters');
        $this->version('tl_consho_product', pid: 1, version: 3, data: [
            'id' => 1,
            'title__en' => 'Herbal Bitters',
            'alias__en' => 'herbal-bitters',
        ]);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame('Herbal Bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'title'));
        self::assertNull($this->connection->fetchOne("SELECT alias FROM tl_consho_translation WHERE ptable = 'tl_consho_product' AND pid = 1"));
        self::assertSame('herbal-bitters', $this->storage()->read('tl_consho_product', 2, 'en', 'alias'));
    }

    public function testTheOwnAliasIsNoCollision(): void
    {
        $this->translate('tl_consho_product', 1, 'en', title: 'Something Else', alias: 'herbal-bitters');
        $this->version('tl_consho_product', pid: 1, version: 3, data: [
            'id' => 1,
            'title__en' => 'Herbal Bitters',
            'alias__en' => 'herbal-bitters',
        ]);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame('herbal-bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'alias'));
    }

    public function testTheSameAliasInAnotherTableIsNoCollision(): void
    {
        // The index is (ptable, language, alias).
        $this->translate('tl_consho_manufacturer', 2, 'en', title: 'Herbal', alias: 'herbal-bitters');
        $this->version('tl_consho_product', pid: 1, version: 3, data: [
            'id' => 1,
            'title__en' => 'Herbal Bitters',
            'alias__en' => 'herbal-bitters',
        ]);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame('herbal-bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'alias'));
    }

    public function testRestoringLeavesOtherTablesWithTheSameIdAlone(): void
    {
        $this->translate('tl_consho_manufacturer', 1, 'en', title: 'Maker', alias: 'maker');
        $this->version('tl_consho_product', pid: 1, version: 3, data: ['id' => 1, 'title__en' => 'Herbal Bitters', 'alias__en' => 'herbal-bitters']);

        $this->versioning()->restore('tl_consho_product', 1, 3);

        self::assertSame('Maker', $this->storage()->read('tl_consho_manufacturer', 1, 'en', 'title'));
    }

    public function testAMissingVersionChangesNothing(): void
    {
        $this->translate('tl_consho_product', 1, 'en', title: 'Herbal Bitters', alias: 'herbal-bitters');

        $this->versioning()->restore('tl_consho_product', 1, 9);

        self::assertSame('Herbal Bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'title'));
    }

    public function testASavedStateSurvivesTheRoundTrip(): void
    {
        $this->shop(7, 'de', 'de', 'en', 'fr');
        $this->translate('tl_consho_product', 1, 'en', title: 'Herbal Bitters', alias: 'herbal-bitters');
        $this->version('tl_consho_product', pid: 1, version: 1, data: ['id' => 1]);
        $this->versioning()->store('tl_consho_product', 1, 1);

        // Afterwards: English changed, French new.
        $this->storage()->write('tl_consho_product', 1, 'en', ['title' => 'Something Else', 'alias' => 'something-else']);
        $this->storage()->write('tl_consho_product', 1, 'fr', ['title' => 'Amer', 'alias' => 'amer']);

        $this->versioning()->restore('tl_consho_product', 1, 1);

        self::assertSame('Herbal Bitters', $this->storage()->read('tl_consho_product', 1, 'en', 'title'));
        self::assertSame(0, $this->storage()->rowId('tl_consho_product', 1, 'fr'));
    }

    private function versioning(): TranslationVersioning
    {
        return new TranslationVersioning($this->connection, $this->storage(), new ShopLanguages($this->connection));
    }

    private function storage(): TranslationStorage
    {
        return new TranslationStorage($this->connection);
    }

    private function translate(string $ptable, int $pid, string $language, string $title, string|null $alias): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => $pid, 'ptable' => $ptable, 'language' => $language,
            'title' => $title, 'alias' => $alias,
        ]);
    }

    /**
     * @param array<string, int|string> $data
     */
    private function version(string $table, int $pid, int $version, array $data): void
    {
        $this->connection->insert('tl_version', [
            'pid' => $pid, 'version' => $version, 'fromTable' => $table, 'data' => serialize($data),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function versionData(string $table, int $pid, int $version): array
    {
        $raw = $this->connection->fetchOne(
            'SELECT data FROM tl_version WHERE fromTable = ? AND pid = ? AND version = ?',
            [$table, $pid, $version],
        );

        return unserialize((string) $raw);
    }

    private function shop(int $id, string $fallback, string ...$languages): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'fallbackLanguage' => $fallback]);

        foreach ($languages as $language) {
            $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => $id, 'language' => $language]);
        }
    }
}
