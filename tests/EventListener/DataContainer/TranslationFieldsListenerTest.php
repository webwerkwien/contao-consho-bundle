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
 * Against the real DCA and language files and an SQLite database.
 *
 * The DCA files instead of a rebuilt copy, because exactly their content
 * decides what must be removed when a field is copied — a copy in the test
 * would only check what one expected anyway.
 *
 * DC_Table itself does not run here. Whether Contao really keeps a field
 * without a column out of the UPDATE only shows when saving in the browser.
 */
class TranslationFieldsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::createCore($this->connection, 'tl_page');
        // A translated manufacturer alias reaches RedirectRecorder, which reads
        // the manufacturer's own alias and the startpoints.
        DcaSchema::create($this->connection, 'tl_consho_manufacturer');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');

        // From the DCA, as strict as the real table — both unique indexes.
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

            // Without an sql key, contao:migrate creates no column.
            self::assertArrayNotHasKey('sql', $fields[$field.'__en']);
            self::assertArrayNotHasKey('search', $fields[$field.'__en']);
            self::assertTrue($fields[$field.'__en']['eval']['doNotSaveEmpty']);
            self::assertSame([[$listener, 'load']], $fields[$field.'__en']['load_callback']);
            self::assertSame([[$listener, 'save']], $fields[$field.'__en']['save_callback']);
        }

        // Mandatory only conditionally (spec 7.3); unique checked the main table.
        self::assertArrayNotHasKey('mandatory', $fields['title__en']['eval']);
        self::assertArrayNotHasKey('unique', $fields['alias__en']['eval']);
        self::assertSame('alias', $fields['alias__en']['eval']['rgxp']);
        self::assertSame(['Bezeichnung (en)', 'Name des Produkts.'], $fields['title__en']['label']);
        // Takes over label and input of the original — no language texts of its own needed.
        self::assertSame('Meta-Beschreibung (en)', $fields['metaDescription__en']['label'][0]);
        self::assertTrue($fields['metaDescription__en']['eval']['decodeEntities']);

        // The original stays as it was.
        self::assertTrue($fields['title']['eval']['mandatory']);
        self::assertArrayHasKey('sql', $fields['title']);
    }

    public function testTheCallbacksOfTheOriginalFieldAreNotCopied(): void
    {
        // Contao attaches the #[AsCallback] callbacks before the onload. The
        // original's alias callback would otherwise generate a main table alias
        // from the main table title — in the translation field.
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
        // An empty shop selection on the variant means "like the main product".
        $this->shop(7, 'de', 'de', 'en');
        $this->assign(5, 7);

        $this->listener()->addFields($this->dc('tl_consho_product', 6, record: ['id' => 6, 'pid' => 5]));

        self::assertArrayHasKey('title__en', $GLOBALS['TL_DCA']['tl_consho_product']['fields']);
    }

    public function testEditingSeveralProductsOffersTheTranslationLanguagesOfAllShops(): void
    {
        // "Edit multiple": DC_Table only sets the ID in the record loop, after
        // the onload. Without a fallback there would not be a single language
        // section there, whichever products are selected.
        $this->shop(7, 'de', 'de', 'en');
        $this->shop(8, 'fr', 'fr', 'it');
        $this->assign(5, 7);

        $this->listener()->addFields($this->dc('tl_consho_product', 0));

        self::assertSame(['en', 'it'], $GLOBALS['TL_DCA']['tl_consho_product']['config']['conshoTranslationLanguages']);
    }

    /**
     * C-8: a shop translates its invoice texts into ITS languages — an Italian
     * footer does not belong in the form of a shop that never sells in Italian.
     * The palette is the real one from the DCA, so that the two fields really
     * arrive at the end.
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
        // The generated field cannot be enabled in any user group; it therefore
        // takes over the permission of the translated field.
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
        // write() replaces the whole block. Without overlaying the stored state,
        // every form that does not carry all fields would empty the others.
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

        // A manufacturer sits elsewhere in the path — the same alias is free.
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
        // Row ID 2, product ID 5: whoever excluded the product ID would find the
        // own row as a duplicate and reject the unchanged alias.
        $this->storage()->write('tl_consho_manufacturer', 1, 'en', ['title' => 'Maker']);
        $this->storage()->write('tl_consho_product', 5, 'en', ['title' => 'Bitters', 'alias' => 'bitters']);

        self::assertSame('', $this->listener()->save('bitters', $this->dc('tl_consho_product', 5, 'alias__en')));
    }

    public function testAnAliasIsRequiredWhenTheBlockHasContentButNoTitle(): void
    {
        // Spec 7.3: mandatory as soon as something is filled in for the language
        // — and without a title there is nothing it could be generated from.
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
        // "Edit multiple" sends several records in one request.
        $listener = $this->listener();
        $listener->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->save('Amaro', $this->dc('tl_consho_product', 6, 'title__en'));

        $listener->store($this->dc('tl_consho_product', 5));

        self::assertSame('Bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'title'));
        self::assertSame(0, $this->storage()->rowId('tl_consho_product', 6, 'en'));
    }

    public function testNachEinerGeaendertenSpracheWirdAuchDieNaechsteGeschrieben(): void
    {
        // A `$changed || …` in the wrong order skipped the second block as soon
        // as the first one had changed.
        $listener = $this->listener();
        $listener->save('Bitters', $this->dc('tl_consho_product', 5, 'title__en'));
        $listener->save('Amaro', $this->dc('tl_consho_product', 5, 'title__it'));

        $listener->store($this->dc('tl_consho_product', 5));

        self::assertSame('Bitters', $this->storage()->read('tl_consho_product', 5, 'en', 'title'));
        self::assertSame('Amaro', $this->storage()->read('tl_consho_product', 5, 'it', 'title'));
    }

    public function testAChangeToATranslationAloneAsksForANewVersion(): void
    {
        // The translation fields never go into the UPDATE of the main table. If
        // nothing else has changed, DC_Table::submit() stays without an UPDATE
        // and thus without a version — the change would be in none.
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
        // Otherwise every save would create a version, even without a change.
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
            // For real, through the same database: if a test here does reach it, it
            // fails loudly on missing tables instead of silently doing nothing.
            new RedirectRecorder($this->connection, new DbalProductPageRepository($this->connection), new ShopLanguages($this->connection)),
        );
    }

    private function storage(): TranslationStorage
    {
        return new TranslationStorage($this->connection);
    }

    /**
     * The counting loop of Contao\CoreBundle\Slug\Slug::generate(), so that the
     * duplicate check passed in really runs against the database.
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
        // DC_Table, not DataContainer: only there does createNewVersion exist.
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
