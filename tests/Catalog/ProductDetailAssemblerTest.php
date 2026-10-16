<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailAssembler;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRow;
use Webwerkwien\ContaoConshoBundle\Catalog\PropertyView;

/**
 * Die Vererbungsregeln Variante → Hauptprodukt, ohne Datenbank.
 *
 * Drei Lagen je Feld: Variante mit eigenem Wert, Variante mit leerem Feld,
 * Hauptprodukt ohne gewählte Variante. Die wichtigste Zeile ist die GTIN — eine
 * geerbte GTIN ist eine falsche Aussage über den Artikel, keine Bequemlichkeit.
 */
class ProductDetailAssemblerTest extends TestCase
{
    // ── Hauptprodukt ohne Variante ───────────────────────────────────────

    public function testWithoutAVariantEverythingIsTheMainProductsOwn(): void
    {
        $properties = [new PropertyView(5, 'Sorte', ['Kräuterbitter'])];
        $detail = ProductDetailAssembler::assemble($this->main(), null, $properties);

        self::assertSame(1, $detail->productId);
        self::assertSame(1, $detail->articleId);
        self::assertSame('Schwedenbitter', $detail->title);
        self::assertSame('Ein Bitter.', $detail->teaser);
        self::assertSame('<p>Kräuter.</p>', $detail->description);
        self::assertSame(['main-image'], $detail->images);
        self::assertSame('SB', $detail->sku);
        self::assertSame('4006381333931', $detail->gtin);
        self::assertSame('SB-MAIN', $detail->mpn);
        self::assertSame(7, $detail->manufacturerId);
        self::assertSame('Bitter', $detail->productTypeTitle);
        self::assertSame('consho_product_type/bitter', $detail->detailPartial);
        self::assertSame(18, $detail->minimumAge);
        self::assertSame('ml', $detail->unit);
        self::assertSame('500.0000', $detail->purchaseUnit);
        self::assertSame('1000.0000', $detail->referenceUnit);
        self::assertSame('1.0000', $detail->minPurchase);
        self::assertSame('1.0000', $detail->purchaseSteps);
        self::assertSame($properties, $detail->properties);
    }

    // ── Variante mit eigenen Werten ──────────────────────────────────────

    public function testAVariantWithValuesOfItsOwnKeepsThem(): void
    {
        $detail = ProductDetailAssembler::assemble($this->main(), $this->variant(), []);

        self::assertSame(1, $detail->productId);
        self::assertSame(2, $detail->articleId);
        self::assertSame(['variant-image-1', 'variant-image-2'], $detail->images);
        self::assertSame('SB-250', $detail->sku);
        self::assertSame('4006381333948', $detail->gtin);
        self::assertSame('SB250', $detail->mpn);
        self::assertSame(8, $detail->manufacturerId);
        self::assertSame('ml', $detail->unit);
        self::assertSame('250.0000', $detail->purchaseUnit);
        self::assertSame('100.0000', $detail->referenceUnit);
        self::assertSame('2.0000', $detail->minPurchase);
        self::assertSame('0.5000', $detail->purchaseSteps);
    }

    public function testTextsAlwaysComeFromTheMainProduct(): void
    {
        // Die Seite ist die des Hauptprodukts. Der Titel an der Variante ist eine
        // Backend-Beschriftung („Schwedenbitter 250 ml"), die Variante zeigt sich
        // über die Achse.
        $detail = ProductDetailAssembler::assemble($this->main(), $this->variant(), []);

        self::assertSame('Schwedenbitter', $detail->title);
        self::assertSame('Ein Bitter.', $detail->teaser);
        self::assertSame('<p>Kräuter.</p>', $detail->description);
    }

    public function testTypeAndPropertiesComeFromTheMainProduct(): void
    {
        $properties = [new PropertyView(5, 'Sorte', ['Kräuterbitter'])];
        $detail = ProductDetailAssembler::assemble($this->main(), $this->variant(), $properties);

        self::assertSame('Bitter', $detail->productTypeTitle);
        self::assertSame('consho_product_type/bitter', $detail->detailPartial);
        self::assertSame($properties, $detail->properties);
    }

