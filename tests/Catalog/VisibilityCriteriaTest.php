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
 * engine questions and belong in the acceptance step on the test server.
 */
class VisibilityCriteriaTest extends TestCase
{
    // Zehnstellig wie ein echter Zeitstempel: start und stop sind in der
    // echten DB varchar(10), der Vergleich läuft dort als Zeichenkette. Eine
    // kleine Zahl wie 1_000_000 würde bei "-1" auf sechs Stellen fallen und
    // SQLites lexikographischen Vergleich (TEXT-Affinität der Spalte) falsch
    // ausfallen lassen — siehe VisibilityCriteria::published().
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
        // Die Mehrheit der Produkte. Die Regel darf nicht pauschal greifen —
        // sonst wäre in jeder zweiten Sprache der halbe Katalog weg.
        $this->product(1);
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame([1], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testAProductWhoseSafetyFieldsWereNeverTouchedIsUnaffected(): void
    {
        // Die Spalten sind `longtext NULL` ohne Vorgabewert — ein nie
        // gespeichertes Feld steht als NULL in der Datenbank, nicht als ''.
        // Auf dem Testserver am 2026-09-13 bei ALLEN Produkten so vorgefunden.
        // `NULL = ''` ist in SQL nie wahr: Ohne Absicherung wäre jedes solche
        // Produkt in jeder Übersetzungssprache unsichtbar.
        $this->product(1, safetyWarnings: null, safetyInformation: null);
        $this->inShop(1, 1);
        $this->translate(1, 'en', alias: 'herbal-bitters', safetyWarnings: null, safetyInformation: null);

        self::assertSame([1], $this->visibleMainProducts(language: 'en', fallback: 'de'));
    }

    public function testAnUntouchedTranslatedSafetyFieldDoesNotCountAsTranslated(): void
    {
        // Die Gegenrichtung: NULL in der Übersetzung ist leer, nicht „vorhanden".
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

    // ── Varianten mit eigenen Sicherheitstexten (Michael, 2026-09-13) ────

    public function testAVariantWithoutOwnSafetyTextsNeedsNoTranslationRow(): void
    {
        // Der Normalfall: Die Variante erbt Texte und Warnhinweise vom
        // Hauptprodukt. Hinge ihre Sichtbarkeit an einer eigenen
        // Übersetzungszeile, verschwände jedes Variantenprodukt in jeder
        // Fremdsprache.
        $this->product(1);
        $this->inShop(1, 1);
        $this->product(2, pid: 1, safetyWarnings: null, safetyInformation: null);
        $this->product(3, pid: 1);

        self::assertSame([2, 3], $this->visibleVariants(language: 'fr', fallback: 'de'));
    }

    public function testAVariantWithOwnWarningsIsInvisibleWithoutTheirTranslation(): void
    {
        // Frage 3: Auf der fr-Seite gäbe es sonst nur „deutsch zeigen" (Verstoß),
        // „die des Hauptprodukts zeigen" (falsch) oder „nichts zeigen" (Verstoß).
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
        // Ohne Alias: Varianten haben keine eigene Adresse, ihre
        // Übersetzungszeile trägt nur die Sicherheitstexte.
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
        // Die Zeile des Hauptprodukts trägt einen französischen Warnhinweis —
        // aber den des Hauptprodukts, nicht den eigenen der Variante.
        $this->product(1, safetyWarnings: 'Enthält Alkohol');
        $this->inShop(1, 1);
        $this->translate(1, 'fr', alias: 'bitter-suedois', safetyWarnings: 'Contient de l\'alcool');
        $this->product(2, pid: 1, safetyWarnings: 'Enthält 40 % Alkohol');

        self::assertSame([], $this->visibleVariants(language: 'fr', fallback: 'de'));
    }

    public function testAMainProductWhoseOnlyVariantIsHiddenByLanguageIsHiddenToo(): void
    {
        // Regel 3 in derselben Sprache: Ist in fr keine Variante sichtbar, gibt
        // es dort nichts zu kaufen. Sonst stünde die Produktseite ohne jede
        // wählbare Variante da, während die Liste das Produkt schon verwirft.
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
            // 0 bleibt der Testparameter für "nicht gesetzt", die Spalte
            // speichert dafür aber '' — genau wie die echte DB.
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
