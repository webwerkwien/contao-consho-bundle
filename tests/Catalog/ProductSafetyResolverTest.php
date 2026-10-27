<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyResolver;

class ProductSafetyResolverTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE']);
    }

    public function testADigitalProductNeedsNothing(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(physical: false)],
            manufacturers: [],
        );

        self::assertFalse($info->required);
        self::assertSame('', $info->manufacturerName);
    }

    public function testAPhysicalProductCarriesItsManufacturer(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 5)],
            manufacturers: [5 => $this->manufacturer()],
        );

        self::assertTrue($info->required);
        self::assertSame('Kräuterhaus Steiermark', $info->manufacturerName);
        self::assertSame('info@example.at', $info->manufacturerEmail);
    }

    public function testTheAddressIsFormattedForReadingNotForParsing(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 5)],
            manufacturers: [5 => $this->manufacturer()],
        );

        self::assertSame("Hauptstraße 1\n8010 Graz\nÖsterreich", $info->manufacturerAddress);
    }

    public function testAVariantInheritsFromItsMainProduct(): void
    {
        // Empty means inherit, like the shop assignment in A-3. Copying the
        // manufacturer onto every variant would go stale one variant at a time.
        $info = $this->resolve(
            products: [
                2 => $this->product(manufacturer: 0, pid: 1, warnings: null),
                1 => $this->product(manufacturer: 5, warnings: 'Enthält Alkohol'),
            ],
            manufacturers: [5 => $this->manufacturer()],
            productId: 2,
        );

        self::assertSame('Kräuterhaus Steiermark', $info->manufacturerName);
        self::assertSame('Enthält Alkohol', $info->warnings);
    }

    public function testAVariantMayOverrideItsMainProduct(): void
    {
        $info = $this->resolve(
            products: [
                2 => $this->product(manufacturer: 6, pid: 1, warnings: 'Nicht für Kinder'),
                1 => $this->product(manufacturer: 5, warnings: 'Enthält Alkohol'),
            ],
            manufacturers: [5 => $this->manufacturer(), 6 => $this->manufacturer(title: 'Zweiter')],
            productId: 2,
        );

        self::assertSame('Zweiter', $info->manufacturerName);
        self::assertSame('Nicht für Kinder', $info->warnings);
    }

    public function testAManufacturerInsideTheUnionHasNoResponsiblePersonBlock(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 5)],
            manufacturers: [5 => $this->manufacturer(country: 'AT', euResponsible: 9)],
        );

        // Even with one set: inside the Union there is nothing to show, and a
        // heading over an irrelevant address is worse than no heading.
        self::assertSame('', $info->euResponsibleName);
        self::assertSame('', $info->euResponsibleAddress);
    }

    public function testAThirdCountryManufacturerCarriesItsResponsiblePerson(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 5)],
            manufacturers: [
                5 => $this->manufacturer(title: 'Swiss Herbs AG', country: 'CH', euResponsible: 9),
                9 => $this->manufacturer(title: 'EU Rep GmbH', email: 'rep@example.de'),
            ],
        );

        self::assertSame('EU Rep GmbH', $info->euResponsibleName);
        self::assertSame('rep@example.de', $info->euResponsibleEmail);
        self::assertStringContainsString('Österreich', $info->euResponsibleAddress);
    }

    public function testAMissingResponsiblePersonLeavesEmptyFieldsNotHalfABlock(): void
    {
        // 🔴 Block B must never render a heading without content. The gap is
        // reported on the start page; the product page stays silent about it.
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 5)],
            manufacturers: [5 => $this->manufacturer(country: 'CH', euResponsible: 0)],
        );

        self::assertSame('', $info->euResponsibleName);
        self::assertSame('', $info->euResponsibleAddress);
        self::assertSame('', $info->euResponsibleEmail);
    }

    public function testAnUnknownProductYieldsAnEmptyResult(): void
    {
        $info = $this->resolve(products: [], manufacturers: []);

        self::assertFalse($info->required);
        self::assertSame('', $info->manufacturerName);
    }

    public function testAProductWithoutAManufacturerYieldsEmptyFields(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 0)],
            manufacturers: [],
        );

        self::assertTrue($info->required);
        self::assertSame('', $info->manufacturerName);
        self::assertSame('', $info->manufacturerEmail);
    }

    // ── Language of the page (B-5) ───────────────────────────────────────

    public function testInTheFallbackLanguageTheMainRowsAreRead(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 5, warnings: 'Contains alcohol')],
            manufacturers: [5 => $this->manufacturer(title: 'Herb House')],
            language: 'en',
            fallback: 'en',
            translatedProducts: [1 => $this->translatedProduct(manufacturer: 5, warnings: 'Contient de l\'alcool')],
            translatedManufacturers: [5 => $this->manufacturer(title: 'Maison des herbes')],
        );

        self::assertSame('Contains alcohol', $info->warnings);
        self::assertSame('Herb House', $info->manufacturerName);
    }

    public function testInAnotherLanguageTheTranslatedRowsAreRead(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 5, warnings: 'Enthält Alkohol')],
            manufacturers: [5 => $this->manufacturer()],
            language: 'fr',
            fallback: 'de',
            translatedProducts: [1 => $this->translatedProduct(manufacturer: 5, warnings: 'Contient de l\'alcool', ownWarnings: true)],
            translatedManufacturers: [5 => $this->manufacturer(title: 'Maison des herbes')],
        );

        self::assertSame('Contient de l\'alcool', $info->warnings);
        self::assertSame('Maison des herbes', $info->manufacturerName);
    }

    public function testTheResponsiblePersonIsAskedInTheSameLanguage(): void
    {
        $info = $this->resolve(
            products: [1 => $this->product(manufacturer: 5)],
            manufacturers: [
                5 => $this->manufacturer(title: 'Swiss Herbs AG', country: 'CH', euResponsible: 9),
                9 => $this->manufacturer(title: 'EU Rep GmbH'),
            ],
            language: 'fr',
            fallback: 'de',
            translatedProducts: [1 => $this->translatedProduct(manufacturer: 5)],
            translatedManufacturers: [
                5 => $this->manufacturer(title: 'Swiss Herbs SA', country: 'CH', euResponsible: 9),
                9 => $this->manufacturer(title: 'Représentant UE'),
            ],
        );

        self::assertSame('Swiss Herbs SA', $info->manufacturerName);
        self::assertSame('Représentant UE', $info->euResponsibleName);
    }

    public function testAVariantWithoutOwnTextsShowsItsMainProductsTextInThatLanguage(): void
    {
        $info = $this->resolve(
            products: [],
            manufacturers: [],
            productId: 2,
            language: 'fr',
            fallback: 'de',
            translatedProducts: [
                2 => $this->translatedProduct(pid: 1),
                1 => $this->translatedProduct(warnings: 'Contient de l\'alcool', ownWarnings: true),
            ],
        );

        self::assertSame('Contient de l\'alcool', $info->warnings);
    }

    public function testAVariantWithAnOwnTranslatedWarningShowsItsOwn(): void
    {
        $info = $this->resolve(
            products: [],
            manufacturers: [],
            productId: 2,
            language: 'fr',
            fallback: 'de',
            translatedProducts: [
                2 => $this->translatedProduct(pid: 1, warnings: 'Pas pour les enfants', ownWarnings: true),
                1 => $this->translatedProduct(warnings: 'Contient de l\'alcool', ownWarnings: true),
            ],
        );

        self::assertSame('Pas pour les enfants', $info->warnings);
    }

    public function testAVariantWithOwnWarningsButNoTranslationShowsNothingRatherThanTheMainProducts(): void
    {
        // 🔴 The main product's French warning would be wrong for this
        // variant. Empty — and VisibilityCriteria::variant() hides the
        // variant in this language anyway.
        $info = $this->resolve(
            products: [],
            manufacturers: [],
            productId: 2,
            language: 'fr',
            fallback: 'de',
            translatedProducts: [
                2 => $this->translatedProduct(pid: 1, warnings: null, ownWarnings: true),
                1 => $this->translatedProduct(warnings: 'Contient de l\'alcool', ownWarnings: true),
            ],
        );

        self::assertSame('', $info->warnings);
    }

    public function testWhetherAVariantHasOwnTextsIsDecidedInTheDefaultLanguage(): void
    {
        // The same question as visibility: it treats a variant without its
        // own German warning as "inherits" — so the page shows the main
        // product's, even if an orphaned French text sits on the
        // variant.
        $info = $this->resolve(
            products: [],
            manufacturers: [],
            productId: 2,
            language: 'fr',
            fallback: 'de',
            translatedProducts: [
                2 => $this->translatedProduct(pid: 1, warnings: 'Pas pour les enfants', ownWarnings: false),
                1 => $this->translatedProduct(warnings: 'Contient de l\'alcool', ownWarnings: true),
            ],
        );

        self::assertSame('Contient de l\'alcool', $info->warnings);
    }

    public function testSafetyInformationIsInheritedPerFieldInThatLanguage(): void
    {
        $info = $this->resolve(
            products: [],
            manufacturers: [],
            productId: 2,
            language: 'fr',
            fallback: 'de',
            translatedProducts: [
                2 => $this->translatedProduct(pid: 1, warnings: 'Pas pour les enfants', ownWarnings: true),
                1 => $this->translatedProduct(warnings: 'Contient de l\'alcool', information: 'Conserver au frais', ownWarnings: true, ownInformation: true),
            ],
        );

        self::assertSame('Pas pour les enfants', $info->warnings);
        self::assertSame('Conserver au frais', $info->safetyInformation);
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<int, array<string, mixed>> $manufacturers
     * @param array<int, array<string, mixed>> $translatedProducts      rows as the repository returns them in a non-default language
     * @param array<int, array<string, mixed>> $translatedManufacturers
     */
    private function resolve(
        array $products,
        array $manufacturers,
        int $productId = 1,
        string $language = '',
        string $fallback = '',
        array $translatedProducts = [],
        array $translatedManufacturers = [],
    ): object {
        // The mock answers according to the language passed — if the resolver
        // does not pass it on, it gets the rows of the default language.
        $translated = static fn (string $language, string $fallback): bool => '' !== $language && $language !== $fallback;

        $repository = $this->createMock(ProductSafetyRepositoryInterface::class);
        $repository->method('product')->willReturnCallback(
            static fn (int $id, string $language = '', string $fallback = ''): ?array => $translated($language, $fallback)
                ? ($translatedProducts[$id] ?? null)
                : ($products[$id] ?? null),
        );
        $repository->method('manufacturer')->willReturnCallback(
            static fn (int $id, string $language = '', string $fallback = ''): ?array => $translated($language, $fallback)
                ? ($translatedManufacturers[$id] ?? null)
                : ($manufacturers[$id] ?? null),
        );

        $resolver = new ProductSafetyResolver($repository);

        return '' === $language ? $resolver->forProduct($productId) : $resolver->forProduct($productId, $language, $fallback);
    }

    /**
     * A product row in a non-default language: texts only from the
     * translation (null = missing), plus whether the default language
     * carries texts of its own.
     *
     * @return array<string, mixed>
     */
    private function translatedProduct(
        int $manufacturer = 0,
        int $pid = 0,
        string|null $warnings = null,
        string|null $information = null,
        bool $ownWarnings = false,
        bool $ownInformation = false,
    ): array {
        return [
            ...$this->product(manufacturer: $manufacturer, pid: $pid, warnings: $warnings, information: $information),
            'hasOwnSafetyWarnings' => $ownWarnings ? 1 : 0,
            'hasOwnSafetyInformation' => $ownInformation ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(
        bool $physical = true,
        int $manufacturer = 0,
        int $pid = 0,
        string|null $warnings = null,
        string|null $information = null,
    ): array {
        return [
            'pid' => $pid,
            'physical' => $physical,
            'manufacturer' => $manufacturer,
            'safetyWarnings' => $warnings,
            'safetyInformation' => $information,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manufacturer(
        string $title = 'Kräuterhaus Steiermark',
        string $country = 'AT',
        string $email = 'info@example.at',
        int $euResponsible = 0,
    ): array {
        return [
            'title' => $title,
            'street' => 'Hauptstraße 1',
            'postal' => '8010',
            'city' => 'Graz',
            'state' => '',
            'country' => $country,
            'email' => $email,
            'contactUrl' => '',
            'euResponsible' => $euResponsible,
        ];
    }
}
