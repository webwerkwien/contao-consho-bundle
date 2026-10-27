<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantMatrix;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantOptionView;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRow;

/**
 * Pure, without a database and without Contao: the matrix gets visible
 * variants and axes and only decides where each link leads.
 *
 * Size S/M/L × colour red/blue. The variant IDs carry the combination:
 * 101 S/red, 102 S/blue, 103 M/red, 104 M/blue, 105 L/red, 106 L/blue.
 */
class VariantMatrixTest extends TestCase
{
    private const SIZE = 1;
    private const COLOUR = 2;

    private const S = 11;
    private const M = 12;
    private const L = 13;
    private const RED = 21;
    private const BLUE = 22;

    // ── Without a choice ─────────────────────────────────────────────────

    public function testWithoutAChoiceEveryOptionLeadsToTheFirstVariantThatHasIt(): void
    {
        $matrix = new VariantMatrix($this->all(), $this->axes());

        self::assertSame(101, $this->option($matrix, self::SIZE, self::S)->targetId);
        self::assertSame(103, $this->option($matrix, self::SIZE, self::M)->targetId);
        self::assertSame(105, $this->option($matrix, self::SIZE, self::L)->targetId);
        self::assertSame(101, $this->option($matrix, self::COLOUR, self::RED)->targetId);
        self::assertSame(102, $this->option($matrix, self::COLOUR, self::BLUE)->targetId);

        foreach ($matrix->axes() as $axis) {
            foreach ($axis->options as $option) {
                self::assertFalse($option->selected);
                // Without a choice there is nothing a click could change along with it.
                self::assertFalse($option->changesOthers);
            }
        }
    }

    public function testAxesKeepTheirOrderAndLabels(): void
    {
        $axes = (new VariantMatrix($this->all(), $this->axes()))->axes();

        self::assertSame([self::SIZE, self::COLOUR], array_map(static fn ($a) => $a->groupId, $axes));
        self::assertSame('Größe', $axes[0]->label);
        self::assertSame([self::S, self::M, self::L], array_map(static fn ($o) => $o->optionId, $axes[0]->options));
        self::assertSame('M', $axes[0]->options[1]->label);
        self::assertNull($axes[0]->options[1]->url);
    }

    // ── With a choice ────────────────────────────────────────────────────

    public function testAChoiceKeepsTheOtherAxes(): void
    {
        $matrix = (new VariantMatrix($this->all(), $this->axes()))->withSelection(101);
        self::assertNotNull($matrix);

        self::assertSame(103, $this->option($matrix, self::SIZE, self::M)->targetId);
        self::assertSame(102, $this->option($matrix, self::COLOUR, self::BLUE)->targetId);
        self::assertFalse($this->option($matrix, self::COLOUR, self::BLUE)->changesOthers);

        self::assertTrue($this->option($matrix, self::SIZE, self::S)->selected);
        self::assertTrue($this->option($matrix, self::COLOUR, self::RED)->selected);
        self::assertFalse($this->option($matrix, self::SIZE, self::M)->selected);

        // The chosen option points to the chosen variant itself.
        self::assertSame(101, $this->option($matrix, self::SIZE, self::S)->targetId);
    }

    public function testAChoiceLaterInTheListStillKeepsTheOtherAxes(): void
    {
        // Counter-check to the above: with S/red, "the first variant with this
        // option" happens to be the right one too. With M/blue it is not — here a
        // target choice without rule 1 would land on S/red and L/red instead of
        // on S/blue and M/red.
        $matrix = (new VariantMatrix($this->all(), $this->axes()))->withSelection(104);
        self::assertNotNull($matrix);

        self::assertSame(102, $this->option($matrix, self::SIZE, self::S)->targetId);
        self::assertSame(106, $this->option($matrix, self::SIZE, self::L)->targetId);
        self::assertSame(103, $this->option($matrix, self::COLOUR, self::RED)->targetId);
        self::assertFalse($this->option($matrix, self::SIZE, self::S)->changesOthers);
    }

    public function testAMissingCombinationLeadsToTheClosestVariant(): void
    {
        $matrix = (new VariantMatrix($this->without(104), $this->axes()))->withSelection(102);
        self::assertNotNull($matrix);

        $m = $this->option($matrix, self::SIZE, self::M);

        self::assertSame(103, $m->targetId);
        self::assertTrue($m->changesOthers);
    }

    public function testOnATieTheDisplayOrderDecides(): void
    {
        // M/red chosen, "blue" exists as S/blue and L/blue — both agree in
        // zero axes. The order of the list decides
        // (v.sorting, v.id from the repository), not the ID.
        $variants = $this->without(104);

        $matrix = (new VariantMatrix($variants, $this->axes()))->withSelection(103);
        self::assertNotNull($matrix);
        $blue = $this->option($matrix, self::COLOUR, self::BLUE);

        self::assertSame(102, $blue->targetId);
        self::assertTrue($blue->changesOthers);

        $reordered = [...array_reverse($variants)];
        $matrix = (new VariantMatrix($reordered, $this->axes()))->withSelection(103);
        self::assertNotNull($matrix);

        self::assertSame(106, $this->option($matrix, self::COLOUR, self::BLUE)->targetId);
    }

