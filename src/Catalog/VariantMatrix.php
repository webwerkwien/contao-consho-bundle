<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * Where each option of the variant choice leads — without a form and without
 * JavaScript.
 *
 * Specification B 6.5: a combination without a variant cannot be chosen. With
 * links instead of form fields (B-4 decision 7) that means: every link leads to
 * a variant that exists and is visible. There is no disabled field to get
 * wrong, and no address that answers 404 after a click.
 *
 * ── Which variant an option leads to ─────────────────────────────────────
 *
 * 1. The visible variant with this option and every other option of the
 *    chosen one — the plain case, only this axis changes.
 * 2. Otherwise the visible variant with this option that agrees with the
 *    chosen one in the most other axes. On a tie the display order decides
 *    (`sorting`, then `id`, as the list arrives). The option is marked
 *    $changesOthers: the click changes another axis as well, and the visitor
 *    should be able to see that before clicking.
 * 3. No visible variant with this option: no target. The option is still
 *    shown, so that nothing moves when the choice changes.
 *
 * Without a choice every option leads to the first variant that has it.
 *
 * Rules 1 and 2 are one loop: the exact combination agrees in every other axis,
 * which is the highest score there is, and the first variant to reach a score
 * keeps it. A variant without an option in some axis agrees with another one
 * that has none there either — "no colour" is a value like any other.
 *
 * ── No choice without the parameter ──────────────────────────────────────
 *
 * selectedId() is only ever the variant from `?variant=`, or the only visible
 * one. Preselecting "the first" would put one variant's price and GTIN on the
 * canonical address, which speaks for the whole product group (plan B-5,
 * decision 3).
 */
final class VariantMatrix
{
    /** @var array<int, VariantRow> */
    private readonly array $byId;

    /**
     * @param list<VariantRow>                                               $variants visible variants, in display order
     * @param array<int, array{label: string, options: array<int, string>}> $axes     groupId => label and optionId => label, in display order
     *
     * @throws \InvalidArgumentException when $selectedId is not one of $variants
     */
    public function __construct(
        private readonly array $variants,
        private readonly array $axes,
        private readonly ?int $selectedId = null,
    ) {
        $byId = [];

        foreach ($variants as $variant) {
            $byId[$variant->id] ??= $variant;
        }

        $this->byId = $byId;

        if (null !== $selectedId && !isset($byId[$selectedId])) {
            throw new \InvalidArgumentException(\sprintf('Variant %d is not a visible variant of this product.', $selectedId));
        }
    }

    /**
     * The same matrix with this variant chosen.
     *
     * Null when it is not a visible variant of this product — unpublished,
     * another product's, the main product itself. The element answers 404 for
     * that: Consho never builds such an address.
     */
    public function withSelection(int $variantId): ?self
    {
        if (!isset($this->byId[$variantId])) {
            return null;
        }

        return new self($this->variants, $this->axes, $variantId);
    }

    /**
     * The chosen variant, or the only visible one — that one is the article to
     * buy without any choice.
     */
    public function selectedId(): ?int
    {
        if (null !== $this->selectedId) {
            return $this->selectedId;
        }

        return 1 === \count($this->variants) ? $this->variants[0]->id : null;
    }

    /**
     * The visible variants' ids in display order, whatever is chosen.
     *
     * For the "from" price without a choice: the element asks each visible
     * variant for its price and reads no second list from the database for it.
     *
     * @return list<int>
     */
    public function variantIds(): array
    {
        return array_map(static fn (VariantRow $variant): int => $variant->id, $this->variants);
    }

    /**
     * Whether the visitor still has to pick a variant before there is one
     * article to buy. False for a product without variants.
     */
    public function needsChoice(): bool
    {
        return [] !== $this->variants && null === $this->selectedId();
    }

    /**
     * @return list<VariantAxisView>
     */
    public function axes(): array
    {
        // Without a visible variant every link would lead nowhere.
        if ([] === $this->variants) {
            return [];
        }

        $selectedId = $this->selectedId();
        $selected = null === $selectedId ? null : $this->byId[$selectedId];
        $groupIds = array_keys($this->axes);
        $views = [];

        foreach ($this->axes as $groupId => $axis) {
            $options = [];

            foreach ($axis['options'] as $optionId => $label) {
                [$targetId, $changesOthers] = $this->target($groupId, $optionId, $selected, $groupIds);

                $options[] = new VariantOptionView(
                    $optionId,
                    $label,
                    null !== $selected && ($selected->options[$groupId] ?? null) === $optionId,
                    $targetId,
                    $changesOthers,
                );
            }

            $views[] = new VariantAxisView($groupId, $axis['label'], $options);
        }

        return $views;
    }

    /**
     * @param list<int> $groupIds
     *
     * @return array{0: int|null, 1: bool} target variant, changes another axis
     */
    private function target(int $groupId, int $optionId, ?VariantRow $selected, array $groupIds): array
    {
        $best = null;
        $bestScore = -1;

        foreach ($this->variants as $variant) {
            if (($variant->options[$groupId] ?? null) !== $optionId) {
                continue;
            }

            if (null === $selected) {
                return [$variant->id, false];
            }

            $score = 0;

            foreach ($groupIds as $otherGroupId) {
                if ($otherGroupId !== $groupId && ($variant->options[$otherGroupId] ?? null) === ($selected->options[$otherGroupId] ?? null)) {
                    ++$score;
                }
            }

            // Strictly greater: on a tie the earlier variant in display order stays.
            if ($score > $bestScore) {
                $best = $variant;
                $bestScore = $score;
            }
        }

        if (null === $best) {
            return [null, false];
        }

        return [$best->id, $bestScore < \count($groupIds) - 1];
    }
}
