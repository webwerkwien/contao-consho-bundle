<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Slug\Slug;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TranslationFieldsListener;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Gegen die echten DCA- und Sprachdateien und eine SQLite-Datenbank.
 *
 * Die DCA-Dateien statt einer nachgebauten Kopie, weil genau ihr Inhalt
 * entscheidet, was beim Kopieren eines Felds entfernt werden muss — eine Kopie
 * im Test würde nur prüfen, was man ohnehin schon erwartet hat.
 *
 * DC_Table selbst läuft hier nicht. Ob Contao ein Feld ohne Spalte wirklich
 * aus dem UPDATE hält, zeigt erst das Speichern im Browser.
 */
class TranslationFieldsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', urlPrefix TEXT NOT NULL DEFAULT '', urlSuffix TEXT NOT NULL DEFAULT '')",
        );
        // A translated manufacturer alias reaches RedirectRecorder, which reads
        // the manufacturer's own alias and the startpoints.
        DcaSchema::create($this->connection, 'tl_consho_manufacturer');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');

        // Aus der DCA, so streng wie die echte Tabelle — beide Unique-Indizes.
        DcaSchema::create($this->connection, 'tl_consho_translation');

        require __DIR__.'/../../../contao/languages/de/default.php';
        require __DIR__.'/../../../contao/languages/de/tl_consho_product.php';
        require __DIR__.'/../../../contao/dca/tl_consho_product.php';
        require __DIR__.'/../../../contao/dca/tl_consho_manufacturer.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testAMonolingualShopLeavesTheMaskExactlyAsItWas(): void
    {
        $this->shop(7, 'de', 'de');
        $this->assign(5, 7);

        $before = $GLOBALS['TL_DCA']['tl_consho_product'];

        $this->listener()->addFields($this->dc('tl_consho_product', 5));

        self::assertSame($before, $GLOBALS['TL_DCA']['tl_consho_product']);
    }

    public function testASecondLanguageAddsFieldsWithoutAColumnOfTheirOwn(): void
    {
        $this->shop(7, 'de', 'de', 'en');
        $this->assign(5, 7);

        $listener = $this->listener();
        $listener->addFields($this->dc('tl_consho_product', 5));

        $fields = $GLOBALS['TL_DCA']['tl_consho_product']['fields'];

        foreach (['title', 'alias', 'teaser', 'metaDescription', 'description', 'safetyWarnings', 'safetyInformation'] as $field) {
            self::assertArrayHasKey($field.'__en', $fields);

            // Ohne sql-Schlüssel legt contao:migrate keine Spalte an.
            self::assertArrayNotHasKey('sql', $fields[$field.'__en']);
            self::assertArrayNotHasKey('search', $fields[$field.'__en']);
            self::assertTrue($fields[$field.'__en']['eval']['doNotSaveEmpty']);
            self::assertSame([[$listener, 'load']], $fields[$field.'__en']['load_callback']);
            self::assertSame([[$listener, 'save']], $fields[$field.'__en']['save_callback']);
        }

        // Pflicht nur bedingt (Spezifikation 7.3); unique prüfte die Haupttabelle.
        self::assertArrayNotHasKey('mandatory', $fields['title__en']['eval']);
        self::assertArrayNotHasKey('unique', $fields['alias__en']['eval']);
        self::assertSame('alias', $fields['alias__en']['eval']['rgxp']);
        self::assertSame(['Bezeichnung (en)', 'Name des Produkts.'], $fields['title__en']['label']);
        // Übernimmt Beschriftung und Eingabe des Originals — keine eigenen Sprachtexte nötig.
        self::assertSame('Meta-Beschreibung (en)', $fields['metaDescription__en']['label'][0]);
        self::assertTrue($fields['metaDescription__en']['eval']['decodeEntities']);

        // Das Original bleibt, wie es war.
        self::assertTrue($fields['title']['eval']['mandatory']);
        self::assertArrayHasKey('sql', $fields['title']);
    }

    public function testTheCallbacksOfTheOriginalFieldAreNotCopied(): void
    {
        // Contao hängt die #[AsCallback]-Callbacks vor dem onload ein. Der
        // Alias-Callback des Originals würde sonst einen Haupttabellen-Alias
        // aus dem Haupttabellen-Titel erzeugen — im Übersetzungsfeld.
        $GLOBALS['TL_DCA']['tl_consho_product']['fields']['alias']['save_callback'] = [['ProductAliasListener', '__invoke']];

        $this->shop(7, 'de', 'de', 'en');
        $this->assign(5, 7);

        $listener = $this->listener();
        $listener->addFields($this->dc('tl_consho_product', 5));

        self::assertSame([[$listener, 'save']], $GLOBALS['TL_DCA']['tl_consho_product']['fields']['alias__en']['save_callback']);
        self::assertSame([['ProductAliasListener', '__invoke']], $GLOBALS['TL_DCA']['tl_consho_product']['fields']['alias']['save_callback']);
    }

    public function testEachLanguageGetsACollapsedSectionWithTheAliasLast(): void
    {
        $this->shop(7, 'de', 'de', 'en');
        $this->assign(5, 7);

        $this->listener()->addFields($this->dc('tl_consho_product', 5));

        self::assertStringEndsWith(
            ';{consho_translation_en_legend:hide},title__en,teaser__en,metaDescription__en,description__en,safetyWarnings__en,safetyInformation__en,alias__en',
            $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'],
        );
        self::assertSame('Übersetzung: en', $GLOBALS['TL_LANG']['tl_consho_product']['consho_translation_en_legend']);
        self::assertSame(['en'], $GLOBALS['TL_DCA']['tl_consho_product']['config']['conshoTranslationLanguages']);
    }

    public function testASecondCallDoesNotAddTheSectionTwice(): void
    {
        $this->shop(7, 'de', 'de', 'en');
        $this->assign(5, 7);

        $listener = $this->listener();
        $listener->addFields($this->dc('tl_consho_product', 5));
        $listener->addFields($this->dc('tl_consho_product', 5));

        self::assertSame(1, substr_count($GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'], 'consho_translation_en_legend'));
    }

    public function testAVariantWithoutShopsOfItsOwnOffersTheLanguagesOfItsMainProduct(): void
    {
        // Leere Shop-Auswahl an der Variante heißt „wie das Hauptprodukt".
        $this->shop(7, 'de', 'de', 'en');
        $this->assign(5, 7);

        $this->listener()->addFields($this->dc('tl_consho_product', 6, record: ['id' => 6, 'pid' => 5]));

        self::assertArrayHasKey('title__en', $GLOBALS['TL_DCA']['tl_consho_product']['fields']);
    }

    public function testEditingSeveralProductsOffersTheTranslationLanguagesOfAllShops(): void
    {
        // „Mehrere bearbeiten": DC_Table setzt die ID erst in der Datensatz-
        // schleife, nach dem onload. Ohne Rückfall gäbe es dort keinen einzigen
        // Sprachabschnitt, gleich welche Produkte ausgewählt sind.
        $this->shop(7, 'de', 'de', 'en');
        $this->shop(8, 'fr', 'fr', 'it');
        $this->assign(5, 7);

        $this->listener()->addFields($this->dc('tl_consho_product', 0));

        self::assertSame(['en', 'it'], $GLOBALS['TL_DCA']['tl_consho_product']['config']['conshoTranslationLanguages']);
    }

    /**
     * C-8: Ein Shop übersetzt seine Rechnungstexte in SEINE Sprachen — eine
     * italienische Fußzeile gehört nicht in die Maske eines Shops, der nie
     * italienisch verkauft. Die Palette ist die echte aus der DCA, damit die
     * beiden Felder auch wirklich hinten ankommen.
     */
    public function testEinShopBietetNurSeineEigenenSprachenAn(): void
    {
        require __DIR__.'/../../../contao/dca/tl_consho_shop.php';
        $this->shop(7, 'de', 'de', 'en');
        $this->shop(8, 'fr', 'fr', 'it');

        $this->listener()->addFields($this->dc('tl_consho_shop', 7));

        self::assertSame(['en'], $GLOBALS['TL_DCA']['tl_consho_shop']['config']['conshoTranslationLanguages']);
        self::assertStringEndsWith(
            ';{consho_translation_en_legend:hide},invoiceFooter__en,invoiceReverseChargeNote__en',
            $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default'],
        );
    }

    public function testOtherTablesOfferTheTranslationLanguagesOfAllShops(): void
    {
        $this->shop(7, 'de', 'de', 'en');
        $this->shop(8, 'fr', 'fr', 'it');

        $this->listener()->addFields($this->dc('tl_consho_manufacturer', 3));

        self::assertSame(['en', 'it'], $GLOBALS['TL_DCA']['tl_consho_manufacturer']['config']['conshoTranslationLanguages']);
        self::assertStringEndsWith(
            ';{consho_translation_it_legend:hide},title__it,alias__it',
            $GLOBALS['TL_DCA']['tl_consho_manufacturer']['palettes']['default'],
        );
    }

    public function testATranslationIsOnlyEditableByWhoMayEditTheOriginalField(): void
    {
        // Das erzeugte Feld kann in keiner Benutzergruppe freigegeben werden;
        // es übernimmt deshalb das Recht am übersetzten Feld.
        $this->shop(7, 'de', 'de', 'en');
        $this->assign(5, 7);

        $this->listener(mayEditField: false)->addFields($this->dc('tl_consho_product', 5));

        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_product']['fields']['title__en']['exclude']);
    }

    public function testATranslationIsEditableForWhoMayEditTheOriginalField(): void
    {
        $this->shop(7, 'de', 'de', 'en');
        $this->assign(5, 7);

        $this->listener(mayEditField: true)->addFields($this->dc('tl_consho_product', 5));

        self::assertFalse($GLOBALS['TL_DCA']['tl_consho_product']['fields']['title__en']['exclude']);
    }

    public function testLoadReadsTheTranslation(): void
    {
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters']);

        self::assertSame('Bitters', $this->listener()->load(null, $this->dc('tl_consho_product', 5, 'title__en')));
    }

    public function testSaveKeepsTheValueOutOfTheMainTable(): void
    {
        self::assertSame('', $this->listener()->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en')));
    }

    public function testStoreKeepsWhatWasNotSubmitted(): void
    {
        // write() ersetzt den ganzen Block. Ohne das Überlagern mit dem
        // gespeicherten Stand leerte jedes Formular, das nicht alle Felder
        // trägt, die übrigen.
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters', 'teaser' => 'Kept']);

        $listener = $this->listener();
        $listener->save('Herbal Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->store($this->dc('tl_consho_product', 5));

        self::assertSame(
            ['title' => 'Herbal Bitters', 'alias' => 'bitters', 'teaser' => 'Kept'],
            array_intersect_key($this->storage()->readAll('tl_consho_product', 5, 'en'), ['title' => 1, 'alias' => 1, 'teaser' => 1]),
        );
    }

    public function testAnEmptiedBlockDeletesTheRow(): void
    {
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters', 'teaser' => 'Short']);

        $listener = $this->listener();

        foreach (['title', 'teaser', 'metaDescription', 'description', 'safetyWarnings', 'safetyInformation', 'alias'] as $field) {
            $listener->save('', $this->dc('tl_consho_product', 5, $field.'__en'));
        }

        $listener->store($this->dc('tl_consho_product', 5));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));
    }

    public function testAFilledBlockWithoutAliasGetsOneFromItsTitle(): void
    {
        $listener = $this->listener();
        $listener->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->save('', $this->dc('tl_consho_product', 5, 'alias__en'));
        $listener->store($this->dc('tl_consho_product', 5));

        self::assertSame('bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'alias'));
    }

    public function testTheGeneratedAliasIsUniqueWithinTableAndLanguage(): void
    {
        $this->storage()->write('tl_consho_product', 9, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        $listener = $this->listener();

        $listener->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->store($this->dc('tl_consho_product', 5));

        // Ein Hersteller steht an anderer Stelle im Pfad — derselbe Alias ist frei.
        $listener->save('Bitters', $this->dc('tl_consho_manufacturer', 4, 'title__en'));
        $listener->store($this->dc('tl_consho_manufacturer', 4));

        self::assertSame('bitters-2', $this->storage()->read('tl_consho_product', 5, 'en', 'alias'));
        self::assertSame('bitters', $this->storage()->read('tl_consho_manufacturer', 4, 'en', 'alias'));
    }

    public function testATypedAliasTakenByAnotherProductIsRefused(): void
    {
        $this->storage()->write('tl_consho_product', 9, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        $this->expectException(\RuntimeException::class);

        $this->listener()->save('bitters', $this->dc('tl_consho_product', 5, 'alias__en'));
    }

    public function testTheRowBeingSavedDoesNotBlockItsOwnAlias(): void
    {
        // Zeilen-ID 2, Produkt-ID 5: Wer die Produkt-ID ausschlösse, fände die
        // eigene Zeile als Dublette und lehnte den unveränderten Alias ab.
        $this->storage()->write('tl_consho_manufacturer', 1, 'en', ['title' => 'Maker']);
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        self::assertSame('', $this->listener()->save('bitters', $this->dc('tl_consho_product', 5, 'alias__en')));
    }

    public function testAnAliasIsRequiredWhenTheBlockHasContentButNoTitle(): void
    {
        // Spezifikation 7.3: Pflicht, sobald in der Sprache etwas ausgefüllt
        // ist — und ohne Titel gibt es nichts, woraus er entstehen könnte.
        $listener = $this->listener();
        $listener->save('Only a teaser', $this->dc('tl_consho_product', 5, 'teaser__en'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('„en"');

        $listener->save('', $this->dc('tl_consho_product', 5, 'alias__en'));
    }

    public function testStoreForgetsTheSubmittedValuesAfterWriting(): void
    {
        $listener = $this->listener();
        $listener->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->store($this->dc('tl_consho_product', 5));

        $this->connection->executeStatement('DELETE FROM tl_consho_translation');
        $listener->store($this->dc('tl_consho_product', 5));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));
    }

    public function testRecordsSubmittedTogetherAreStoredApart(): void
    {
        // „Mehrere bearbeiten" schickt mehrere Datensätze in einer Anfrage.
        $listener = $this->listener();
        $listener->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->save('Amaro', $this->dc('tl_consho_product', 6, 'title__en'));

        $listener->store($this->dc('tl_consho_product', 5));

        self::assertSame('Bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'title'));
        self::assertSame(0, $this->storage()->rowId('tl_consho_product', 6, 'en'));
    }

    public function testNachEinerGeaendertenSpracheWirdAuchDieNaechsteGeschrieben(): void
    {
        // Ein `$changed || …` in falscher Reihenfolge übersprang den zweiten
        // Block, sobald der erste geändert war.
        $listener = $this->listener();
        $listener->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->save('Amaro', $this->dc('tl_consho_product', 5, 'title__it'));

        $listener->store($this->dc('tl_consho_product', 5));

        self::assertSame('Bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'title'));
        self::assertSame('Amaro', $this->storage()->read('tl_consho_product', 5, 'it', 'title'));
    }

    public function testAChangeToATranslationAloneAsksForANewVersion(): void
    {
        // Die Übersetzungsfelder kommen nie ins UPDATE der Haupttabelle. Ist
        // sonst nichts geändert, bleibt DC_Table::submit() ohne UPDATE und
        // damit ohne Version — die Änderung stünde in keiner.
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        $listener = $this->listener();
        $listener->save('Herbal Bitters', $this->dc('tl_consho_product', 5, 'title__en'));

        $dc = $this->dc('tl_consho_product', 5);
        $dc->expects(self::once())->method('__set')->with('createNewVersion', true);

        $listener->store($dc);
    }

    public function testEmptyingATranslationAsksForANewVersion(): void
    {
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        $listener = $this->listener();

        foreach (['title', 'teaser', 'metaDescription', 'description', 'safetyWarnings', 'safetyInformation', 'alias'] as $field) {
            $listener->save('', $this->dc('tl_consho_product', 5, $field.'__en'));
        }

        $dc = $this->dc('tl_consho_product', 5);
        $dc->expects(self::once())->method('__set')->with('createNewVersion', true);

        $listener->store($dc);
    }

    public function testAnUnchangedTranslationDoesNotAskForANewVersion(): void
    {
        // Sonst entstünde bei jedem Speichern eine Version, auch ohne Änderung.
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        $listener = $this->listener();
        $listener->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->save('bitters', $this->dc('tl_consho_product', 5, 'alias__en'));

        $dc = $this->dc('tl_consho_product', 5);
        $dc->expects(self::never())->method('__set');

        $listener->store($dc);
    }

    private function listener(bool $mayEditField = true): TranslationFieldsListener
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($mayEditField);

        return new TranslationFieldsListener(
            new ShopLanguages($this->connection),
            $this->storage(),
            new AliasFactory($this->countingSlug(), $this->connection),
            $security,
            // Echt über dieselbe Datenbank: Erreicht ihn ein Test hier doch,
            // scheitert er laut an fehlenden Tabellen statt still nichts zu tun.
            new RedirectRecorder($this->connection, new DbalProductPageRepository($this->connection), new ShopLanguages($this->connection)),
        );
    }

    private function storage(): TranslationStorage
    {
        return new TranslationStorage($this->connection);
    }

    /**
     * Die Zählschleife von Contao\CoreBundle\Slug\Slug::generate(), damit die
     * übergebene Dublettenprüfung wirklich gegen die Datenbank läuft.
     */
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

    /**
     * @param array<string, int|string> $record
     */
    private function dc(string $table, int $id, string $field = '', array $record = []): DC_Table&MockObject
    {
        // DC_Table, nicht DataContainer: Nur dort gibt es createNewVersion.
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['table', $table], ['id', $id], ['field', $field]]);
        $dc->method('getCurrentRecord')->willReturn($record);

        return $dc;
    }

    private function shop(int $id, string $fallback, string ...$languages): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'fallbackLanguage' => $fallback]);

        foreach ($languages as $language) {
            $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => $id, 'language' => $language]);
        }
    }

    private function assign(int $productId, int $shopId): void
    {
        $this->connection->insert('tl_consho_product_shop', ['pid' => $productId, 'shop' => $shopId]);
    }
}