    public function testAnOptionWithoutAVisibleVariantIsNotLinked(): void
    {
        // "L" only exists as an unpublished variant: the option is there
        // so that nothing shifts, but leads nowhere.
        $variants = array_values(array_filter($this->all(), static fn (VariantRow $v) => !\in_array($v->id, [105, 106], true)));

        foreach ([new VariantMatrix($variants, $this->axes()), (new VariantMatrix($variants, $this->axes()))->withSelection(101)] as $matrix) {
            self::assertNotNull($matrix);
            $l = $this->option($matrix, self::SIZE, self::L);

            self::assertNull($l->targetId);
            self::assertFalse($l->selected);
            self::assertFalse($l->changesOthers);
        }
    }

    // ── Choice needed? ───────────────────────────────────────────────────

    public function testTheOnlyVariantIsTheChosenOne(): void
    {
        $matrix = new VariantMatrix([$this->row(101, self::S, self::RED)], $this->axes());

        self::assertSame(101, $matrix->selectedId());
        self::assertFalse($matrix->needsChoice());
        self::assertTrue($this->option($matrix, self::SIZE, self::S)->selected);
    }

    public function testTwoVariantsWithoutAChoiceNeedAChoice(): void
    {
        $matrix = new VariantMatrix([$this->row(101, self::S, self::RED), $this->row(102, self::S, self::BLUE)], $this->axes());

        self::assertNull($matrix->selectedId());
        self::assertTrue($matrix->needsChoice());

        $chosen = $matrix->withSelection(102);
        self::assertNotNull($chosen);

        self::assertSame(102, $chosen->selectedId());
        self::assertFalse($chosen->needsChoice());
    }

    public function testAVariantMissingAnAxisIsReachableThroughTheAxesItHas(): void
    {
        // 202 has no colour. It is reachable via "M"; nothing is marked as
        // chosen on the colour axis.
        $variants = [
            $this->row(201, self::S, self::RED),
            new VariantRow(202, [self::SIZE => self::M]),
        ];

        $matrix = new VariantMatrix($variants, $this->axes());
        self::assertSame(202, $this->option($matrix, self::SIZE, self::M)->targetId);

        $chosen = $matrix->withSelection(202);
        self::assertNotNull($chosen);

        self::assertTrue($this->option($chosen, self::SIZE, self::M)->selected);
        self::assertFalse($this->option($chosen, self::COLOUR, self::RED)->selected);
        self::assertFalse($this->option($chosen, self::COLOUR, self::BLUE)->selected);

        $red = $this->option($chosen, self::COLOUR, self::RED);
        self::assertSame(201, $red->targetId);
        self::assertTrue($red->changesOthers);
    }

    public function testWithoutVariantsThereIsNothingToChoose(): void
    {
        // Even with axes passed: without a visible variant every link would lead
        // nowhere — then no axis.
        foreach ([new VariantMatrix([], []), new VariantMatrix([], $this->axes())] as $matrix) {
            self::assertSame([], $matrix->axes());
            self::assertNull($matrix->selectedId());
            self::assertFalse($matrix->needsChoice());
        }
    }

    public function testTheVisibleVariantsAreListedInDisplayOrderWhateverIsChosen(): void
    {
        // For the "ab" price without a choice: the element asks for the price of
        // each visible variant and reads no second list from the database for it.
        $matrix = new VariantMatrix($this->all(), $this->axes());

        self::assertSame([101, 102, 103, 104, 105, 106], $matrix->variantIds());
        self::assertSame([101, 102, 103, 104, 105, 106], $matrix->withSelection(104)?->variantIds());
        self::assertSame([], (new VariantMatrix([], []))->variantIds());
    }

    public function testASelectionOutsideTheListIsRefused(): void
    {
        // The controller answers 404 to that.
        $matrix = new VariantMatrix($this->all(), $this->axes());

        self::assertNull($matrix->withSelection(99));
        self::assertNull($matrix->selectedId(), 'withSelection() ändert die Matrix nicht');
    }

    public function testTheConstructorRefusesASelectionOutsideTheList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VariantMatrix($this->all(), $this->axes(), 99);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @return list<VariantRow>
     */
    private function all(): array
    {
        return [
            $this->row(101, self::S, self::RED),
            $this->row(102, self::S, self::BLUE),
            $this->row(103, self::M, self::RED),
            $this->row(104, self::M, self::BLUE),
            $this->row(105, self::L, self::RED),
            $this->row(106, self::L, self::BLUE),
        ];
    }

    /**
     * @return list<VariantRow>
     */
    private function without(int $id): array
    {
        return array_values(array_filter($this->all(), static fn (VariantRow $v) => $v->id !== $id));
    }

    private function row(int $id, int $size, int $colour): VariantRow
    {
        return new VariantRow($id, [self::SIZE => $size, self::COLOUR => $colour]);
    }

    /**
     * @return array<int, array{label: string, options: array<int, string>}>
     */
    private function axes(): array
    {
        return [
            self::SIZE => ['label' => 'Größe', 'options' => [self::S => 'S', self::M => 'M', self::L => 'L']],
            self::COLOUR => ['label' => 'Farbe', 'options' => [self::RED => 'rot', self::BLUE => 'blau']],
        ];
    }

    private function option(VariantMatrix $matrix, int $groupId, int $optionId): VariantOptionView
    {
        foreach ($matrix->axes() as $axis) {
            if ($axis->groupId !== $groupId) {
                continue;
            }

            foreach ($axis->options as $option) {
                if ($option->optionId === $optionId) {
                    return $option;
                }
            }
        }

        self::fail(\sprintf('Option %d in Achse %d fehlt.', $optionId, $groupId));
    }
}
