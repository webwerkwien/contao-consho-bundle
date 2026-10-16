<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantMatrix;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantOptionView;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRow;

/**
 * Rein, ohne Datenbank und ohne Contao: Die Matrix bekommt sichtbare Varianten
 * und Achsen und entscheidet nur, wohin jeder Link führt.
 *
 * Größe S/M/L × Farbe rot/blau. Die Varianten-IDs tragen die Kombination:
 * 101 S/rot, 102 S/blau, 103 M/rot, 104 M/blau, 105 L/rot, 106 L/blau.
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

    // ── Ohne Wahl ────────────────────────────────────────────────────────

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
                // Ohne Wahl gibt es nichts, was ein Klick mitändern könnte.
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

    // ── Mit Wahl ─────────────────────────────────────────────────────────

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

        // Die gewählte Option zeigt auf die gewählte Variante selbst.
        self::assertSame(101, $this->option($matrix, self::SIZE, self::S)->targetId);
    }

    public function testAChoiceLaterInTheListStillKeepsTheOtherAxes(): void
    {
        // Gegenprobe zu oben: Bei S/rot ist „die erste Variante mit dieser
        // Option" zufällig auch die richtige. Bei M/blau nicht — hier fiele eine
        // Zielwahl ohne Regel 1 auf S/rot und L/rot statt auf S/blau und M/rot.
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
        // M/rot gewählt, „blau" gibt es als S/blau und L/blau — beide stimmen in
        // null Achsen überein. Es entscheidet die Reihenfolge der Liste
        // (v.sorting, v.id aus dem Repository), nicht die ID.
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
        // „L" gibt es nur als unveröffentlichte Variante: Die Option steht da,
        // damit nichts verrutscht, führt aber nirgends hin.
        $variants = array_values(array_filter($this->all(), static fn (VariantRow $v) => !\in_array($v->id, [105, 106], true)));

        foreach ([new VariantMatrix($variants, $this->axes()), (new VariantMatrix($variants, $this->axes()))->withSelection(101)] as $matrix) {
            self::assertNotNull($matrix);
            $l = $this->option($matrix, self::SIZE, self::L);

            self::assertNull($l->targetId);
            self::assertFalse($l->selected);
            self::assertFalse($l->changesOthers);
        }
    }

    // ── Wahl nötig? ──────────────────────────────────────────────────────

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
        // 202 hat keine Farbe. Über „M" ist sie erreichbar; gewählt ist in der
        // Farbachse nichts markiert.
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
        // Auch mit übergebenen Achsen: Ohne sichtbare Variante führte jeder Link
        // ins Leere — dann keine Achse.
        foreach ([new VariantMatrix([], []), new VariantMatrix([], $this->axes())] as $matrix) {
            self::assertSame([], $matrix->axes());
            self::assertNull($matrix->selectedId());
            self::assertFalse($matrix->needsChoice());
        }
    }

    public function testTheVisibleVariantsAreListedInDisplayOrderWhateverIsChosen(): void
    {
        // Für den „ab"-Preis ohne Wahl: Das Element fragt je sichtbarer Variante
        // den Preis ab und liest dafür keine zweite Liste aus der Datenbank.
        $matrix = new VariantMatrix($this->all(), $this->axes());

        self::assertSame([101, 102, 103, 104, 105, 106], $matrix->variantIds());
        self::assertSame([101, 102, 103, 104, 105, 106], $matrix->withSelection(104)?->variantIds());
        self::assertSame([], (new VariantMatrix([], []))->variantIds());
    }

    public function testASelectionOutsideTheListIsRefused(): void
    {
        // Der Controller antwortet darauf 404 (Entscheidung 3).
        $matrix = new VariantMatrix($this->all(), $this->axes());

        self::assertNull($matrix->withSelection(99));
        self::assertNull($matrix->selectedId(), 'withSelection() ändert die Matrix nicht');
    }

    public function testTheConstructorRefusesASelectionOutsideTheList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VariantMatrix($this->all(), $this->axes(), 99);
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

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
