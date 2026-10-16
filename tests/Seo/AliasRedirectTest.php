<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Slug\Slug;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\AliasRestoreListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductAddressListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductAliasListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TranslationFieldsListener;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\DbalRedirectResolver;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressLookup;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Weiterleitungen, wenn sich der Alias eines einzelnen Produkts ändert.
 *
 * Abschnitt 4.4 der Spezifikation deckt die geänderte Pfadvorlage ab
 * (RedirectRecorderTest); hier geht es um denselben Mechanismus mit anderem
 * Auslöser — vom Maskenfeld über den Recorder bis zum Resolver.
 *
 * Aufbau: ein Shop mit zwei Startpunkten (de = Standardsprache, en), jeweils mit
 * Produktseite und verschiedener Vorlage, und ein zweiter Shop, dem das Produkt
 * nicht zugeordnet ist.
 */
class AliasRedirectTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_000_000;

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
        $this->page(123, pid: 1, alias: 'produkt', type: 'consho_product', template: '{alias}');
        $this->page(2, type: 'root', language: 'en', shop: 1, prefix: 'en');
        $this->page(124, pid: 2, alias: 'product', type: 'consho_product', template: '{manufacturer}/{alias}');

        // Ein zweiter Shop, dem Produkt 10 nicht zugeordnet ist.
        $this->insertShop(['id' => 2, 'fallbackLanguage' => 'de']);
        $this->page(3, type: 'root', language: 'de', shop: 2);
        $this->page(125, pid: 3, alias: 'artikel', type: 'consho_product', template: '{alias}');

        $this->insertManufacturer(['id' => 7, 'alias' => 'kraeuterhaus']);
        $this->product(10, 'schwedenbitter', manufacturer: 7);
        $this->product(11, 'melissengeist');
        $this->insertTranslation(['pid' => 10, 'ptable' => 'tl_consho_product', 'language' => 'en', 'title' => 'Herbal Bitters', 'alias' => 'herbal-bitters']);
        $this->insertTranslation(['pid' => 11, 'ptable' => 'tl_consho_product', 'language' => 'en', 'title' => 'Melissa Spirit', 'alias' => 'melissa-spirit']);
    }

    // ── Standard-Alias ──────────────────────────────────────────────────

    public function testAnAliasChangeRecordsTheOldAddressOncePerAffectedStartpoint(): void
    {
        /*
         * Nur der deutsche Startpunkt: Der englische adressiert das Produkt über
         * seinen übersetzten Alias, und Shop 2 führt das Produkt gar nicht.
         */
        $written = $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW);

        self::assertSame(1, $written);
        self::assertSame([[1, '/produkt/schwedenbitter.html', 10]], $this->recorded());
    }

    public function testAnUnchangedAliasRecordsNothing(): void
    {
        self::assertSame(0, $this->recorder()->recordProductAlias(10, 'schwedenbitter', self::NOW));
        self::assertSame([], $this->recorded());
    }

    public function testOtherProductsStayUntouched(): void
    {
        /*
         * Eine Zeile aus einer früheren Vorlagenänderung für Produkt 11 bleibt
         * stehen, und Produkt 11 bekommt keine neue — die Umbenennung eines
         * Artikels rechnet nicht den ganzen Katalog durch.
         */
        $this->redirect(1, '/produkt/melissengeist-alt.html', 11);

        $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW);

        self::assertSame(
            [[1, '/produkt/melissengeist-alt.html', 11], [1, '/produkt/schwedenbitter.html', 10]],
            $this->recorded(),
        );
    }

    public function testTheAddressIsBuiltWithThePagesCurrentTemplate(): void
    {
        $this->connection->update('tl_page', ['conshoPathTemplate' => '{manufacturer}/{alias}'], ['id' => 123]);

        $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW);

        self::assertSame([[1, '/produkt/kraeuterhaus/schwedenbitter.html', 10]], $this->recorded());
    }

    public function testAPageWithoutTemplateUsesTheDefaultOne(): void
    {
        // Wie der Routenbau: eine leere Vorlage heißt {alias}.
        $this->connection->update('tl_page', ['conshoPathTemplate' => ''], ['id' => 123]);

        $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW);

        self::assertSame([[1, '/produkt/schwedenbitter.html', 10]], $this->recorded());
    }

    public function testAStartpointWithoutLanguageUsesTheMainAlias(): void
    {
        // Ein einsprachiger Startpunkt ohne Sprachangabe: TranslationJoin behandelt
        // '' als Standardsprache, also zeigt er den Alias des Datensatzes.
        $this->connection->update('tl_page', ['language' => ''], ['id' => 1]);

        self::assertSame(1, $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW));
    }

    public function testAStartpointInATranslationLanguageWithoutTranslatedAliasIsNotAffected(): void
    {
        /*
         * Ohne übersetzten Alias fällt TranslationJoin zwar auf den Standard-Alias
         * zurück — aber VisibilityCriteria::translated() blendet das Produkt in
         * dieser Sprache ganz aus. Unter /en/… gab es also nie eine Adresse, die
         * wandern könnte.
         */
        $this->connection->delete('tl_consho_translation', ['pid' => 11]);

        $this->recorder()->recordProductAlias(11, 'melissengeist-klassik', self::NOW);

        self::assertSame([[1, '/produkt/melissengeist.html', 11]], $this->recorded());
    }

    public function testAVariantRecordsNothing(): void
    {
        // Eine Variante hat keine eigene Adresse — auch nicht mit eigener Shop-Zuordnung.
        $this->product(13, 'schwedenbitter-250', pid: 10);

        self::assertSame(0, $this->recorder()->recordProductAlias(13, 'schwedenbitter-500', self::NOW));
        self::assertSame([], $this->recorded());
    }

    public function testNothingForAProductThatDoesNotExist(): void
    {
        self::assertSame(0, $this->recorder()->recordProductAlias(999, 'irgendwas', self::NOW));
    }

    public function testNothingForAProductWithoutAliasSoFar(): void
    {
        // Ein frisch angelegtes Produkt hatte keine Adresse — sonst entstünde
        // eine Weiterleitung von der nackten Produktseite /produkt.html.
        $this->product(12, '');

        self::assertSame(0, $this->recorder()->recordProductAlias(12, 'neuheit', self::NOW));
    }

    public function testNothingForAStartpointWithoutProductPage(): void
    {
        $this->connection->delete('tl_page', ['id' => 123]);

        self::assertSame(0, $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW));
    }

    public function testNothingForAStartpointWithoutShop(): void
    {
        $this->connection->update('tl_page', ['consho_shop' => 0], ['id' => 1]);

        self::assertSame(0, $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW));
    }

    // ── Idempotenz und Rückkehr ─────────────────────────────────────────

    public function testRecordingTwiceStaysIdempotent(): void
    {
        // Zweimal derselbe Aufruf, bevor die Datenbank den neuen Wert hat.
        self::assertSame(1, $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW));
        self::assertSame(0, $this->recorder()->recordProductAlias(10, 'schwedenbitter-klassik', self::NOW + 10));
        self::assertCount(1, $this->recorded());
    }

    public function testReturningToAnEarlierAliasLeavesNoRowOnTodaysAddress(): void
    {
        /*
         * schwedenbitter → neu → schwedenbitter. Danach ist /produkt/schwedenbitter.html
         * wieder die heutige Adresse; eine Zeile dafür wäre eine Weiterleitung
         * des Produkts auf sich selbst.
         */
        $this->recorder()->recordProductAlias(10, 'neu', self::NOW);
        $this->rename(10, 'neu');
        $this->recorder()->recordProductAlias(10, 'schwedenbitter', self::NOW + 10);
        $this->rename(10, 'schwedenbitter');

        self::assertSame([[1, '/produkt/neu.html', 10]], $this->recorded());
        self::assertNull($this->resolver()->find('/produkt/schwedenbitter.html', 'example.org'));
        self::assertSame(10, $this->resolver()->find('/produkt/neu.html', 'example.org')?->productId);
    }

    public function testAnAddressTakenOverFromAnotherProductLosesItsOldRow(): void
    {
        /*
         * Produkt 11 hieß früher „kraeuter", die Zeile führt noch zu ihm. Nimmt
         * Produkt 10 den frei gewordenen Alias, gehört die Adresse jetzt 10.
         * Bliebe die Zeile, führte die alte Adresse von 10 später — wenn 10
         * wieder umbenannt wird — auf Produkt 11.
         */
        $this->redirect(1, '/produkt/kraeuter.html', 11);

        $this->recorder()->recordProductAlias(10, 'kraeuter', self::NOW);

        self::assertSame([[1, '/produkt/schwedenbitter.html', 10]], $this->recorded());
    }

    // ── Ende zu Ende ────────────────────────────────────────────────────

    public function testTheOldAddressLeadsToTheProductAndTheNewOneAnswersItself(): void
    {
        $this->recorder()->recordProductAlias(10, 'neu', self::NOW);
        $this->rename(10, 'neu');

        $target = $this->resolver()->find('/produkt/schwedenbitter.html', 'example.org');

        self::assertNotNull($target);
        self::assertSame(10, $target->productId);
        self::assertSame(1, $target->rootPageId);
        self::assertSame(1, $target->shopId);

        // Was ProductRedirectListener danach fragt: sichtbar, und unter dem neuen Alias zu finden.
        $lookup = new ProductAddressLookup($this->connection, new VisibilityCriteria());
        self::assertTrue($lookup->isVisible(10, 1, self::NOW));
        self::assertSame(10, $lookup->find(['alias' => 'neu'], 1, self::NOW, 'de', 'de'));
        self::assertNull($lookup->find(['alias' => 'schwedenbitter'], 1, self::NOW, 'de', 'de'));

        // Die neue Adresse selbst ist keine Weiterleitung.
        self::assertNull($this->resolver()->find('/produkt/neu.html', 'example.org'));
    }

    // ── Die Masken ──────────────────────────────────────────────────────

    public function testDieProduktmaskeZeichnetEinenGeaendertenAliasBeimSpeichernAuf(): void
    {
        /*
         * Der save_callback erzeugt nur den Alias. Aufgezeichnet wird für den
         * ganzen Speichervorgang in ProductAddressListener — sonst entstünde bei
         * Alias und Hersteller zugleich eine halb neue Adresse
         * (ProductAddressRedirectTest).
         */
        $alias = $this->productAliasListener()('schwedenbitter-klassik', $this->productDc(10, stored: 'schwedenbitter'));
        $this->submit(10, ['alias' => $alias]);

        self::assertSame('schwedenbitter-klassik', $alias);
        self::assertSame([[1, '/produkt/schwedenbitter.html', 10]], $this->recorded());
    }

    public function testDieProduktmaskeZeichnetBeiUnveraendertemAliasNichtsAuf(): void
    {
        $this->submit(10, ['alias' => 'schwedenbitter']);

        self::assertSame([], $this->recorded());
    }

    public function testDerUebersetzungsbereichZeichnetDieAlteAdresseBeimSpeichernAuf(): void
    {
        // Nur Übersetzungen geändert: kein UPDATE am Produkt, kein onbeforesubmit.
        $listener = $this->translationListener();
        $listener->save('swedish-bitters', $this->translationDc(10, 'alias__en'));
        $this->submit(10, [], $listener);

        self::assertSame([[2, '/en/product/kraeuterhaus/herbal-bitters.html', 10]], $this->recorded());
        self::assertSame('swedish-bitters', (new TranslationStorage($this->connection))->read('tl_consho_product', 10, 'en', 'alias'));
    }

    public function testDerUebersetzungsbereichZeichnetNichtsAufWennNurDerTitelWechselt(): void
    {
        $listener = $this->translationListener();
        $listener->save('Swedish Bitters', $this->translationDc(10, 'title__en'));
        $this->submit(10, [], $listener);

        self::assertSame([], $this->recorded());
    }

    // ── Wiederherstellen einer Version ──────────────────────────────────

    public function testEineWiederhergestellteVersionMitAnderemAliasZeichnetDieAlteAdresseAuf(): void
    {
        /*
         * Versions::restore() schreibt zuerst den UPDATE und ruft danach
         * onrestore_version_callback. Die alte Adresse steht dann nicht mehr in
         * der Datenbank — sie kommt aus dem Schnappschuss von onload.
         */
        $listener = $this->restoreListener();
        $listener->onLoad($this->productDc(10, stored: 'schwedenbitter'));

        $this->rename(10, 'schwedenbitter-alt');
        $listener->onRestoreVersion('tl_consho_product', 10, 3, []);

        self::assertSame([[1, '/produkt/schwedenbitter.html', 10]], $this->recorded());
        self::assertSame(10, $this->resolver()->find('/produkt/schwedenbitter.html', 'example.org')?->productId);
    }

    public function testEineWiederhergestellteVersionMitGleichemAliasZeichnetNichtsAuf(): void
    {
        $listener = $this->restoreListener();
        $listener->onLoad($this->productDc(10, stored: 'schwedenbitter'));
        $listener->onRestoreVersion('tl_consho_product', 10, 3, []);

        self::assertSame([], $this->recorded());
    }

    public function testEineWiederhergestellteVersionKehrtOhneVeralteteZeileZurueck(): void
    {
        $this->redirect(1, '/produkt/schwedenbitter-alt.html', 10);

        $listener = $this->restoreListener();
        $listener->onLoad($this->productDc(10, stored: 'schwedenbitter'));
        $this->rename(10, 'schwedenbitter-alt');
        $listener->onRestoreVersion('tl_consho_product', 10, 3, []);

        self::assertSame([[1, '/produkt/schwedenbitter.html', 10]], $this->recorded());
    }

    public function testEineWiederhergestellteUebersetzungMitAnderemAliasZeichnetAuf(): void
    {
        $listener = $this->restoreListener();
        $listener->onLoad($this->productDc(10, stored: 'schwedenbitter'));

        // TranslationVersioning::restore() hat die Übersetzung schon zurückgeschrieben.
        $this->connection->update('tl_consho_translation', ['alias' => 'swedish-bitters'], ['pid' => 10, 'ptable' => 'tl_consho_product']);
        $listener->onRestoreVersion('tl_consho_product', 10, 3, []);

        self::assertSame([[2, '/en/product/kraeuterhaus/herbal-bitters.html', 10]], $this->recorded());
    }

    public function testOhneWiederherstellungsAnfrageEntstehtKeinSchnappschuss(): void
    {
        /*
         * Versions::restore() ohne Maske davor — ein Befehl, ein Skript. Ohne
         * den vorigen Alias ist nichts zu rendern; lieber keine Zeile als eine
         * geratene. Schreibwege am Backend vorbei sind eine bewusste Grenze.
         */
        $listener = new AliasRestoreListener($this->connection, new RequestStack(), $this->recorder());
        $listener->onLoad($this->productDc(10, stored: 'schwedenbitter'));

        $this->rename(10, 'schwedenbitter-alt');
        $listener->onRestoreVersion('tl_consho_product', 10, 3, []);

        self::assertSame([], $this->recorded());
    }

    public function testDieAufzeichnungLaeuftNachDemZurueckschreibenDerUebersetzungen(): void
    {
        /*
         * TranslationVersionListener schreibt die Übersetzungen in seinem eigenen
         * onrestore_version-Callback zurück. Liefe dieser hier davor, sähe er den
         * übersetzten Alias noch im alten Stand. Contao sortiert Callbacks mit
         * Priorität ≤ 0 absteigend hinter die DCA-eigenen
         * (DataContainerCallbackListener::addCallbacks()).
         */
        $method = new \ReflectionMethod(AliasRestoreListener::class, 'onRestoreVersion');

        foreach ($method->getAttributes(AsCallback::class) as $attribute) {
            self::assertLessThan(0, $attribute->getArguments()['priority'] ?? 0);
        }

        self::assertNotEmpty($method->getAttributes(AsCallback::class));
    }

    // ── Hilfen ──────────────────────────────────────────────────────────

    private function restoreListener(): AliasRestoreListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/contao?do=consho_product&act=edit&id=10', 'POST', ['FORM_SUBMIT' => 'tl_version', 'version' => '3']));

        return new AliasRestoreListener($this->connection, $requests, $this->recorder());
    }

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

    private function productAliasListener(): ProductAliasListener
    {
        return new ProductAliasListener(new AliasFactory($this->createMock(Slug::class), $this->connection));
    }

    /**
     * Was DC_Table::submit() für einen Datensatz tut: onbeforesubmit, der eine
     * UPDATE, onsubmit mit Priorität 1, die Übersetzungen (0), onsubmit mit −1.
     * Ohne geänderte Spalte gibt es keinen UPDATE und kein onbeforesubmit.
     *
     * @param array<string, int|string> $columns
     */
    private function submit(int $id, array $columns, ?TranslationFieldsListener $translations = null): void
    {
        $listener = new ProductAddressListener($this->recorder());
        $dc = $this->translationDc($id);

        if ([] !== $columns) {
            $columns = $listener->rememberBeforeUpdate($columns, $dc);
            $this->connection->update('tl_consho_product', $columns, ['id' => $id]);
        }

        $listener->rememberBeforeTranslations($dc);
        $translations?->store($dc);
        $listener->recordMovedAddresses($dc);
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

    private function productDc(int $id, string $stored): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['id', $id], ['table', 'tl_consho_product']]);
        $dc->method('getActiveRecord')->willReturn(['id' => $id, 'title' => 'Schwedenbitter']);
        $dc->method('getCurrentRecord')->willReturn(['id' => $id, 'alias' => $stored]);

        return $dc;
    }

    private function translationDc(int $id, string $field = ''): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['table', 'tl_consho_product'], ['id', $id], ['field', $field]]);
        $dc->method('getCurrentRecord')->willReturn([]);

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
     * Was Contao nach den save_callbacks tut: der eine UPDATE am Ende der Anfrage.
     */
    private function rename(int $productId, string $alias): void
    {
        $this->connection->update('tl_consho_product', ['alias' => $alias], ['id' => $productId]);
    }

    private function redirect(int $rootId, string $path, int $productId): void
    {
        $this->connection->insert('tl_consho_url_redirect', ['pid' => $rootId, 'oldPath' => $path, 'productId' => $productId, 'createdAt' => 1]);
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

    private function product(int $id, string $alias, int $pid = 0, int $manufacturer = 0): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $pid, 'title' => $alias, 'alias' => $alias, 'manufacturer' => $manufacturer, 'published' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => 1]);
    }
}
