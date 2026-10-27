<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalProductSafetyRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;

/**
 * Against SQLite with the schema of the real DB (CatalogSchema): whether a
 * German warning slips through onto a French page is a question for the
 * SQL, not for a mock.
 */
class DbalProductSafetyRepositoryTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_700_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->insertProductType(['id' => 1, 'kind' => 'physical']);
        $GLOBALS['TL_LANGUAGE'] = 'de';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE']);
    }

    // ── Product ──────────────────────────────────────────────────────────

    public function testWithoutALanguageTheMainRowIsReadAsBefore(): void
    {
        $this->insertProduct(['id' => 1, 'productType' => 1, 'manufacturer' => 5, 'safetyWarnings' => 'Enthält Alkohol']);

        $row = $this->repository()->product(1);

        self::assertNotNull($row);
        self::assertSame('Enthält Alkohol', $row['safetyWarnings']);
        self::assertSame(5, (int) $row['manufacturer']);
        self::assertSame(0, (int) $row['pid']);
        self::assertTrue((bool) $row['physical']);
    }

    public function testInTheFallbackLanguageTheMainRowIsRead(): void
    {
        $this->insertProduct(['id' => 1, 'productType' => 1, 'safetyWarnings' => 'Contains alcohol']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer', 'safetyWarnings' => 'Contient de l\'alcool']);

        $row = $this->repository()->product(1, 'en', 'en');

        self::assertSame('Contains alcohol', $this->text($row['safetyWarnings'] ?? null));
    }

    public function testInAnotherLanguageOnlyTheTranslationIsRead(): void
    {
        $this->insertProduct(['id' => 1, 'productType' => 1, 'manufacturer' => 5, 'safetyWarnings' => 'Contains alcohol']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer', 'safetyWarnings' => 'Contient de l\'alcool']);

        $row = $this->repository()->product(1, 'fr', 'en');

        self::assertNotNull($row);
        self::assertSame('Contient de l\'alcool', $this->text($row['safetyWarnings']));
        // The remaining columns are language-neutral and come as before.
        self::assertSame(5, (int) $row['manufacturer']);
        self::assertTrue((bool) $row['physical']);
    }

    public function testATranslationRowWithoutTheWarningNeverYieldsTheGermanOne(): void
    {
        // 🔴 The core of multilingualism 4.1: no fallback. A German
        // warning on the French page is a violation that looks like a
        // well-maintained product.
        $this->insertProduct(['id' => 1, 'productType' => 1, 'safetyWarnings' => 'Enthält Alkohol']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer', 'safetyWarnings' => '']);
        $this->insertProduct(['id' => 2, 'productType' => 1, 'safetyWarnings' => 'Enthält Alkohol']);
        $this->insertTranslation(['pid' => 2, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer-2']);

        self::assertSame('', $this->text($this->repository()->product(1, 'fr', 'de')['safetyWarnings'] ?? null));
        self::assertSame('', $this->text($this->repository()->product(2, 'fr', 'de')['safetyWarnings'] ?? null));
    }

    public function testWithoutAnyTranslationRowBothSafetyTextsAreEmpty(): void
    {
        $this->insertProduct(['id' => 1, 'productType' => 1, 'safetyWarnings' => 'Enthält Alkohol', 'safetyInformation' => 'Kühl lagern']);

        $row = $this->repository()->product(1, 'fr', 'de');

        self::assertNotNull($row);
        self::assertSame('', $this->text($row['safetyWarnings']));
        self::assertSame('', $this->text($row['safetyInformation']));
    }

    public function testSafetyInformationFollowsTheSameRule(): void
    {
        $this->insertProduct(['id' => 1, 'productType' => 1, 'safetyInformation' => 'Kühl lagern']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer', 'safetyInformation' => 'Conserver au frais']);
        $this->insertProduct(['id' => 2, 'productType' => 1, 'safetyInformation' => 'Kühl lagern']);
        $this->insertTranslation(['pid' => 2, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer-2', 'safetyInformation' => '']);

        self::assertSame('Conserver au frais', $this->text($this->repository()->product(1, 'fr', 'de')['safetyInformation'] ?? null));
        self::assertSame('', $this->text($this->repository()->product(2, 'fr', 'de')['safetyInformation'] ?? null));
    }

    public function testTheRowSaysWhetherTheDefaultLanguageCarriesOwnTexts(): void
    {
        // The inheritance variant → main product asks "does the variant have
        // texts of its OWN?" — and in the default language, like
        // VisibilityCriteria::variant(). NULL counts as empty.
        $this->insertProduct(['id' => 1, 'productType' => 1]);
        $this->insertProduct(['id' => 2, 'pid' => 1, 'productType' => 1, 'safetyWarnings' => 'Nicht für Kinder', 'safetyInformation' => '']);

        $variant = $this->repository()->product(2, 'fr', 'de');
        $main = $this->repository()->product(1, 'fr', 'de');

        self::assertTrue((bool) ($variant['hasOwnSafetyWarnings'] ?? null));
        self::assertFalse((bool) ($variant['hasOwnSafetyInformation'] ?? true));
        self::assertFalse((bool) ($main['hasOwnSafetyWarnings'] ?? true));
        self::assertFalse((bool) ($main['hasOwnSafetyInformation'] ?? true));
    }

    public function testAnUnknownProductIsNull(): void
    {
        self::assertNull($this->repository()->product(99));
        self::assertNull($this->repository()->product(99, 'fr', 'de'));
    }

    // ── Manufacturer ─────────────────────────────────────────────────────

    public function testTheManufacturerTitleIsTranslated(): void
    {
        $this->insertManufacturer(['id' => 5, 'title' => 'Kräuterhaus Steiermark', 'city' => 'Graz']);
        $this->insertTranslation(['pid' => 5, 'ptable' => 'tl_consho_manufacturer', 'language' => 'fr', 'title' => 'Maison des herbes', 'alias' => 'maison']);

        $row = $this->repository()->manufacturer(5, 'fr', 'de');

        self::assertSame('Maison des herbes', $row['title'] ?? null);
        self::assertSame('Graz', $row['city'] ?? null);
    }

    public function testTheManufacturerTitleFallsBackToTheDefaultLanguage(): void
    {
        // The name is a fallback class (TranslatableFields), unlike the
        // warnings: a missing name would be an empty manufacturer block.
        $this->insertManufacturer(['id' => 5, 'title' => 'Kräuterhaus Steiermark']);
        $this->insertManufacturer(['id' => 6, 'title' => 'Zweiter']);
        $this->insertTranslation(['pid' => 6, 'ptable' => 'tl_consho_manufacturer', 'language' => 'fr', 'title' => '', 'alias' => 'zweiter']);

        self::assertSame('Kräuterhaus Steiermark', $this->repository()->manufacturer(5, 'fr', 'de')['title'] ?? null);
        self::assertSame('Zweiter', $this->repository()->manufacturer(6, 'fr', 'de')['title'] ?? null);
    }

    public function testWithoutALanguageTheManufacturerIsReadAsBefore(): void
    {
        $this->insertManufacturer(['id' => 5, 'title' => 'Kräuterhaus Steiermark']);
        $this->insertTranslation(['pid' => 5, 'ptable' => 'tl_consho_manufacturer', 'language' => 'fr', 'title' => 'Maison des herbes', 'alias' => 'maison']);

        self::assertSame('Kräuterhaus Steiermark', $this->repository()->manufacturer(5)['title'] ?? null);
        self::assertNull($this->repository()->manufacturer(99, 'fr', 'de'));
    }

    // ── Display and visibility say the same ──────────────────────────────

    public function testAVariantWithoutOwnTextsShowsItsMainProductsTextInThatLanguage(): void
    {
        $this->shopWithVariant(variantWarnings: null);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer', 'safetyWarnings' => 'Contient de l\'alcool']);

        self::assertSame([2], $this->visibleVariants('fr', 'de'));
        self::assertSame('Contient de l\'alcool', $this->resolver()->forProduct(2, 'fr', 'de')->warnings);
    }

    public function testAVariantWithOwnTranslatedWarningsShowsItsOwn(): void
    {
        $this->shopWithVariant(variantWarnings: 'Nicht für Kinder');
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer', 'safetyWarnings' => 'Contient de l\'alcool']);
        $this->insertTranslation(['pid' => 2, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => '750 ml', 'alias' => 'amer-750', 'safetyWarnings' => 'Pas pour les enfants']);

        self::assertSame([2], $this->visibleVariants('fr', 'de'));
        self::assertSame('Pas pour les enfants', $this->resolver()->forProduct(2, 'fr', 'de')->warnings);
    }

    public function testAVariantTheVisibilityHidesShowsNoWarningEither(): void
    {
        /*
         * Own German warning, no French row of its own → the variant is
         * invisible in French.
         * The display must then not substitute the main product's warning —
         * otherwise the page would carry a statement that is not true for this
         * variant, and visibility would have judged differently from the display.
         */
        $this->shopWithVariant(variantWarnings: 'Nicht für Kinder');
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'alias' => 'amer', 'safetyWarnings' => 'Contient de l\'alcool']);

        self::assertSame([], $this->visibleVariants('fr', 'de'));
        self::assertSame('', $this->resolver()->forProduct(2, 'fr', 'de')->warnings);
    }

    public function testInTheDefaultLanguageTheVariantInheritsAsBefore(): void
    {
        $this->shopWithVariant(variantWarnings: null);

        self::assertSame('Enthält Alkohol', $this->resolver()->forProduct(2, 'de', 'de')->warnings);
        self::assertSame('Enthält Alkohol', $this->resolver()->forProduct(2)->warnings);
    }

    public function testTheResolverNamesTheManufacturerInThePagesLanguage(): void
    {
        $this->insertManufacturer(['id' => 5, 'title' => 'Kräuterhaus Steiermark', 'country' => 'AT']);
        $this->insertTranslation(['pid' => 5, 'ptable' => 'tl_consho_manufacturer', 'language' => 'fr', 'title' => 'Maison des herbes', 'alias' => 'maison']);
        $this->insertProduct(['id' => 1, 'productType' => 1, 'manufacturer' => 5]);

        self::assertSame('Maison des herbes', $this->resolver()->forProduct(1, 'fr', 'de')->manufacturerName);
        self::assertSame('Kräuterhaus Steiermark', $this->resolver()->forProduct(1)->manufacturerName);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function shopWithVariant(string|null $variantWarnings): void
    {
        $this->insertProduct(['id' => 1, 'productType' => 1, 'published' => 1, 'safetyWarnings' => 'Enthält Alkohol']);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
        $this->insertProduct(['id' => 2, 'pid' => 1, 'productType' => 1, 'published' => 1, 'safetyWarnings' => $variantWarnings]);
    }

    /**
     * @return list<int>
     */
    private function visibleVariants(string $language, string $fallback): array
    {
        $condition = (new VisibilityCriteria())->variant(1, self::NOW, 'v', $language, $fallback);

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT v.id FROM tl_consho_product v WHERE '.$condition->sql.' ORDER BY v.id',
            $condition->params,
        ));
    }

    private function repository(): DbalProductSafetyRepository
    {
        return new DbalProductSafetyRepository($this->connection);
    }

    private function resolver(): ProductSafetyResolver
    {
        return new ProductSafetyResolver($this->repository());
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
