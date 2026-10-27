<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRow;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;

/**
 * Against SQLite with the shared test schema — the class is a
 * query, and VisibilityCriteria decides visibility, not a
 * mock.
 *
 * Base data: product 1 (Schwedenbitter) with the
 * variants 3 (250 ml) and 6 (750 ml), shop 1.
 */
class VariantRepositoryTest extends TestCase
{
    use CatalogSchema;

    // Ten digits like a real timestamp — start/stop are varchar(10),
    // see VisibilityCriteria::published().
    private const NOW = 1_700_000_000;

    private const VOLUME = 1;
    private const COLOUR = 2;
    private const ML_250 = 11;
    private const ML_750 = 12;
    private const ML_1000 = 13;
    private const RED = 21;

    private VariantRepository $repository;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($connection);
        $this->repository = new VariantRepository($connection, new VisibilityCriteria());

        $this->insertShop(['id' => 1, 'title' => 'Shop']);

        // A group that is never filterable as an axis — axes are not bound to
        // `filterable`.
        $this->insertPropertyGroup(['id' => self::VOLUME, 'title' => 'Füllmenge', 'filterable' => 0]);
        $this->insertPropertyOption(['id' => self::ML_250, 'pid' => self::VOLUME, 'title' => '250 ml', 'sorting' => 64]);
        $this->insertPropertyOption(['id' => self::ML_750, 'pid' => self::VOLUME, 'title' => '750 ml', 'sorting' => 128]);
        $this->insertPropertyOption(['id' => self::ML_1000, 'pid' => self::VOLUME, 'title' => '1 l', 'sorting' => 256]);