    // ── Variante mit leeren Feldern ──────────────────────────────────────

    public function testAnEmptyVariantTakesImagesManufacturerAndUnitsFromTheMainProduct(): void
    {
        $detail = ProductDetailAssembler::assemble($this->main(), $this->emptyVariant(), []);

        self::assertSame(2, $detail->articleId);
        self::assertSame(['main-image'], $detail->images);
        self::assertSame(7, $detail->manufacturerId);
        self::assertSame('ml', $detail->unit);
        self::assertSame('500.0000', $detail->purchaseUnit);
        self::assertSame('1000.0000', $detail->referenceUnit);
    }

    public function testAnEmptyVariantHasNoIdentificationOfItsOwnAndInheritsNone(): void
    {
        // 🔴 A-12: GTIN, SKU und MPN gehören zum ARTIKEL. „250 ml" und „750 ml"
        // haben zwei EANs — die des Hauptprodukts an der Variante wäre falsch.
        $detail = ProductDetailAssembler::assemble($this->main(), $this->emptyVariant(), []);

        self::assertSame('', $detail->gtin);
        self::assertSame('', $detail->sku);
        self::assertSame('', $detail->mpn);
    }

    public function testQuantitiesAreAlwaysTheArticlesOwn(): void
    {
        // Die Spalten haben den Vorgabewert 1 — eine Variante hat immer einen
        // eigenen Wert, „leer" gibt es hier nicht.
        $detail = ProductDetailAssembler::assemble($this->main(minPurchase: '6.0000', purchaseSteps: '6.0000'), $this->emptyVariant(), []);

        self::assertSame('1.0000', $detail->minPurchase);
        self::assertSame('1.0000', $detail->purchaseSteps);
    }

    public function testTheUnitFieldsAreInheritedAsOneSet(): void
    {
        // 250 ml und 750 ml haben verschiedene Inhalte. Eine Variante mit eigener
        // Einheit, aber ohne Füllmenge, bekommt NICHT die Füllmenge des
        // Hauptprodukts dazu — sonst stünde ein Grundpreis aus zwei Artikeln da.
        $variant = $this->row(id: 2, pid: 1, unit: 'l', purchaseUnit: '0.0000', referenceUnit: '0.0000');

        $detail = ProductDetailAssembler::assemble($this->main(), $variant, []);

        self::assertSame('l', $detail->unit);
        self::assertSame('0.0000', $detail->purchaseUnit);
        self::assertSame('0.0000', $detail->referenceUnit);
    }

    public function testQuantityFieldsWithoutAUnitOnTheVariantDoNotBreakTheSet(): void
    {
        // Umgekehrt: Füllmenge an der Variante, aber keine Einheit — dann gilt der
        // Satz des Hauptprodukts ganz, nicht halb.
        $variant = $this->row(id: 2, pid: 1, unit: '', purchaseUnit: '250.0000', referenceUnit: '100.0000');

        $detail = ProductDetailAssembler::assemble($this->main(), $variant, []);

        self::assertSame('ml', $detail->unit);
        self::assertSame('500.0000', $detail->purchaseUnit);
        self::assertSame('1000.0000', $detail->referenceUnit);
    }

    // ── Jugendschutz ─────────────────────────────────────────────────────

    public function testTheMinimumAgeIsTheHigherOfBoth(): void
    {
        // Der Vorgabewert der Spalte ist 0 — „nicht gesetzt" und „jugendfrei" sind
        // nicht zu unterscheiden. Ein Hauptprodukt ab 18 wird durch eine 0 an der
        // Variante nicht jugendfrei.
        self::assertSame(18, ProductDetailAssembler::assemble($this->main(minimumAge: 18), $this->emptyVariant(), [])->minimumAge);
        self::assertSame(18, ProductDetailAssembler::assemble($this->main(minimumAge: 18), $this->row(id: 2, pid: 1, minimumAge: 16), [])->minimumAge);
        self::assertSame(18, ProductDetailAssembler::assemble($this->main(minimumAge: 16), $this->row(id: 2, pid: 1, minimumAge: 18), [])->minimumAge);
        self::assertSame(0, ProductDetailAssembler::assemble($this->main(minimumAge: 0), $this->emptyVariant(), [])->minimumAge);
    }

