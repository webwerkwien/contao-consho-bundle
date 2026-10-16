<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\Slug\Slug;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\AliasRestoreListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CatalogAliasListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TranslationFieldsListener;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\DbalRedirectResolver;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Weiterleitungen, wenn sich der Alias eines Herstellers oder Produkttyps ändert.
 *
 * Anders als beim Produkt-Alias wandert hier nicht eine Adresse, sondern die
 * aller Produkte des Herstellers bzw. Typs — aber nur auf Startpunkten, deren
 * Vorlage den Platzhalter enthält.
 *
 * Aufbau:
 * - Shop 1 (Standardsprache de): Startpunkt 1 (de) und 2 (en), beide mit
 *   {manufacturer}/{alias}.
 * - Shop 2 (de): Startpunkt 3 mit {type}/{alias}.
 * - Hersteller 7 „kraeuterhaus" (Produkte 10, 11), Hersteller 8 „bienenhof"
 *   (Produkt 12). Typ 3 „tinktur" (10, 12), Typ 4 „salbe" (11).
 * - Variante 13 unter Produkt 10, gleicher Hersteller und Typ.
 */
class SegmentAliasRedirectTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_000_000;

    private const MANUFACTURER = 'tl_consho_manufacturer';

    private const TYPE = 'tl_consho_product_type';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                alias TEXT NOT NULL DEFAULT '',
                type TEXT NOT NULL DEFAULT 'regular',
                published INTEGER NOT NULL DEFAULT 0,
                dns TEXT NOT NULL DEFAULT '',
                urlPrefix TEXT NOT NULL DEFAULT '',
                urlSuffix TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                conshoPathTemplate TEXT NOT NULL DEFAULT ''
            )",
        );
        DcaSchema::create($this->connection, 'tl_consho_url_redirect');

        $this->insertShop(['id' => 1, 'fallbackLanguage' => 'de']);
        $this->page(1, type: 'root', language: 'de', shop: 1);
        $this->page(123, pid: 1, alias: 'produkt', type: 'consho_product', template: '{manufacturer}/{alias}');
        $this->page(2, type: 'root', language: 'en', shop: 1, prefix: 'en');
        $this->page(124, pid: 2, alias: 'product', type: 'consho_product', template: '{manufacturer}/{alias}');

        $this->insertShop(['id' => 2, 'fallbackLanguage' => 'de']);
        $this->page(3, type: 'root', language: 'de', shop: 2);
        $this->page(125, pid: 3, alias: 'artikel', type: 'consho_product', template: '{type}/{alias}');

        $this->insertManufacturer(['id' => 7, 'title' => 'Kräuterhaus', 'alias' => 'kraeuterhaus']);
        $this->insertManufacturer(['id' => 8, 'title' => 'Bienenhof', 'alias' => 'bienenhof']);
        $this->insertProductType(['id' => 3, 'title' => 'Tinktur', 'alias' => 'tinktur']);
        $this->insertProductType(['id' => 4, 'title' => 'Salbe', 'alias' => 'salbe']);

        $this->product(10, 'schwedenbitter', manufacturer: 7, type: 3);
        $this->product(11, 'melissengeist', manufacturer: 7, type: 4);
        $this->product(12, 'honig', manufacturer: 8, type: 3);
        $this->product(13, 'schwedenbitter-250', manufacturer: 7, type: 3, pid: 10);

        $this->insertTranslation(['pid' => 10, 'ptable' => 'tl_consho_product', 'language' => 'en', 'title' => 'Herbal Bitters', 'alias' => 'herbal-bitters']);
        $this->insertTranslation(['pid' => 11, 'ptable' => 'tl_consho_product', 'language' => 'en', 'title' => 'Melissa Spirit', 'alias' => 'melissa-spirit']);
        $this->insertTranslation(['pid' => 12, 'ptable' => 'tl_consho_product', 'language' => 'en', 'title' => 'Honey', 'alias' => 'honey']);
    }

    // ── Hersteller-Alias ────────────────────────────────────────────────

    public function testEinGeaenderterHerstellerAliasZeichnetDieAlteAdresseJedesSeinerProdukteAuf(): void
    {
        /*
         * Startpunkt 1 und 2 haben {manufacturer} in der Vorlage. Der englische
         * zeigt den Alias des Herstellers, weil es keine Übersetzung dafür gibt
         * (TranslationJoin::coalesce()). Startpunkt 3 hat nur {type}.
         * Produkt 12 gehört einem anderen Hersteller, 13 ist eine Variante.
         */
        $written = $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'kraeuterhaus-wien', self::NOW);

        self::assertSame(4, $written);
        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [1, '/produkt/kraeuterhaus/melissengeist.html', 11],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
                [2, '/en/product/kraeuterhaus/melissa-spirit.html', 11],
            ],
            $this->recorded(),
        );
    }

    public function testEinStartpunktOhneHerstellerInDerVorlageBleibtUnberuehrt(): void
    {
        $this->connection->update('tl_page', ['conshoPathTemplate' => '{alias}'], ['id' => 124]);

        $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'kraeuterhaus-wien', self::NOW);

        self::assertSame([1], $this->recordedStartpoints());
    }

    public function testProdukteEinesAnderenHerstellersBleibenUnberuehrt(): void
    {
        $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 8, 'bienenhof-wien', self::NOW);

        self::assertSame(
            [
                [1, '/produkt/bienenhof/honig.html', 12],
                [2, '/en/product/bienenhof/honey.html', 12],
            ],
            $this->recorded(),
        );
    }

    public function testEinUnveraenderterHerstellerAliasZeichnetNichtsAuf(): void
    {
        self::assertSame(0, $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'kraeuterhaus', self::NOW));
        self::assertSame([], $this->recorded());
    }

    public function testEinLeererNeuerHerstellerAliasZeichnetNichtsAuf(): void
    {
        self::assertSame(0, $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, '  ', self::NOW));
    }

    public function testEinStartpunktOhneShopWirdUebersprungen(): void
    {
        $this->connection->update('tl_page', ['consho_shop' => 0], ['id' => 1]);

        $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'kraeuterhaus-wien', self::NOW);

        self::assertSame([2], $this->recordedStartpoints());
    }

    public function testDieRueckkehrZuEinemAltenHerstellerAliasHinterlaesstKeineVeralteteZeile(): void
    {
        $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'neu', self::NOW);
        $this->renameRecord(self::MANUFACTURER, 7, 'neu');
        $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'kraeuterhaus', self::NOW + 10);
        $this->renameRecord(self::MANUFACTURER, 7, 'kraeuterhaus');

        self::assertSame(
            [
                [1, '/produkt/neu/schwedenbitter.html', 10],
                [1, '/produkt/neu/melissengeist.html', 11],
                [2, '/en/product/neu/herbal-bitters.html', 10],
                [2, '/en/product/neu/melissa-spirit.html', 11],
            ],
            $this->recorded(),
        );
        self::assertNull($this->resolver()->find('/produkt/kraeuterhaus/schwedenbitter.html', 'example.org'));
    }

    public function testEinHerstellerOhneBisherigenAliasLoestDieAdresseOhneSegmentAb(): void
    {
        /*
         * Hersteller aus der Zeit vor B-1 haben keinen Alias; die Vorlage lässt
         * das Segment dann weg (ProductPathTemplate::render()). „Aliase
         * generieren" setzt einen — die Adresse wandert von /produkt/x.html
         * nach /produkt/kraeuterhaus/x.html.
         */
        $this->renameRecord(self::MANUFACTURER, 7, '');

        $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'kraeuterhaus', self::NOW);

        self::assertContains([1, '/produkt/schwedenbitter.html', 10], $this->recorded());
    }

    public function testDasAlteZielFuehrtZumProdukt(): void
    {
        $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'neu', self::NOW);
        $this->renameRecord(self::MANUFACTURER, 7, 'neu');

        self::assertSame(11, $this->resolver()->find('/produkt/kraeuterhaus/melissengeist.html', 'example.org')?->productId);
        self::assertNull($this->resolver()->find('/produkt/neu/melissengeist.html', 'example.org'));
    }

    // ── Übersetzter Hersteller-Alias ────────────────────────────────────

    public function testEinUebersetzterHerstellerAliasVerdecktDieAenderungDesEigenen(): void
    {
        // Mit Übersetzung zeigt der englische Startpunkt „herb-house" — dort wandert nichts.
        $this->insertTranslation(['pid' => 7, 'ptable' => self::MANUFACTURER, 'language' => 'en', 'title' => 'Herb House', 'alias' => 'herb-house']);

        $this->recorder()->recordSegmentAlias(self::MANUFACTURER, 7, 'kraeuterhaus-wien', self::NOW);

        self::assertSame([1], $this->recordedStartpoints());
    }

    public function testEinGeaenderterUebersetzterHerstellerAliasBetrifftNurStartpunkteSeinerSprache(): void
    {
        $this->insertTranslation(['pid' => 7, 'ptable' => self::MANUFACTURER, 'language' => 'en', 'title' => 'Herb House', 'alias' => 'herb-house']);

        $this->recorder()->recordTranslatedSegmentAlias(self::MANUFACTURER, 7, 'en', 'herbal-house', self::NOW);

        self::assertSame(
            [
                [2, '/en/product/herb-house/herbal-bitters.html', 10],
                [2, '/en/product/herb-house/melissa-spirit.html', 11],
            ],
            $this->recorded(),
        );
    }

    public function testEinErsterUebersetzterHerstellerAliasLoestDenEigenenAliasAb(): void
    {
        /*
         * Anders als beim Produkt: Ohne Übersetzung ist das Produkt nicht
         * unsichtbar, der englische Startpunkt zeigte „kraeuterhaus". Diese
         * Adresse wandert jetzt.
         */
        $this->recorder()->recordTranslatedSegmentAlias(self::MANUFACTURER, 7, 'en', 'herb-house', self::NOW);

        self::assertSame(
            [
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
                [2, '/en/product/kraeuterhaus/melissa-spirit.html', 11],
            ],
            $this->recorded(),
        );
    }

    public function testEinUebersetzterHerstellerAliasInDerStandardspracheZeichnetNichtsAuf(): void
    {
        self::assertSame(0, $this->recorder()->recordTranslatedSegmentAlias(self::MANUFACTURER, 7, 'de', 'kraeuterhaus-de', self::NOW));
    }

    // ── Produkttyp-Alias ────────────────────────────────────────────────

    public function testEinGeaenderterProdukttypAliasZeichnetDieAlteAdresseJedesSeinerProdukteAuf(): void
    {
        $written = $this->recorder()->recordSegmentAlias(self::TYPE, 3, 'tropfen', self::NOW);

        self::assertSame(2, $written);
        self::assertSame(
            [
                [3, '/artikel/tinktur/schwedenbitter.html', 10],
                [3, '/artikel/tinktur/honig.html', 12],
            ],
            $this->recorded(),
        );
    }

    public function testEinStartpunktOhneProdukttypInDerVorlageBleibtUnberuehrt(): void
    {
        $this->connection->update('tl_page', ['conshoPathTemplate' => '{alias}'], ['id' => 125]);

        self::assertSame(0, $this->recorder()->recordSegmentAlias(self::TYPE, 3, 'tropfen', self::NOW));
    }

    public function testEinUnveraenderterProdukttypAliasZeichnetNichtsAuf(): void
    {
        self::assertSame(0, $this->recorder()->recordSegmentAlias(self::TYPE, 3, 'tinktur', self::NOW));
    }

    public function testDieRueckkehrZuEinemAltenProdukttypAliasHinterlaesstKeineVeralteteZeile(): void
    {
        $this->recorder()->recordSegmentAlias(self::TYPE, 3, 'tropfen', self::NOW);
        $this->renameRecord(self::TYPE, 3, 'tropfen');
        $this->recorder()->recordSegmentAlias(self::TYPE, 3, 'tinktur', self::NOW + 10);

        self::assertSame(
            [
                [3, '/artikel/tropfen/schwedenbitter.html', 10],
                [3, '/artikel/tropfen/honig.html', 12],
            ],
            $this->recorded(),
        );
    }

    public function testEineAndereTabelleZeichnetNichtsAuf(): void
    {
        // Eigenschaftsgruppen sind Filteradressen, keine Produktadressen.
        self::assertSame(0, $this->recorder()->recordSegmentAlias('tl_consho_property_group', 3, 'farbe', self::NOW));
    }

    // ── Die Masken ──────────────────────────────────────────────────────

    public function testDieHerstellerMaskeZeichnetAufSolangeDieDatenbankDenAltenAliasHat(): void
    {
        $alias = $this->catalogAliasListener()->onManufacturer('kraeuterhaus-wien', $this->dc(self::MANUFACTURER, 7, stored: 'kraeuterhaus'));

        self::assertSame('kraeuterhaus-wien', $alias);
        self::assertCount(4, $this->recorded());
    }

    public function testDieHerstellerMaskeZeichnetBeiUnveraendertemAliasNichtsAuf(): void
    {
        $this->catalogAliasListener()->onManufacturer('kraeuterhaus', $this->dc(self::MANUFACTURER, 7, stored: 'kraeuterhaus'));

        self::assertSame([], $this->recorded());
    }

    public function testDieProdukttypMaskeZeichnetAuf(): void
    {
        $this->catalogAliasListener()->onProductType('tropfen', $this->dc(self::TYPE, 3, stored: 'tinktur'));

        self::assertSame([3], $this->recordedStartpoints());
    }

    public function testDieUebersetzungsmaskeDesHerstellersZeichnetVorDemSchreibenAuf(): void
    {
        $listener = $this->translationListener();
        $listener->save('herb-house', $this->dc(self::MANUFACTURER, 7, field: 'alias__en'));
        $listener->store($this->dc(self::MANUFACTURER, 7));

        self::assertSame([2], $this->recordedStartpoints());
        self::assertContains([2, '/en/product/kraeuterhaus/herbal-bitters.html', 10], $this->recorded());
        self::assertSame('herb-house', (new TranslationStorage($this->connection))->read(self::MANUFACTURER, 7, 'en', 'alias'));
    }

    // ── Wiederherstellen einer Version ──────────────────────────────────

    public function testEineWiederhergestellteHerstellerVersionMitAnderemAliasZeichnetAuf(): void
    {
        $listener = $this->restoreListener();
        $listener->onLoad($this->dc(self::MANUFACTURER, 7));

        // Versions::restore(): erst der UPDATE, dann onrestore_version_callback.
        $this->renameRecord(self::MANUFACTURER, 7, 'kraeuterhaus-alt');
        $listener->onRestoreVersion(self::MANUFACTURER, 7, 2, []);

        self::assertCount(4, $this->recorded());
        self::assertContains([1, '/produkt/kraeuterhaus/schwedenbitter.html', 10], $this->recorded());
    }

    public function testEineWiederhergestellteUebersetzungDesProdukttypsZeichnetAuf(): void
    {
        $this->connection->update('tl_page', ['conshoPathTemplate' => '{type}/{alias}'], ['id' => 124]);
        $this->insertTranslation(['pid' => 3, 'ptable' => self::TYPE, 'language' => 'en', 'title' => 'Tincture', 'alias' => 'tincture']);

        $listener = $this->restoreListener();
        $listener->onLoad($this->dc(self::TYPE, 3));

        // TranslationVersioning::restore() hat die Übersetzung schon zurückgeschrieben.
        $this->connection->update('tl_consho_translation', ['alias' => 'drops'], ['ptable' => self::TYPE, 'pid' => 3]);
        $listener->onRestoreVersion(self::TYPE, 3, 2, []);

        self::assertSame(
            [
                [2, '/en/product/tincture/herbal-bitters.html', 10],
                [2, '/en/product/tincture/honey.html', 12],
            ],
            $this->recorded(),
        );
    }

    // ── Hilfen ──────────────────────────────────────────────────────────

    private function recorder(): RedirectRecorder
    {
        return new RedirectRecorder(
            $this->connection,
            new DbalProductPageRepository($this->connection),
            new ShopLanguages($this->connection),
        );
    }

    private function resolver(): DbalRedirectResolver
    {
        return new DbalRedirectResolver($this->connection);
    }

    private function catalogAliasListener(): CatalogAliasListener
    {
        return new CatalogAliasListener(new AliasFactory($this->createMock(Slug::class), $this->connection), $this->recorder());
    }

    private function translationListener(): TranslationFieldsListener
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);

        return new TranslationFieldsListener(
            new ShopLanguages($this->connection),
            new TranslationStorage($this->connection),
            new AliasFactory($this->createMock(Slug::class), $this->connection),
            $security,
            $this->recorder(),
        );
    }

    private function restoreListener(): AliasRestoreListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/contao?do=consho_manufacturer&act=edit', 'POST', ['FORM_SUBMIT' => 'tl_version', 'version' => '2']));

        return new AliasRestoreListener($this->connection, $requests, $this->recorder());
    }

    private function dc(string $table, int $id, string $stored = '', string $field = ''): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['id', $id], ['table', $table], ['field', $field]]);
        $dc->method('getActiveRecord')->willReturn(['id' => $id, 'title' => 'Titel']);
        $dc->method('getCurrentRecord')->willReturn(['id' => $id, 'alias' => $stored]);

        return $dc;
    }

    /**
     * @return list<array{int, string, int}>
     */
    private function recorded(): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row['pid'], (string) $row['oldPath'], (int) $row['productId']],
            $this->connection->fetchAllAssociative('SELECT pid, oldPath, productId FROM tl_consho_url_redirect ORDER BY id'),
        );
    }

    /**
     * @return list<int>
     */
    private function recordedStartpoints(): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT pid FROM tl_consho_url_redirect ORDER BY pid'));
    }

    /**
     * Was Contao nach den save_callbacks tut: der eine UPDATE am Ende der Anfrage.
     */
    private function renameRecord(string $table, int $id, string $alias): void
    {
        $this->connection->update($table, ['alias' => $alias], ['id' => $id]);
    }

    private function page(
        int $id,
        int $pid = 0,
        string $alias = '',
        string $type = 'regular',
        string $language = '',
        int $shop = 0,
        string $prefix = '',
        string $template = '',
    ): void {
        $this->connection->insert('tl_page', [
            'id' => $id,
            'pid' => $pid,
            'alias' => $alias,
            'type' => $type,
            'published' => 1,
            'urlPrefix' => $prefix,
            'urlSuffix' => 'root' === $type ? '.html' : '',
            'language' => $language,
            'consho_shop' => $shop,
            'conshoPathTemplate' => $template,
        ]);
    }

    private function product(int $id, string $alias, int $manufacturer, int $type, int $pid = 0): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $pid, 'title' => $alias, 'alias' => $alias, 'manufacturer' => $manufacturer, 'productType' => $type, 'published' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => 2]);
    }
}