        $this->insertPropertyGroup(['id' => self::COLOUR, 'title' => 'Farbe']);
        $this->insertPropertyOption(['id' => self::RED, 'pid' => self::COLOUR, 'title' => 'Rot']);

        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter', 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
    }

    // ── Visible variants ─────────────────────────────────────────────────

    public function testVisibleVariantsComeWithTheirOptionPerGroup(): void
    {
        $this->variant(3, [self::ML_250]);
        $this->variant(6, [self::ML_750]);

        $rows = $this->repository->visibleVariants(1, 1, self::NOW);

        self::assertSame([3, 6], $this->ids($rows));
        self::assertSame([self::VOLUME => self::ML_250], $rows[0]->options);
        self::assertSame([self::VOLUME => self::ML_750], $rows[1]->options);
    }

    public function testAVariantWithOptionsInTwoGroupsCarriesBoth(): void
    {
        $this->variant(3, [self::ML_250, self::RED]);

        // The order of the groups within a row carries no
        // meaning — axes() orders the axes.
        $options = $this->repository->visibleVariants(1, 1, self::NOW)[0]->options;
        ksort($options);

        self::assertSame([self::VOLUME => self::ML_250, self::COLOUR => self::RED], $options);
    }

    public function testAnUnpublishedVariantIsLeftOut(): void
    {
        $this->variant(3, [self::ML_250]);
        $this->variant(6, [self::ML_750], ['published' => 0]);

        self::assertSame([3], $this->ids($this->repository->visibleVariants(1, 1, self::NOW)));
    }

    public function testAVariantOutsideItsTimeWindowIsLeftOut(): void
    {
        $this->variant(3, [self::ML_250], ['start' => (string) (self::NOW + 100)]);
        $this->variant(6, [self::ML_750], ['stop' => (string) (self::NOW - 100)]);
        $this->variant(7, [self::ML_1000]);

        self::assertSame([7], $this->ids($this->repository->visibleVariants(1, 1, self::NOW)));
    }

    public function testAVariantRestrictedToAnotherShopIsLeftOut(): void
    {
        $this->insertProductShop(['pid' => 1, 'shop' => 2]);
        $this->variant(3, [self::ML_250]);
        $this->variant(6, [self::ML_750]);
        $this->insertProductShop(['pid' => 6, 'shop' => 2]);

        self::assertSame([3], $this->ids($this->repository->visibleVariants(1, 1, self::NOW)));
        self::assertSame([3, 6], $this->ids($this->repository->visibleVariants(1, 2, self::NOW)));
    }

    public function testNothingWhenTheMainProductIsUnpublished(): void
    {
        $this->catalogConnection->update('tl_consho_product', ['published' => 0], ['id' => 1]);
        $this->variant(3, [self::ML_250]);

        self::assertSame([], $this->repository->visibleVariants(1, 1, self::NOW));
    }

    public function testVariantsOfAnotherProductAreNotMixedIn(): void
    {
        $this->insertProduct(['id' => 2, 'title' => 'Anderes', 'published' => 1]);
        $this->insertProductShop(['pid' => 2, 'shop' => 1]);
        $this->variant(3, [self::ML_250]);
        $this->variant(8, [self::ML_750], ['pid' => 2]);

        self::assertSame([3], $this->ids($this->repository->visibleVariants(1, 1, self::NOW)));
    }

    public function testTheOrderIsSortingThenId(): void
    {
        // sorting collides after copying — then id decides.
        $this->variant(7, [self::ML_250], ['sorting' => 64]);
        $this->variant(5, [self::ML_750], ['sorting' => 128]);
        $this->variant(6, [self::ML_1000], ['sorting' => 64]);

        self::assertSame([6, 7, 5], $this->ids($this->repository->visibleVariants(1, 1, self::NOW)));
    }

    public function testAVariantWithoutOptionsIsStillAVariant(): void
    {
        // Maintained incompletely, but published: the matrix reaches it through
        // no axis, the controller does through ?variant=.
        $this->variant(3, []);

        $rows = $this->repository->visibleVariants(1, 1, self::NOW);

        self::assertSame([3], $this->ids($rows));
        self::assertSame([], $rows[0]->options);
    }

    public function testAProductWithoutVariantsHasNone(): void
    {
        self::assertSame([], $this->repository->visibleVariants(1, 1, self::NOW));
        self::assertSame([], $this->repository->axes(1));
        self::assertSame([], $this->repository->matrixFor(1, 1, self::NOW)->axes());
    }

    public function testAVariantWithOwnWarningsIsLeftOutInALanguageWithoutTheirTranslation(): void
    {
        // Own warnings without a French row: invisible in French — through
        // VisibilityCriteria::variant().
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Bitter suédois', 'alias' => 'bitter-suedois']);
        $this->variant(3, [self::ML_250]);
        $this->variant(6, [self::ML_750], ['safetyWarnings' => 'Enthält 40 % Alkohol']);

        self::assertSame([3, 6], $this->ids($this->repository->visibleVariants(1, 1, self::NOW, 'de', 'de')));
        self::assertSame([3], $this->ids($this->repository->visibleVariants(1, 1, self::NOW, 'fr', 'de')));
    }

    // ── Axes ─────────────────────────────────────────────────────────────

    public function testAxesComeWithGroupAndOptionLabels(): void
    {
        $this->variant(3, [self::ML_250, self::RED]);
        $this->variant(6, [self::ML_750, self::RED]);

        self::assertSame(
            [
                self::COLOUR => ['label' => 'Farbe', 'options' => [self::RED => 'Rot']],
                self::VOLUME => ['label' => 'Füllmenge', 'options' => [self::ML_250 => '250 ml', self::ML_750 => '750 ml']],
            ],
            $this->repository->axes(1),
        );
    }

    public function testAxesAreTranslatedOrFallBack(): void
    {
        // In fr "Contenance" comes before "Couleur" — the groups follow the
        // label the visitor reads. "750 ml" has no translation
        // and stays as it is.
        $this->variant(3, [self::ML_250, self::RED]);
        $this->variant(6, [self::ML_750, self::RED]);
        $this->insertTranslation(['pid' => self::VOLUME, 'ptable' => 'tl_consho_property_group', 'language' => 'fr', 'title' => 'Contenance']);
        $this->insertTranslation(['pid' => self::COLOUR, 'ptable' => 'tl_consho_property_group', 'language' => 'fr', 'title' => 'Couleur']);
        $this->insertTranslation(['pid' => self::ML_250, 'ptable' => 'tl_consho_property_option', 'language' => 'fr', 'title' => '25 cl']);
        $this->insertTranslation(['pid' => self::RED, 'ptable' => 'tl_consho_property_option', 'language' => 'fr', 'title' => '']);

        self::assertSame(
            [
                self::VOLUME => ['label' => 'Contenance', 'options' => [self::ML_250 => '25 cl', self::ML_750 => '750 ml']],
                self::COLOUR => ['label' => 'Couleur', 'options' => [self::RED => 'Rot']],
            ],
            $this->repository->axes(1, 'fr', 'de'),
        );
    }

    public function testOptionsComeBySortingThenId(): void
    {
        $this->insertPropertyOption(['id' => 14, 'pid' => self::VOLUME, 'title' => '500 ml', 'sorting' => 128]);
        $this->variant(3, [self::ML_1000]);
        $this->variant(4, [self::ML_750]);
        $this->variant(5, [14]);
        $this->variant(6, [self::ML_250]);

        self::assertSame([self::ML_250, self::ML_750, 14, self::ML_1000], array_keys($this->repository->axes(1)[self::VOLUME]['options']));
    }

    public function testAxesIncludeOptionsOfVariantsThatAreNotVisible(): void
    {
        // "1 l" only exists as an unpublished variant. The option is there
        // so that nothing shifts — VariantMatrix gives it no link.
        $this->variant(3, [self::ML_250]);
        $this->variant(7, [self::ML_1000], ['published' => 0]);

        self::assertSame([self::ML_250 => '250 ml', self::ML_1000 => '1 l'], $this->repository->axes(1)[self::VOLUME]['options']);

        $matrix = $this->repository->matrixFor(1, 1, self::NOW);
        $options = $matrix->axes()[0]->options;

        self::assertSame(3, $options[0]->targetId);
        self::assertNull($options[1]->targetId);
    }

    public function testAnOptionUsedByTwoVariantsAppearsOnce(): void
    {
        $this->variant(3, [self::ML_250, self::RED]);
        $this->variant(6, [self::ML_750, self::RED]);

        self::assertCount(1, $this->repository->axes(1)[self::COLOUR]['options']);
    }

    // ── Matrix ───────────────────────────────────────────────────────────

    public function testMatrixForAcceptsOnlyVisibleVariantsOfThisProduct(): void
    {
        $this->insertProduct(['id' => 2, 'title' => 'Anderes', 'published' => 1]);
        $this->insertProductShop(['pid' => 2, 'shop' => 1]);
        $this->variant(3, [self::ML_250]);
        $this->variant(6, [self::ML_750]);
        $this->variant(7, [self::ML_1000], ['published' => 0]);
        $this->variant(8, [self::ML_250], ['pid' => 2]);

        $matrix = $this->repository->matrixFor(1, 1, self::NOW);

        self::assertTrue($matrix->needsChoice());
        self::assertNotNull($matrix->withSelection(6));
        self::assertNull($matrix->withSelection(7), 'unveröffentlicht');
        self::assertNull($matrix->withSelection(8), 'Variante eines anderen Produkts');
        self::assertNull($matrix->withSelection(1), 'das Hauptprodukt selbst');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param list<int>            $optionIds
     * @param array<string, mixed> $overrides
     */
    private function variant(int $id, array $optionIds, array $overrides = []): void
    {
        $this->insertProduct([
            'id' => $id,
            'pid' => 1,
            'title' => 'Variante '.$id,
            'published' => 1,
            ...$overrides,
        ]);

        foreach ($optionIds as $optionId) {
            $this->insertProductOption(['pid' => $id, 'optionId' => $optionId]);
        }
    }

    /**
     * @param list<VariantRow> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (VariantRow $row) => $row->id, $rows);
    }
}
