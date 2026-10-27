<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dev;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogSeedPlan;

class CatalogSeedPlanTest extends TestCase
{
    private const NOW = 1_790_000_000;

    public function testTheSameSeedWritesTheSameCatalogue(): void
    {
        // B-9: two runs with the same seed value produce the same catalogue.
        self::assertSame($this->plan()->rows(4711), $this->plan()->rows(4711));
        self::assertNotSame($this->plan(seed: 1)->rows(4711), $this->plan(seed: 2)->rows(4711));
    }

    public function testAProductIsTheSameInEveryCatalogueSize(): void
    {
        // Otherwise 5,000 and 100,000 would be two different catalogues and not comparable.
        self::assertSame($this->plan(products: 100)->rows(42), $this->plan(products: 100_000)->rows(42));
    }

    public function testWithoutALanguageOnlyTheTranslationsAreMissing(): void
    {
        $with = $this->plan()->rows(9);
        $without = $this->plan(language: '')->rows(9);

        self::assertSame([], $without['translations']);
        unset($with['translations'], $without['translations']);
        self::assertSame($with, $without);
    }

    public function testGroupsHaveDifferentSizes(): void
    {
        self::assertSame([2, 40, 24, 12, 8, 4], array_map(static fn (array $group): int => \count($group['options']), $this->plan()->groups()));
    }

    public function testTheDistributionIsSkewed(): void
    {
        $plan = $this->plan(products: 3000);
        $main = 0;
        $bio = 0;
        $withVariants = 0;
        $unpublished = 0;
        $translated = 0;
        $byOption = [];
        $ids = [];

        for ($n = 0; $n < $plan->products; ++$n) {
            $rows = $plan->rows($n);
            ++$main;

            foreach ($rows['products'] as $product) {
                $ids[] = $product['id'];
            }

            $bio += \count(array_filter($rows['properties'], static fn (array $p): bool => CatalogSeedPlan::frequentOptionId() === $p['optionId']));
            $withVariants += \count($rows['products']) > 1 ? 1 : 0;
            $unpublished += 0 === $rows['products'][0]['published'] ? 1 : 0;
            $translated += \count($rows['translations']);

            foreach ($rows['properties'] as $property) {
                $byOption[$property['optionId']] = ($byOption[$property['optionId']] ?? 0) + 1;
            }
        }

        self::assertEqualsWithDelta(0.60, $bio / $main, 0.03, '„Bio" an 60 %');
        self::assertEqualsWithDelta(0.15, $withVariants / $main, 0.02);
        self::assertEqualsWithDelta(0.05, $unpublished / $main, 0.015);
        self::assertEqualsWithDelta(0.70, $translated / $main, 0.03);

        // Zipf: the most frequent brand value is clearly more frequent than the rarest.
        $brands = array_filter($byOption, static fn (int $id): bool => CatalogSeedPlan::groupIdOf($id) === CatalogSeedPlan::OPTION_OFFSET + 2, ARRAY_FILTER_USE_KEY);
        self::assertGreaterThan(10 * min($brands), max($brands));

        self::assertCount(\count($ids), array_unique($ids));
        self::assertGreaterThanOrEqual(CatalogSeedPlan::PRODUCT_OFFSET, min($ids));
    }

    public function testTheBenchmarkOptionsBelongToTheCatalogue(): void
    {
        $optionIds = array_merge(...array_map(static fn (array $group): array => array_column($group['options'], 'id'), $this->plan()->groups()));
        $groupIds = array_column($this->plan()->groups(), 'id');

        foreach ([CatalogSeedPlan::frequentOptionId(), CatalogSeedPlan::variantAxisOptionId(), ...CatalogSeedPlan::sixOptionIds()] as $optionId) {
            self::assertContains($optionId, $optionIds);
            self::assertContains(CatalogSeedPlan::groupIdOf($optionId), $groupIds);
        }

        self::assertCount(5, array_unique(array_map(CatalogSeedPlan::groupIdOf(...), CatalogSeedPlan::sixOptionIds())));
    }

    public function testDieZweiteWaehrungIstDieZweiteDesShops(): void
    {
        // Since C-1c a shop can accept several currencies. The seed then writes
        // price rows in one that really exists — before, the second one was
        // hard-wired and belonged to no shop.
        //
        // ⚠️ The examples are chosen with care: with ['EUR','CHF'] the old
        // fixed choice (EUR → CHF) yields THE SAME, the test would be blind there.
        // GBP does not occur in the fallback and separates the two versions.
        self::assertSame(['EUR', 'GBP'], CatalogSeedPlan::currenciesOf(['EUR', 'GBP']));
        self::assertSame(['CHF', 'GBP'], CatalogSeedPlan::currenciesOf(['CHF', 'GBP', 'USD']));
    }

    public function testBeiNurEinerWaehrungBleibtDieZweiteAbsichtlichFremd(): void
    {
        // The seed needs rows that a list must NOT show — otherwise a
        // measurement cannot tell "filtered correctly" from "there was nothing
        // else anyway".
        self::assertSame(['EUR', 'CHF'], CatalogSeedPlan::currenciesOf(['EUR']));
        self::assertSame(['GBP', 'EUR'], CatalogSeedPlan::currenciesOf(['GBP']));
    }

    private function plan(int $products = 5000, int $seed = 20260913, string $language = 'fr'): CatalogSeedPlan
    {
        return new CatalogSeedPlan($products, $seed, 1, 'EUR', 'CHF', $language, self::NOW);
    }
}
