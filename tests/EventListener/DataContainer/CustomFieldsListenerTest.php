<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldCodec;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldValues;
use Contao\CoreBundle\Slug\Slug;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CustomFieldsListener;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\CustomFieldTranslations;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationWriter;

/**
 * Custom field values, ZW-2: one field in the product form per custom field
 * of the product type; the input is validated, collected and saved as JSON
 * on submit, with a version. A variant inherits what it leaves empty (Z2).
 */
class CustomFieldsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_custom_field', 'tl_consho_shop', 'tl_consho_product_shop', 'tl_consho_translation'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // Product 10 is in a shop with German and French: fr is its
        // translation language.
        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', urlPrefix TEXT NOT NULL DEFAULT '', urlSuffix TEXT NOT NULL DEFAULT '')");
        $this->connection->insert('tl_consho_shop', ['id' => 7, 'fallbackLanguage' => 'de']);
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => 7, 'language' => 'de']);
        $this->connection->insert('tl_page', ['type' => 'root', 'consho_shop' => 7, 'language' => 'fr']);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 10, 'shop' => 7]);

        $this->connection->insert('tl_consho_custom_field', ['id' => 1, 'alias' => 'jahrgang', 'title' => 'Jahrgang', 'type' => 'number']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 2, 'alias' => 'rebsorte', 'title' => 'Rebsorte', 'type' => 'text']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 3, 'alias' => 'bio', 'title' => 'Bio', 'type' => 'boolean']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 4, 'alias' => 'abfuellung', 'title' => 'Abfüllung', 'type' => 'date']);
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'title' => 'Wein', 'customFields' => serialize(['2', '1', '3', '4'])]);
        $this->connection->insert('tl_consho_product', ['id' => 10, 'pid' => 0, 'productType' => 1, 'customFieldValues' => '{"rebsorte":"Grüner Veltliner","bio":"1"}']);
        $this->connection->insert('tl_consho_product', ['id' => 11, 'pid' => 10, 'productType' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 20, 'pid' => 0, 'productType' => 0]);

        $GLOBALS['TL_DCA']['tl_consho_product'] = ['palettes' => ['__selector__' => ['x'], 'default' => '{title_legend},title', 'digital' => '{title_legend},title,digitalFile'], 'fields' => []];
        $GLOBALS['TL_LANG']['tl_consho_product'] = [
            'customFieldInherited' => 'Leer: %s',
            'customFieldInheritedNone' => 'Leer: dort leer.',
            'customFieldBoolean' => ['1' => 'Ja', '0' => 'Nein'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testJeFeldDesTypsEinEingabefeld(): void
    {
        // Monolingual: without a shop there is no translation language.
        $this->connection->delete('tl_consho_product_shop', ['pid' => 10]);
        $this->listener()->addFields($this->dc(10));
        $fields = $GLOBALS['TL_DCA']['tl_consho_product']['fields'];

        self::assertSame(['cf_rebsorte', 'cf_jahrgang', 'cf_bio', 'cf_abfuellung'], array_keys($fields));
        self::assertSame(['text', 'text', 'select', 'text'], array_column($fields, 'inputType'));
        self::assertSame('date', $fields['cf_abfuellung']['eval']['rgxp']);
        self::assertSame(['1', '0'], $fields['cf_bio']['options']);
        self::assertTrue($fields['cf_bio']['eval']['includeBlankOption'], 'leer heißt: nicht gesetzt (bei Varianten: erbt)');
        self::assertArrayNotHasKey('sql', $fields['cf_jahrgang'], 'keine Spalte — sonst legte contao:migrate sie an');
        self::assertSame('{title_legend},title;{custom_legend},cf_rebsorte,cf_jahrgang,cf_bio,cf_abfuellung', $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default']);
        self::assertSame('{title_legend},title,digitalFile;{custom_legend},cf_rebsorte,cf_jahrgang,cf_bio,cf_abfuellung', $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['digital']);
        self::assertSame(['x'], $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['__selector__']);
        self::assertSame(['Rebsorte', ''], $fields['cf_rebsorte']['label']);
    }

    public function testOhneTypOderOhneDatensatzNichts(): void
    {
        $this->listener()->addFields($this->dc(20));
        $this->listener()->addFields(null);

        self::assertSame([], $GLOBALS['TL_DCA']['tl_consho_product']['fields']);
        self::assertSame('{title_legend},title', $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default']);
    }

    /**
     * The variant names in its help text what it inherits (Z2).
     */
    public function testDieVarianteNenntDenGeerbtenWert(): void
    {
        $this->listener()->addFields($this->dc(11, record: ['pid' => 10]));
        $fields = $GLOBALS['TL_DCA']['tl_consho_product']['fields'];

        self::assertSame('Leer: Grüner Veltliner', $fields['cf_rebsorte']['label'][1]);
        self::assertSame('Leer: Ja', $fields['cf_bio']['label'][1]);
        self::assertSame('Leer: dort leer.', $fields['cf_jahrgang']['label'][1]);
    }

    /**
     * The inherited date stands in the help text the way the date field shows
     * it — not "2024-03-01" next to "01.03.2024".
     */
    public function testDasGeerbteDatumImFormatDerInstallation(): void
    {
        $saved = $GLOBALS['TL_CONFIG']['dateFormat'] ?? null;
        $GLOBALS['TL_CONFIG']['dateFormat'] = 'd.m.Y';
        $this->connection->update('tl_consho_product', ['customFieldValues' => '{"abfuellung":"2024-03-01"}'], ['id' => 10]);

        try {
            $this->listener()->addFields($this->dc(11, record: ['pid' => 10]));

            self::assertSame('Leer: 01.03.2024', $GLOBALS['TL_DCA']['tl_consho_product']['fields']['cf_abfuellung']['label'][1]);
        } finally {
            $GLOBALS['TL_CONFIG']['dateFormat'] = $saved;
        }
    }

    /**
     * The generated fields cannot be enabled in the user group — they follow
     * the permission of the customFieldValues column.
     */
    public function testDasRechtDerSpalte(): void
    {
        $this->listener(false)->addFields($this->dc(10));
        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_product']['fields']['cf_rebsorte']['exclude']);

        unset($GLOBALS['TL_DCA']['tl_consho_product']['config']);
        $this->listener(true)->addFields($this->dc(10));
        self::assertFalse($GLOBALS['TL_DCA']['tl_consho_product']['fields']['cf_rebsorte']['exclude']);
    }

    public function testSpeichernMitVersion(): void
    {
        $listener = $this->listener();
        $listener->addFields($this->dc(10));

        self::assertSame('Grüner Veltliner', $listener->load(null, $this->dc(10, 'cf_rebsorte')));
        self::assertSame('', $listener->save('2021', $this->dc(10, 'cf_jahrgang')), 'nichts in die Hauptspalte');
        self::assertSame('', $listener->save('', $this->dc(10, 'cf_bio')));
        self::assertSame('', $listener->save('Riesling', $this->dc(10, 'cf_rebsorte')));

        $dc = $this->dc(10);
        $dc->expects(self::once())->method('__set')->with('createNewVersion', true);
        $listener->store($dc);

        self::assertSame('{"rebsorte":"Riesling","jahrgang":"2021"}', $this->stored(10));
    }

    public function testUnveraendertKeineVersion(): void
    {
        $listener = $this->listener();
        $listener->addFields($this->dc(10));
        $listener->save('Grüner Veltliner', $this->dc(10, 'cf_rebsorte'));
        $listener->save('1', $this->dc(10, 'cf_bio'));

        $dc = $this->dc(10);
        $dc->expects(self::never())->method('__set');
        $listener->store($dc);

        self::assertSame('{"rebsorte":"Grüner Veltliner","bio":"1"}', $this->stored(10));
    }

    /**
     * Contao shows an invalid number at the field; nothing is collected.
     */
    public function testEineFalscheZahlAmFeld(): void
    {
        $listener = $this->listener();
        $listener->addFields($this->dc(10));

        try {
            $listener->save('12 %', $this->dc(10, 'cf_jahrgang'));
            self::fail('nicht abgelehnt');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('jahrgang: "12 %" is no number (e.g. 12 or 12.5).', $e->getMessage());
        }

        $listener->store($this->dc(10));
        self::assertSame('{"rebsorte":"Grüner Veltliner","bio":"1"}', $this->stored(10));
    }

    /**
     * The date widget delivers a timestamp and shows one — what is saved is
     * YYYY-MM-DD. In the installation's time zone: midnight in Vienna is still
     * the previous day in UTC (ZW-K — in UTC no test saw the difference).
     */
    public function testDatumHinUndZurueck(): void
    {
        $saved = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            $listener = $this->listener();
            $listener->addFields($this->dc(10));
            $timestamp = (new \DateTimeImmutable('2024-03-01 00:00:00'))->getTimestamp();
            self::assertSame('2024-02-29', gmdate('Y-m-d', $timestamp), 'der Fall, um den es geht');

            $listener->save((string) $timestamp, $this->dc(10, 'cf_abfuellung'));
            $listener->store($this->dc(10));

            self::assertStringContainsString('"abfuellung":"2024-03-01"', $this->stored(10));
            self::assertSame($timestamp, $listener->load(null, $this->dc(10, 'cf_abfuellung')));
        } finally {
            date_default_timezone_set($saved);
        }
    }

    public function testDieVarianteSpeichertNurIhreEigenen(): void
    {
        $listener = $this->listener();
        $listener->addFields($this->dc(11, record: ['pid' => 10]));
        $listener->save('', $this->dc(11, 'cf_rebsorte'));
        $listener->save('2022', $this->dc(11, 'cf_jahrgang'));
        $listener->store($this->dc(11));

        self::assertSame('{"jahrgang":"2022"}', $this->stored(11));
        self::assertSame('{"rebsorte":"Grüner Veltliner","bio":"1"}', $this->stored(10), 'das Hauptprodukt bleibt');
    }

    /**
     * Z1: text fields get a field of their own per translation language;
     * number, date and yes/no do not — they read the same in every language.
     */
    public function testTextfelderJeSprache(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_product']['custom_legend'] = 'Zusatzfelder';
        $this->listener()->addFields($this->dc(10));
        $fields = $GLOBALS['TL_DCA']['tl_consho_product']['fields'];

        self::assertSame(['cf_rebsorte', 'cf_jahrgang', 'cf_bio', 'cf_abfuellung', 'cf_rebsorte__fr'], array_keys($fields));
        self::assertSame(['Rebsorte (fr)', ''], $fields['cf_rebsorte__fr']['label']);
        self::assertStringEndsWith(';{custom_legend},cf_rebsorte,cf_jahrgang,cf_bio,cf_abfuellung;{custom_fr_legend:hide},cf_rebsorte__fr', $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default']);
        self::assertSame('Zusatzfelder (fr)', $GLOBALS['TL_LANG']['tl_consho_product']['custom_fr_legend']);
    }

    public function testDieUebersetzungImSprachblock(): void
    {
        $listener = $this->listener();
        $listener->addFields($this->dc(10));

        self::assertSame('', $listener->save('Riesling (FR)', $this->dc(10, 'cf_rebsorte__fr')));
        self::assertSame('', $listener->save('2021', $this->dc(10, 'cf_jahrgang')));

        $dc = $this->dc(10);
        $dc->expects(self::once())->method('__set')->with('createNewVersion', true);
        $listener->store($dc);

        self::assertSame('{"rebsorte":"Riesling (FR)"}', $this->connection->fetchOne("SELECT customFieldValues FROM tl_consho_translation WHERE ptable = 'tl_consho_product' AND pid = 10 AND language = 'fr'"));
        self::assertSame('Riesling (FR)', $listener->load(null, $this->dc(10, 'cf_rebsorte__fr')));
        self::assertSame('{"rebsorte":"Grüner Veltliner","bio":"1","jahrgang":"2021"}', $this->stored(10), 'der eigene Wert getrennt davon');
    }

    public function testNurDieUebersetzungGeaendertGibtEineVersion(): void
    {
        $listener = $this->listener();
        $listener->addFields($this->dc(10));
        $listener->save('Riesling (FR)', $this->dc(10, 'cf_rebsorte__fr'));

        $dc = $this->dc(10);
        $dc->expects(self::once())->method('__set')->with('createNewVersion', true);
        $listener->store($dc);
    }

    public function testEineZahlHatKeineUebersetzung(): void
    {
        $listener = $this->listener();
        $listener->addFields($this->dc(10));

        self::assertSame('x', $listener->save('x', $this->dc(10, 'cf_jahrgang__fr')), 'kein Feld von uns');
        self::assertSame('y', $listener->load('y', $this->dc(10, 'cf_jahrgang__fr')));
    }

    public function testFremdeFelderBleibenUnberuehrt(): void
    {
        $listener = $this->listener();
        $listener->addFields($this->dc(10));

        self::assertSame('x', $listener->save('x', $this->dc(10, 'title')));
        self::assertSame('y', $listener->load('y', $this->dc(10, 'title')));
    }

    private function stored(int $id): string|null
    {
        $value = $this->connection->fetchOne('SELECT customFieldValues FROM tl_consho_product WHERE id = ?', [$id]);

        return false === $value || null === $value ? null : (string) $value;
    }

    private function listener(bool $mayEdit = true): CustomFieldsListener
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($mayEdit);

        $values = new CustomFieldValues($this->connection, new CustomFieldCodec());
        $storage = new TranslationStorage($this->connection);
        $languages = new ShopLanguages($this->connection);
        $writer = new TranslationWriter($storage, new AliasFactory($this->createMock(Slug::class), $this->connection), new RedirectRecorder($this->connection, new DbalProductPageRepository($this->connection), $languages), $languages);

        return new CustomFieldsListener($values, $this->connection, $security, new CustomFieldTranslations($storage, $writer, $values, new CustomFieldCodec()));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function dc(int $id, string $field = '', array $record = []): DC_Table&MockObject
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['table', 'tl_consho_product'], ['id', $id], ['field', $field]]);
        $dc->method('getCurrentRecord')->willReturn($record);

        return $dc;
    }
}
