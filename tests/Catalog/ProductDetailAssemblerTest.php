<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailAssembler;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRow;
use Webwerkwien\ContaoConshoBundle\Catalog\PropertyView;

/**
 * The inheritance rules variant → main product, without a database.
 *
 * Three layers per field: variant with its own value, variant with an empty
 * field, main product without a chosen variant. The most important row is the
 * GTIN — an inherited GTIN is a false statement about the item, not a
 * convenience.
 */
class ProductDetailAssemblerTest extends TestCase
{
    // ── Main product without a variant ───────────────────────────────────

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

    // ── Variant with its own values ──────────────────────────────────────

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
        // The page is that of the main product. The title on the variant is a
        // backend label ("Schwedenbitter 250 ml"); the variant shows itself
        // through the axis.
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

    // ── Variant with empty fields ────────────────────────────────────────

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
        // 🔴 A-12: GTIN, SKU and MPN belong to the ITEM. "250 ml" and "750 ml"
        // have two EANs — the main product's on the variant would be wrong.
        $detail = ProductDetailAssembler::assemble($this->main(), $this->emptyVariant(), []);

        self::assertSame('', $detail->gtin);
        self::assertSame('', $detail->sku);
        self::assertSame('', $detail->mpn);
    }

    public function testQuantitiesAreAlwaysTheArticlesOwn(): void
    {
        // The columns default to 1 — a variant always has a value of its
        // own; "empty" does not exist here.
        $detail = ProductDetailAssembler::assemble($this->main(minPurchase: '6.0000', purchaseSteps: '6.0000'), $this->emptyVariant(), []);

        self::assertSame('1.0000', $detail->minPurchase);
        self::assertSame('1.0000', $detail->purchaseSteps);
    }

    public function testTheUnitFieldsAreInheritedAsOneSet(): void
    {
        // 250 ml and 750 ml have different contents. A variant with its own
        // unit but without a quantity does NOT get the main product's quantity
        // added — otherwise a unit price from two items would be shown.
        $variant = $this->row(id: 2, pid: 1, unit: 'l', purchaseUnit: '0.0000', referenceUnit: '0.0000');

        $detail = ProductDetailAssembler::assemble($this->main(), $variant, []);

        self::assertSame('l', $detail->unit);
        self::assertSame('0.0000', $detail->purchaseUnit);
        self::assertSame('0.0000', $detail->referenceUnit);
    }

    public function testQuantityFieldsWithoutAUnitOnTheVariantDoNotBreakTheSet(): void
    {
        // Conversely: quantity on the variant, but no unit — then the main
        // product's set applies wholly, not half.
        $variant = $this->row(id: 2, pid: 1, unit: '', purchaseUnit: '250.0000', referenceUnit: '100.0000');

        $detail = ProductDetailAssembler::assemble($this->main(), $variant, []);

        self::assertSame('ml', $detail->unit);
        self::assertSame('500.0000', $detail->purchaseUnit);
        self::assertSame('1000.0000', $detail->referenceUnit);
    }

    // ── Youth protection ─────────────────────────────────────────────────

    public function testTheMinimumAgeIsTheHigherOfBoth(): void
    {
        // The column defaults to 0 — "not set" and "suitable for minors" cannot be
        // told apart. A main product restricted to 18+ does not become suitable for
        // minors through a 0 on the variant.
        self::assertSame(18, ProductDetailAssembler::assemble($this->main(minimumAge: 18), $this->emptyVariant(), [])->minimumAge);
        self::assertSame(18, ProductDetailAssembler::assemble($this->main(minimumAge: 18), $this->row(id: 2, pid: 1, minimumAge: 16), [])->minimumAge);
        self::assertSame(18, ProductDetailAssembler::assemble($this->main(minimumAge: 16), $this->row(id: 2, pid: 1, minimumAge: 18), [])->minimumAge);
        self::assertSame(0, ProductDetailAssembler::assemble($this->main(minimumAge: 0), $this->emptyVariant(), [])->minimumAge);
    }

    // ── Wrong pairs ──────────────────────────────────────────────────────

    public function testAVariantOfAnotherProductIsRefused(): void
    {
        // A silent mix of two items — texts of one, GTIN of the other —
        // would not be recognisable on the page.
        $this->expectException(\InvalidArgumentException::class);

        ProductDetailAssembler::assemble($this->main(), $this->row(id: 9, pid: 4), []);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

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
     * A variant as the backend creates it: only the title, otherwise the
     * default values of the columns.
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