    // ── Falsche Paare ────────────────────────────────────────────────────

    public function testAVariantOfAnotherProductIsRefused(): void
    {
        // Ein stilles Mischen zweier Artikel — Texte des einen, GTIN des anderen —
        // wäre auf der Seite nicht zu erkennen.
        $this->expectException(\InvalidArgumentException::class);

        ProductDetailAssembler::assemble($this->main(), $this->row(id: 9, pid: 4), []);
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private function main(int $minimumAge = 18, string $minPurchase = '1.0000', string $purchaseSteps = '1.0000'): ProductDetailRow
    {
        return $this->row(
            id: 1,
            pid: 0,
            title: 'Schwedenbitter',
            teaser: 'Ein Bitter.',
            description: '<p>Kräuter.</p>',
            sku: 'SB',
            gtin: '4006381333931',
            mpn: 'SB-MAIN',
            images: ['main-image'],
            unit: 'ml',
            purchaseUnit: '500.0000',
            referenceUnit: '1000.0000',
            minPurchase: $minPurchase,
            purchaseSteps: $purchaseSteps,
            minimumAge: $minimumAge,
            manufacturer: 7,
            productType: 3,
            productTypeTitle: 'Bitter',
            detailPartial: 'consho_product_type/bitter',
        );
    }

    private function variant(): ProductDetailRow
    {
        return $this->row(
            id: 2,
            pid: 1,
            title: 'Schwedenbitter 250 ml',
            teaser: 'Variantenteaser',
            description: '<p>Variante</p>',
            sku: 'SB-250',
            gtin: '4006381333948',
            mpn: 'SB250',
            images: ['variant-image-1', 'variant-image-2'],
            unit: 'ml',
            purchaseUnit: '250.0000',
            referenceUnit: '100.0000',
            minPurchase: '2.0000',
            purchaseSteps: '0.5000',
            minimumAge: 0,
            manufacturer: 8,
            productType: 4,
            productTypeTitle: 'Anderer Typ',
            detailPartial: 'consho_product_type/anders',
        );
    }

    /**
     * Eine Variante, wie das Backend sie anlegt: nur der Titel, sonst die
     * Vorgabewerte der Spalten.
     */
    private function emptyVariant(): ProductDetailRow
    {
        return $this->row(id: 2, pid: 1, title: 'Schwedenbitter 750 ml');
    }

    /**
     * @param list<string> $images
     */
    private function row(
        int $id,
        int $pid,
        string $title = '',
        string $teaser = '',
        string $description = '',
        string $sku = '',
        string $gtin = '',
        string $mpn = '',
        array $images = [],
        string $unit = '',
        string $purchaseUnit = '0.0000',
        string $referenceUnit = '0.0000',
        string $minPurchase = '1.0000',
        string $purchaseSteps = '1.0000',
        int $minimumAge = 0,
        int $manufacturer = 0,
        int $productType = 0,
        string $productTypeTitle = '',
        string $detailPartial = '',
    ): ProductDetailRow {
        return new ProductDetailRow(
            id: $id,
            pid: $pid,
            title: $title,
            teaser: $teaser,
            metaDescription: '',
            description: $description,
            sku: $sku,
            gtin: $gtin,
            mpn: $mpn,
            images: $images,
            unit: $unit,
            purchaseUnit: $purchaseUnit,
            referenceUnit: $referenceUnit,
            minPurchase: $minPurchase,
            purchaseSteps: $purchaseSteps,
            minimumAge: $minimumAge,
            manufacturer: $manufacturer,
            productType: $productType,
            productTypeTitle: $productTypeTitle,
            detailPartial: $detailPartial,
        );
    }
}
