<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Slug\Slug;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TranslationFieldsListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TranslationVersionListener;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslatableFields;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationVersioning;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * DC_Table und Versions laufen hier nicht — ihre Reihenfolge wird von Hand
 * nachgestellt, so wie sie in Contao 5.7 steht:
 *
 *   DC_Table::submit(): UPDATE Haupttabelle → onsubmit_callback → Versions::create()
 *   Versions::create(): INSERT tl_version → oncreate_version_callback
 *   Versions::restore(): array_intersect_key() → UPDATE → onrestore_version_callback
 *
 * Ob Contao das im Browser wirklich so tut, gehört in die Abnahme.
 */
class TranslationVersionListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // published, urlPrefix, urlSuffix: Der Alias-Wechsel im ersten Test läuft
        // durch RedirectRecorder, der Startpunkte und Produktseiten liest. Es gibt
        // keine Produktseite, also wird nichts aufgezeichnet.
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', published INTEGER NOT NULL DEFAULT 0, urlPrefix TEXT NOT NULL DEFAULT '', urlSuffix TEXT NOT NULL DEFAULT '')",
        );
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_translation');
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

        $this->connection->insert('tl_consho_shop', ['id' => 7, 'fallbackLanguage' => 'de']);
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => 7, 'language' => 'de']);
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => 7, 'language' => 'en']);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 5, 'shop' => 7]);
    }

    public function testBothCallbacksAreRegisteredForEveryTranslatedTable(): void
    {
        // Eine vergessene Tabelle fiele nirgends auf: Ihre Versionen hätten
        // einfach keine Übersetzungen.
        self::assertSame(TranslatableFields::tables(), $this->tablesOf('onCreate', 'config.oncreate_version'));
        self::assertSame(TranslatableFields::tables(), $this->tablesOf('onRestore', 'config.onrestore_version'));
    }

    public function testAVersionCreatedOnSaveHoldsWhatWasJustSaved(): void
    {
        // Die Frage, an der M-5 hängt: Enthält die Version den neuen Stand oder
        // den davor? Der Schnappschuss liest die Datenbank im oncreate-Callback,
        // und der läuft nach dem onsubmit, in dem store() schreibt.
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Herbal Bitters', 'alias' => 'herbal-bitters']);

        $fields = $this->fieldsListener();
        $fields->save('Something Else', $this->dc('tl_consho_product', 5, 'title__en'));
        $fields->save('something-else', $this->dc('tl_consho_product', 5, 'alias__en'));

        // onsubmit_callback
        $fields->store($this->dc('tl_consho_product', 5));

        // Versions::create(): INSERT, dann oncreate_version_callback
        $data = ['id' => 5, 'tstamp' => 1, 'title' => 'Kräuterbitter'];
        $this->connection->insert('tl_version', ['pid' => 5, 'version' => 2, 'fromTable' => 'tl_consho_product', 'data' => serialize($data)]);
        $this->versionListener()->onCreate('tl_consho_product', 5, 2, $data);

        $stored = unserialize((string) $this->connection->fetchOne('SELECT data FROM tl_version WHERE version = 2'));

        self::assertSame('Something Else', $stored['title__en']);
        self::assertSame('something-else', $stored['alias__en']);
        self::assertSame('Kräuterbitter', $stored['title']);
    }

    public function testRestoreDoesNotRelyOnTheDataContaoPassesAlong(): void
    {
        // Versions::restore() reicht $data nach array_intersect_key() auf die
        // Spalten der Haupttabelle weiter — ohne title__en.
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Something Else', 'alias' => 'something-else']);
        $this->connection->insert('tl_version', ['pid' => 5, 'version' => 1, 'fromTable' => 'tl_consho_product', 'data' => serialize([
            'id' => 5,
            'title' => 'Kräuterbitter',
            'title__en' => 'Herbal Bitters',
            'alias__en' => 'herbal-bitters',
        ])]);

        // Die Versionsnummer kommt aus Input::post() — als Zeichenkette.
        $this->versionListener()->onRestore('tl_consho_product', 5, '1', ['id' => 5, 'title' => 'Kräuterbitter']);

        self::assertSame('Herbal Bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'title'));
    }

    /**
     * @return list<string>
     */
    private function tablesOf(string $method, string $target): array
    {
        $tables = [];

        foreach ((new \ReflectionMethod(TranslationVersionListener::class, $method))->getAttributes(AsCallback::class) as $attribute) {
            $arguments = $attribute->getArguments();

            if ($target === ($arguments['target'] ?? null)) {
                $tables[] = (string) $arguments['table'];
            }
        }

        return $tables;
    }

    private function versionListener(): TranslationVersionListener
    {
        return new TranslationVersionListener(
            new TranslationVersioning($this->connection, $this->storage(), new ShopLanguages($this->connection)),
        );
    }

    private function fieldsListener(): TranslationFieldsListener
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);

        $slug = $this->createMock(Slug::class);
        $slug->method('generate')->willReturnCallback(static fn (string $text): string => strtolower(str_replace(' ', '-', $text)));

        return new TranslationFieldsListener(
            new ShopLanguages($this->connection),
            $this->storage(),
            new AliasFactory($slug, $this->connection),
            $security,
            new RedirectRecorder($this->connection, new DbalProductPageRepository($this->connection), new ShopLanguages($this->connection)),
        );
    }

    private function storage(): TranslationStorage
    {
        return new TranslationStorage($this->connection);
    }

    private function dc(string $table, int $id, string $field = ''): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnMap([['table', $table], ['id', $id], ['field', $field]]);
        $dc->method('getCurrentRecord')->willReturn([]);

        return $dc;
    }
}
