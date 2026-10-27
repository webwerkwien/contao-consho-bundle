<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Runs against a real database, not a mock.
 *
 * The three rules from block A, section 3.7 are boolean structure over
 * integers — whether "two variants, none active" is excluded cannot be
 * answered by asserting that a query string contains the word EXISTS. SQLite
 * answers the same as MySQL here; index use and ONLY_FULL_GROUP_BY are
 * engine questions and need a real MySQL/MariaDB installation.
 */
class VisibilityCriteriaTest extends TestCase
{
    // Ten digits like a real timestamp: start and stop are varchar(10) in the
    // real DB, the comparison runs there as a string. A
    // small number like 1_000_000 would drop to six digits at "-1" and make
    // SQLite's lexicographic comparison (TEXT affinity of the column) come
    // out wrong — see VisibilityCriteria::published().
    private const NOW = 1_700_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_translation');
    }

    // ── Main products ────────────────────────────────────────────────────

    public function testAPublishedProductWithoutVariantsIsVisible(): void
    {
        $this->product(1);
        $this->inShop(1, 1);

        self::assertSame([1], $this->visibleMainProducts());
    }

    public function testAnUnpublishedProductIsNotVisible(): void
    {
        $this->product(1, published: false);
        $this->inShop(1, 1);

        self::assertSame([], $this->visibleMainProducts());
    }

    public function testAProductThatIsNotInTheShopIsNotVisible(): void
    {
        $this->product(1);
        $this->inShop(1, 2);

        self::assertSame([], $this->visibleMainProducts());
    }

    public function testAProductWhoseStartIsInTheFutureIsNotVisible(): void
    {
        $this->product(1, start: self::NOW + 100);
        $this->inShop(1, 1);

        self::assertSame([], $this->visibleMainProducts());
    }

    public function testAProductWhoseStopHasPassedIsNotVisible(): void
    {
        $this->product(1, stop: self::NOW - 100);
        $this->inShop(1, 1);

        self::assertSame([], $this->visibleMainProducts());
    }

    // ── Main products with variants ──────────────────────────────────────

    public function testAProductWithOneActiveVariantIsVisible(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1);
        $this->product(3, pid: 1, published: false);

        self::assertSame([1], $this->visibleMainProducts());
    }

    public function testAProductWhoseVariantsAreAllInactiveIsNotVisible(): void
    {
        // Nothing about it is purchasable. The main product is a container.
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1, published: false);
        $this->product(3, pid: 1, published: false);

        self::assertSame([], $this->visibleMainProducts());
    }

    public function testAProductWithoutVariantsIsNotCaughtByThatRule(): void
    {
        /*
         * 🔴 The test this class exists for. "Has no active variant" and "has
         * no variants" are one condition apart, and confusing them hides every
         * variant-less product in the shop — which is most of them.
         */
        $this->product(1);
        $this->inShop(1, 1);

        $this->product(2);
        $this->inShop(2, 1);
        $this->product(3, pid: 2, published: false);

        self::assertSame([1], $this->visibleMainProducts());
    }

    public function testAVariantOutsideItsOwnTimeWindowDoesNotCount(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1, stop: self::NOW - 1);

        self::assertSame([], $this->visibleMainProducts());
    }

    public function testAVariantRestrictedToAnotherShopDoesNotCountHere(): void
    {
        // The main product is in both shops, the only variant only in shop 2.
        // In shop 1 there is nothing to buy.
        $this->product(1);
        $this->inShop(1, 1);
        $this->inShop(1, 2);
        $this->product(2, pid: 1);
        $this->inShop(2, 2);

        self::assertSame([], $this->visibleMainProducts(shop: 1));
        self::assertSame([1], $this->visibleMainProducts(shop: 2));
    }

    // ── Variants ─────────────────────────────────────────────────────────

    public function testAVariantWithoutItsOwnShopsInheritsTheMainProduct(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1);

        self::assertSame([2], $this->visibleVariants());
    }

    public function testAVariantIsInvisibleWhenItsMainProductIsUnpublished(): void
    {
        // It has no existence of its own — description, images, tax class and
        // its page all come from the main product.
        $this->product(1, published: false);
        $this->inShop(1, 1);
        $this->product(2, pid: 1);

        self::assertSame([], $this->visibleVariants());
    }

    public function testAVariantCannotReachAShopItsMainProductIsNotIn(): void
    {
        /*
         * The intersection rule. Without it a variant could appear in a shop
         * whose catalogue does not carry its main product — a contradiction
         * that only surfaces in the basket.
         */
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1);
        $this->inShop(2, 2);

        self::assertSame([], $this->visibleVariants(shop: 2));
        self::assertSame([], $this->visibleVariants(shop: 1));
    }

    public function testAVariantRestrictedToOneOfItsMainProductsShops(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->inShop(1, 2);
        $this->product(2, pid: 1);
        $this->inShop(2, 2);

        self::assertSame([], $this->visibleVariants(shop: 1));
        self::assertSame([2], $this->visibleVariants(shop: 2));
    }

    public function testAMainProductIsNeverReturnedAsAVariant(): void
    {
        $this->product(1);
        $this->inShop(1, 1);

        self::assertSame([], $this->visibleVariants());
    }

    // ── Language (A-11) ──────────────────────────────────────────────────

    public function testInTheFallbackLanguageNothingChanges(): void
    {
        $this->product(1);
        $this->inShop(1, 1);

        self::assertSame([1], $this->visibleMainProducts(language: 'de', fallback: 'de'));
    }

    public function testAProductWithoutATranslationIsNotVisibleInThatLanguage(): void
    {
        $this->product(1);
        $this->inShop(1, 1);

        self::assertSame([], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testATranslationWithAnAliasMakesItVisible(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame([1], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testATranslationWithoutAnAliasIsNotEnough(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: null);

        self::assertSame([], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testAnEmptyAliasIsNotEnoughEither(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: '');

        self::assertSame([], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testMissingSafetyWarningsHideTheProductInThatLanguage(): void
    {
        $this->product(1, safetyWarnings: 'Enthält Alkohol');
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame([], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testTranslatedSafetyWarningsMakeItVisibleAgain(): void
    {
        $this->product(1, safetyWarnings: 'Enthält Alkohol');
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters', safetyWarnings: 'Contains alcohol');

        self::assertSame([1], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testAProductWithoutSafetyWarningsIsUnaffected(): void
    {
        // The majority of products. The rule must not apply across the board —
        // otherwise half the catalogue would be gone in every second language.
        $this->product(1);
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame([1], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testAProductWhoseSafetyFieldsWereNeverTouchedIsUnaffected(): void
    {
        // The columns are `longtext NULL` without a default — a field never
        // saved is NULL in the database, not ''.
        // On a real installation ALL products looked like this.
        // `NULL = ''` is never true in SQL: without a safeguard every such
        // product would be invisible in every translation language.
        $this->product(1, safetyWarnings: null, safetyInformation: null);
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters', safetyWarnings: null, safetyInformation: null);

        self::assertSame([1], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testAnUntouchedTranslatedSafetyFieldDoesNotCountAsTranslated(): void
    {
        // The other direction: NULL in the translation is empty, not "present".
        $this->product(1, safetyWarnings: 'Enthält Alkohol');
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters', safetyWarnings: null);

        self::assertSame([], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testSafetyInformationIsCheckedSeparately(): void
    {
        $this->product(1, safetyInformation: 'Kühl lagern');
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame([], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    // ── Variants with their own safety texts ────

    public function testAVariantWithoutOwnSafetyTextsNeedsNoTranslationRow(): void
    {
        // The normal case: the variant inherits texts and warnings from the
        // main product. If its visibility depended on a translation row of its
        // own, every variant product would disappear in every foreign
        // language.
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1, safetyWarnings: null, safetyInformation: null);
        $this->product(3, pid: 1);

        self::assertSame([2, 3], $this->visibleVariants(language: 'fr', fallback: 'de'));
    }

    public function testAVariantWithOwnWarningsIsInvisibleWithoutTheirTranslation(): void
    {
        // Otherwise the fr page would only have "show German" (violation),
        // "show the main product's" (wrong) or "show nothing" (violation).
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1, safetyWarnings: 'Enthält 40 % Alkohol');

        self::assertSame([], $this->visibleVariants(language: 'fr', fallback: 'de'));
        self::assertSame([2], $this->visibleVariants(language: 'de', fallback: 'de'));
        self::assertSame([2], $this->visibleVariants(), 'ohne Sprache wie bisher');
    }

    public function testAVariantTranslationRowWithoutTheWarningIsNotEnough(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1, safetyWarnings: 'Enthält 40 % Alkohol');
        $this->translate(2, 'fr', alias: null, safetyWarnings: null);

        self::assertSame([], $this->visibleVariants(language: 'fr', fallback: 'de'));
    }

    public function testAVariantWithTranslatedOwnWarningsIsVisible(): void
    {
        // Without an alias: variants have no address of their own, their
        // translation row only carries the safety texts.
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1, safetyWarnings: 'Enthält 40 % Alkohol');
        $this->translate(2, 'fr', alias: null, safetyWarnings: 'Contient 40 % d\'alcool');

        self::assertSame([2], $this->visibleVariants(language: 'fr', fallback: 'de'));
    }

    public function testAVariantsOwnSafetyInformationIsCheckedSeparately(): void
    {
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1, safetyInformation: 'Kühl lagern');
        $this->translate(2, 'fr', alias: null, safetyInformation: null);

        self::assertSame([], $this->visibleVariants(language: 'fr', fallback: 'de'));
    }

    public function testTheMainProductsTranslationDoesNotCoverTheVariantsOwnWarnings(): void
    {
        // The main product's row carries a French warning —
        // but the main product's, not the variant's own.
        $this->product(1, safetyWarnings: 'Enthält Alkohol');
        $this->inShop(1, 1);
        $this->translate(1, 'fr', alias: 'bitter-suedois', safetyWarnings: 'Contient de l\'alcool');
        $this->product(2, pid: 1, safetyWarnings: 'Enthält 40 % Alkohol');

        self::assertSame([], $this->visibleVariants(language: 'fr', fallback: 'de'));
    }

    public function testAMainProductWhoseOnlyVariantIsHiddenByLanguageIsHiddenToo(): void
    {
        // Rule 3 in the same language: if no variant is visible in fr, there is
        // nothing to buy there. Otherwise the product page would have no
        // selectable variant at all, while the list already discards the product.
        $this->product(1);
        $this->inShop(1, 1);
        $this->translate(1, 'fr', alias: 'bitter-suedois');
        $this->product(2, pid: 1, safetyWarnings: 'Enthält 40 % Alkohol');

        self::assertSame([], $this->visibleMainProducts(language: 'fr', fallback: 'de'));
        self::assertSame([1], $this->visibleMainProducts(language: 'de', fallback: 'de'));

        $this->product(3, pid: 1);

        self::assertSame([1], $this->visibleMainProducts(language: 'fr', fallback: 'de'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function product(
        int $id,
        int $pid = 0,
        bool $published = true,
        int $start = 0,
        int $stop = 0,
        ?string $safetyWarnings = '',
        ?string $safetyInformation = '',
    ): void {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => $pid,
            'published' => $published ? 1 : 0,
            // 0 stays the test parameter for "not set", but the column
            // stores '' for it — just like the real DB.
            'start' => $start > 0 ? (string) $start : '',
            'stop' => $stop > 0 ? (string) $stop : '',
            'safetyWarnings' => $safetyWarnings,
            'safetyInformation' => $safetyInformation,
        ]);
    }

    private function inShop(int $productId, int $shop): void
    {
        $this->connection->insert('tl_consho_product_shop', ['pid' => $productId, 'shop' => $shop]);
    }

    private function translate(int $pid, string $language, ?string $alias, ?string $safetyWarnings = '', ?string $safetyInformation = ''): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => $pid,
            'ptable' => 'tl_consho_product',
            'language' => $language,
            'alias' => $alias,
            'safetyWarnings' => $safetyWarnings,
            'safetyInformation' => $safetyInformation,
        ]);
    }

    /**
     * @return list<int>
     */
    private function visibleMainProducts(int $shop = 1, string $language = '', string $fallback = 'de'): array
    {
        $condition = (new VisibilityCriteria())->mainProduct($shop, self::NOW, 'p', $language, $fallback);

        $ids = $this->connection->fetchFirstColumn(
            'SELECT p.id FROM tl_consho_product p WHERE '.$condition->sql.' ORDER BY p.id',
            $condition->params,
        );

        return array_map(intval(...), $ids);
    }

    /**
     * @return list<int>
     */
    private function visibleVariants(int $shop = 1, string $language = '', string $fallback = 'de'): array
    {
        $condition = (new VisibilityCriteria())->variant($shop, self::NOW, 'v', $language, $fallback);

        $ids = $this->connection->fetchFirstColumn(
            'SELECT v.id FROM tl_consho_product v WHERE '.$condition->sql.' ORDER BY v.id',
            $condition->params,
        );

        return array_map(intval(...), $ids);
    }
}
