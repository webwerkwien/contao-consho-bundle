<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetail;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRow;
use Webwerkwien\ContaoConshoBundle\Catalog\PropertyView;

/**
 * Gegen eine echte Datenbank — das Repository ist eine SQL-Abfrage, und eine
 * Zusicherung über den Abfragetext bewiese nichts über ihr Ergebnis.
 *
 * Das Repository liest Zeilen, es entscheidet nichts: keine Sichtbarkeit (das
 * haben Produktseite und VariantRepository schon getan), keine Vererbung (das
 * ist ProductDetailAssembler), keine Sicherheitstexte (Task 8).
 */
class ProductDetailRepositoryTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
    }

    // ── Standardsprache ──────────────────────────────────────────────────

    public function testInTheDefaultLanguageNoJoinIsBuilt(): void
    {
        // 🔴 Ohne Übersetzungstabelle. Entstünde ein Join, bräche die Abfrage mit
        // „no such table" ab — ein einsprachiger Shop bekommt die Abfrage ohne
        // Übersetzung, für Produkt, Typ und Eigenschaften.
        $this->connection->executeStatement('DROP TABLE tl_consho_translation');
        $this->insertProductType(['id' => 3, 'title' => 'Bitter']);
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter', 'productType' => 3]);
        $this->property(group: 5, groupTitle: 'Sorte', option: 51, optionTitle: 'Kräuterbitter', product: 1);

        foreach ([['en', 'en'], ['', '']] as [$language, $fallback]) {
            $row = $this->repository()->row(1, $language, $fallback);

            self::assertNotNull($row);
            self::assertSame('Schwedenbitter', $row->title);
            self::assertSame('Bitter', $row->productTypeTitle);
            self::assertSame('Kräuterbitter', $this->repository()->properties(1, $language, $fallback)[0]->values[0]);
        }
    }

    // ── Texte in der Sprache der Seite ───────────────────────────────────

    public function testTitleTeaserAndDescriptionAreTranslated(): void
    {
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter', 'teaser' => 'Ein Bitter.', 'description' => '<p>Kräuter.</p>']);
        $this->insertTranslation([
            'pid' => 1,
            'ptable' => 'tl_consho_product',
            'language' => 'fr',
            'title' => 'Amer suédois',
            'alias' => 'amer-suedois',
            'teaser' => 'Un amer.',
            'description' => '<p>Herbes.</p>',
        ]);

        $row = $this->repository()->row(1, 'fr', 'en');

        self::assertNotNull($row);
        self::assertSame('Amer suédois', $row->title);
        self::assertSame('Un amer.', $row->teaser);
        self::assertSame('<p>Herbes.</p>', $row->description);
    }

    public function testAnEmptyTranslatedTeaserFallsBackToTheMainRow(): void
    {
        // Eine Übersetzungszeile mit leerem Feld ist keine Übersetzung — ohne
        // NULLIF nähme COALESCE die leere Zeichenkette.
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter', 'teaser' => 'Ein Bitter.', 'description' => '<p>Kräuter.</p>']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Amer suédois', 'teaser' => '']);

        $row = $this->repository()->row(1, 'fr', 'en');

        self::assertNotNull($row);
        self::assertSame('Ein Bitter.', $row->teaser);
        // description ist in der Übersetzung NULL — ebenfalls Rückfall.
        self::assertSame('<p>Kräuter.</p>', $row->description);
    }

    public function testWithoutATranslationRowTheMainRowStays(): void
    {
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter']);

        $row = $this->repository()->row(1, 'fr', 'en');

        self::assertNotNull($row);
        self::assertSame('Schwedenbitter', $row->title);
        // teaser, metaDescription und description sind in der DB NULL — die
        // Zeile trägt nie null.
        self::assertSame('', $row->teaser);
        self::assertSame('', $row->metaDescription);
        self::assertSame('', $row->description);
    }

    // ── Meta-Beschreibung: ein Text, fällt zurück wie der Kurztext ───────

    public function testTheMetaDescriptionIsReadInTheLanguageOfThePage(): void
    {
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter', 'metaDescription' => 'Schwedenbitter nach Originalrezept']);
        $this->insertTranslation([
            'pid' => 1,
            'ptable' => 'tl_consho_product',
            'language' => 'fr',
            'title' => 'Amer suédois',
            'metaDescription' => 'Amer suédois selon la recette originale',
        ]);

        self::assertSame('Amer suédois selon la recette originale', $this->repository()->row(1, 'fr', 'en')?->metaDescription);
        self::assertSame('Schwedenbitter nach Originalrezept', $this->repository()->row(1, 'en', 'en')?->metaDescription);
    }

    public function testAnUntranslatedMetaDescriptionFallsBackToTheDefaultLanguage(): void
    {
        // Wie beim Kurztext: Die Übersetzungszeile gibt es, das Feld ist leer.
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter', 'teaser' => 'Ein Bitter.', 'metaDescription' => 'Schwedenbitter nach Originalrezept']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Amer suédois', 'teaser' => 'Un amer.', 'metaDescription' => '']);

        $row = $this->repository()->row(1, 'fr', 'en');

        self::assertNotNull($row);
        self::assertSame('Un amer.', $row->teaser);
        self::assertSame('Schwedenbitter nach Originalrezept', $row->metaDescription);
    }

    public function testSafetyTextsAreNotReadHere(): void
    {
        // GPSR hat eine andere Regel als die Texte — Rückfall verboten
        // (Mehrsprachigkeit 4) — und einen eigenen Ort: ProductSafetyResolver,
        // Task 8. Stünde das Feld hier, läse es irgendwann jemand mit coalesce().
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter', 'safetyWarnings' => '<p>Enthält Alkohol.</p>']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Amer suédois']);

        self::assertNotNull($this->repository()->row(1, 'fr', 'en'));
        self::assertFalse(property_exists(ProductDetailRow::class, 'safetyWarnings'));
        self::assertFalse(property_exists(ProductDetailRow::class, 'safetyInformation'));
        self::assertFalse(property_exists(ProductDetail::class, 'safetyWarnings'));
        self::assertFalse(property_exists(ProductDetail::class, 'safetyInformation'));
    }

    // ── Die übrigen Felder ───────────────────────────────────────────────

    public function testTheRowCarriesEveryFieldThePageNeeds(): void
    {
        $uuids = ['9e474bae-ce18-11ec-a8a8-0242ac130003', '1a2b3c4d-ce18-11ec-a8a8-0242ac130003'];

        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter']);
        $this->insertProduct([
            'id' => 2,
            'pid' => 1,
            'title' => 'Schwedenbitter 250 ml',
            'sku' => 'SB-250',
            'gtin' => '4006381333931',
            'mpn' => 'SB250',
            'images' => serialize(array_map(static fn (string $uuid): string => hex2bin(str_replace('-', '', $uuid)), $uuids)),
            'unit' => 'ml',
            'purchaseUnit' => '250.0000',
            'referenceUnit' => '1000.0000',
            'minPurchase' => '2.0000',
            'purchaseSteps' => '0.5000',
            'minimumAge' => 16,
            'manufacturer' => 7,
            'productType' => 3,
        ]);

        $row = $this->repository()->row(2);

        self::assertNotNull($row);
        self::assertSame(2, $row->id);
        self::assertSame(1, $row->pid);
        self::assertSame('SB-250', $row->sku);
        self::assertSame('4006381333931', $row->gtin);
        self::assertSame('SB250', $row->mpn);
        // Reihenfolge wie gespeichert — das erste Bild ist eine Aussage über den Artikel.
        self::assertSame($uuids, $row->images);
        self::assertSame('ml', $row->unit);
        self::assertTrue(BigDecimal::of($row->purchaseUnit)->isEqualTo('250'));
        self::assertTrue(BigDecimal::of($row->referenceUnit)->isEqualTo('1000'));
        self::assertTrue(BigDecimal::of($row->minPurchase)->isEqualTo('2'));
        self::assertTrue(BigDecimal::of($row->purchaseSteps)->isEqualTo('0.5'));
        self::assertSame(16, $row->minimumAge);
        self::assertSame(7, $row->manufacturer);
        self::assertSame(3, $row->productType);
    }

    public function testUnsetColumnsCarryTheirDatabaseDefaults(): void
    {
        // Nur der Titel geschrieben: alles andere ist das, was die DB selbst
        // hineinlegt — gerade minPurchase/purchaseSteps mit 1, nicht 0.
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter']);

        $row = $this->repository()->row(1);

        self::assertNotNull($row);
        self::assertSame(0, $row->pid);
        self::assertSame('', $row->sku);
        self::assertSame('', $row->unit);
        self::assertTrue(BigDecimal::of($row->minPurchase)->isEqualTo('1'));
        self::assertTrue(BigDecimal::of($row->purchaseSteps)->isEqualTo('1'));
        self::assertSame(0, $row->minimumAge);
        self::assertSame('', $row->productTypeTitle);
        self::assertSame('', $row->detailPartial);
    }

    public function testImagesNullIsAnEmptyList(): void
    {
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter']);

        $row = $this->repository()->row(1);

        self::assertNotNull($row);
        self::assertSame([], $row->images);
    }

    public function testAnUnknownIdIsNull(): void
    {
        self::assertNull($this->repository()->row(99));
        self::assertNull($this->repository()->row(99, 'fr', 'en'));
    }

    // ── Produkttyp ───────────────────────────────────────────────────────

    public function testTheProductTypeTitleIsTranslatedAndItsPartialComesAlong(): void
    {
        $this->insertProductType(['id' => 3, 'title' => 'Farbe', 'detailPartial' => 'consho_product_type/farbe']);
        $this->insertProduct(['id' => 1, 'title' => 'Leinöl', 'productType' => 3]);
        $this->insertTranslation(['pid' => 3, 'ptable' => 'tl_consho_product_type', 'language' => 'fr', 'title' => 'Couleur']);

        $row = $this->repository()->row(1, 'fr', 'en');

        self::assertNotNull($row);
        self::assertSame('Couleur', $row->productTypeTitle);
        // Die Kennung ist sprachneutral — sie läuft nie durch coalesce().
        self::assertSame('consho_product_type/farbe', $row->detailPartial);
    }

    public function testATranslationOfTheProductWithTheTypesIdDoesNotLabelTheType(): void
    {
        // Produkt 3 und Typ 3 teilen die ID. Ohne ptable im Join trüge der Typ
        // den übersetzten Titel des Produkts.
        $this->insertProductType(['id' => 3, 'title' => 'Farbe']);
        $this->insertProduct(['id' => 3, 'title' => 'Leinöl', 'productType' => 3]);
        $this->insertTranslation(['pid' => 3, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Huile de lin']);

        $row = $this->repository()->row(3, 'fr', 'en');

        self::assertNotNull($row);
        self::assertSame('Huile de lin', $row->title);
        self::assertSame('Farbe', $row->productTypeTitle);
    }

    // ── Eigenschaften ────────────────────────────────────────────────────

    public function testPropertiesOfAGroupThatIsNotFilterableAreShown(): void
    {
        // Anders als der Filter: Die Detailseite zeigt, was der Artikel IST,
        // auch aus Gruppen, nach denen niemand filtern soll.
        $this->property(group: 5, groupTitle: 'Sorte', option: 51, optionTitle: 'Kräuterbitter', product: 1, filterable: false);
        $this->insertTranslation(['pid' => 5, 'ptable' => 'tl_consho_property_group', 'language' => 'fr', 'title' => 'Variété']);
        $this->insertTranslation(['pid' => 51, 'ptable' => 'tl_consho_property_option', 'language' => 'fr', 'title' => 'Amer aux herbes']);

        $properties = $this->repository()->properties(1, 'fr', 'en');

        self::assertCount(1, $properties);
        self::assertSame(5, $properties[0]->groupId);
        self::assertSame('Variété', $properties[0]->label);
        self::assertSame(['Amer aux herbes'], $properties[0]->values);
    }

    public function testAPropertyWithoutTranslationKeepsItsLabel(): void
    {
        $this->property(group: 5, groupTitle: 'Sorte', option: 51, optionTitle: 'Kräuterbitter', product: 1);
        $this->insertTranslation(['pid' => 51, 'ptable' => 'tl_consho_property_option', 'language' => 'fr', 'title' => '']);

        $properties = $this->repository()->properties(1, 'fr', 'en');

        self::assertSame('Sorte', $properties[0]->label);
        self::assertSame(['Kräuterbitter'], $properties[0]->values);
    }

    public function testTwoOptionsOfOneGroupAreOneViewBySortingThenId(): void
    {
        $this->insertPropertyGroup(['id' => 5, 'title' => 'Farbe']);
        $this->insertPropertyOption(['id' => 53, 'pid' => 5, 'title' => 'Grün', 'sorting' => 64]);
        $this->insertPropertyOption(['id' => 52, 'pid' => 5, 'title' => 'Blau', 'sorting' => 128]);
        $this->insertPropertyOption(['id' => 51, 'pid' => 5, 'title' => 'Rot', 'sorting' => 64]);
        $this->insertPropertyOption(['id' => 54, 'pid' => 5, 'title' => 'Gelb', 'sorting' => 64]);
        $this->insertProductProperty(['pid' => 1, 'optionId' => 52]);
        $this->insertProductProperty(['pid' => 1, 'optionId' => 53]);
        $this->insertProductProperty(['pid' => 1, 'optionId' => 51]);

        $properties = $this->repository()->properties(1);

        // Gelb ist nicht zugeordnet; sorting kollidiert bei Rot/Grün — dann id.
        self::assertCount(1, $properties);
        self::assertSame(['Rot', 'Grün', 'Blau'], $properties[0]->values);
    }

    public function testGroupsComeByTheirLabelInThePagesLanguage(): void
    {
        // Gruppen haben kein sorting — wie im Filter entscheidet die Beschriftung,
        // die der Besucher liest, dann id.
        $this->property(group: 5, groupTitle: 'Volumen', option: 51, optionTitle: '250 ml', product: 1);
        $this->property(group: 6, groupTitle: 'Farbe', option: 61, optionTitle: 'Rot', product: 1);
        $this->property(group: 7, groupTitle: 'Größe', option: 71, optionTitle: 'M', product: 1);
        $this->insertTranslation(['pid' => 5, 'ptable' => 'tl_consho_property_group', 'language' => 'fr', 'title' => 'Aa-Volume']);

        self::assertSame([6, 7, 5], $this->groupIds($this->repository()->properties(1)));
        self::assertSame([5, 6, 7], $this->groupIds($this->repository()->properties(1, 'fr', 'en')));
    }

    public function testOnlyTheProductsOwnPropertiesAreRead(): void
    {
        // tl_consho_product_property, nicht tl_consho_product_option: die Achse
        // einer Variante ist keine Eigenschaft des Produkts.
        $this->property(group: 5, groupTitle: 'Sorte', option: 51, optionTitle: 'Kräuterbitter', product: 1);
        $this->insertPropertyOption(['id' => 52, 'pid' => 5, 'title' => 'Magenbitter']);
        $this->insertProductProperty(['pid' => 2, 'optionId' => 52]);
        $this->insertProductOption(['pid' => 1, 'optionId' => 52]);

        $properties = $this->repository()->properties(1);

        self::assertCount(1, $properties);
        self::assertSame(['Kräuterbitter'], $properties[0]->values);
    }

    public function testNoPropertiesIsAnEmptyList(): void
    {
        self::assertSame([], $this->repository()->properties(1));
        self::assertSame([], $this->repository()->properties(1, 'fr', 'en'));
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private function repository(): ProductDetailRepository
    {
        return new ProductDetailRepository($this->connection);
    }

    private function property(int $group, string $groupTitle, int $option, string $optionTitle, int $product, bool $filterable = true): void
    {
        $this->insertPropertyGroup(['id' => $group, 'title' => $groupTitle, 'filterable' => $filterable ? 1 : 0]);
        $this->insertPropertyOption(['id' => $option, 'pid' => $group, 'title' => $optionTitle]);
        $this->insertProductProperty(['pid' => $product, 'optionId' => $option]);
    }

    /**
     * @param list<PropertyView> $properties
     *
     * @return list<int>
     */
    private function groupIds(array $properties): array
    {
        return array_map(static fn (PropertyView $p): int => $p->groupId, $properties);
    }
}
