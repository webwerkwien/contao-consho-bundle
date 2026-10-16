<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dev;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A reproducible test catalogue for consho:dev:benchmark (plan B-9).
 *
 * Development only, and deliberately pure: it computes rows, it writes nothing.
 * CatalogSeedWriter puts them into the database.
 *
 * ── Reproducible per product ──────────────────────────────────────────────
 *
 * Every product draws from its own generator, seeded with the catalogue seed
 * and its number. Product 4711 is the same whether the catalogue has 5,000 or
 * 100,000 products, and whether it is written in batches of 1,000 or 7 — so a
 * measurement can be repeated and two sizes compared at all.
 *
 * ── Skewed, not uniform (B-9, decision 2) ─────────────────────────────────
 *
 * A catalogue where every option is equally common measures a shop nobody
 * has. Groups have 2 to 40 values, values follow a Zipf curve, and "Bio"
 * sits on 60 % of the products — the filter that hurts.
 *
 * ── Out of the way of real data ───────────────────────────────────────────
 *
 * Products from 10,000,000, groups and options from 9,000,000. The writer
 * removes only those ranges, so a seed on a maintained test installation never
 * touches what was entered by hand.
 */
final class CatalogSeedPlan
{
    /**
     * The two currencies a seed writes prices in.
     *
     * The second one exists so the catalogue holds price rows a listing must
     * NOT show — without it a measurement cannot tell "filtered correctly"
     * from "there was nothing else anyway".
     *
     * Until C-1c it was hard-wired as `'EUR' === $currency ? 'CHF' : 'EUR'`,
     * which wrote rows in a currency no shop sells in — a state the catalogue
     * cannot reach, so the seed measured against a fiction. Now it is the
     * shop's own second currency, and the old fallback only steps in where a
     * shop accepts exactly one. Then the rows are deliberately unreachable,
     * which is the point.
     *
     * Lives here rather than in DevSeedCommand because it is a rule about the
     * catalogue, not about a console call — and because there is no test for
     * that command, while this class has one.
     *
     * @param list<string> $accepted the shop's accepted currencies, sorted
     *
     * @return array{string, string} the currency to price in, and the second
     */
    public static function currenciesOf(array $accepted): array
    {
        $currency = $accepted[0] ?? '';

        return [$currency, $accepted[1] ?? ('EUR' === $currency ? 'CHF' : 'EUR')];
    }

    public const PRODUCT_OFFSET = 10_000_000;

    public const OPTION_OFFSET = 9_000_000;

    /** A main product and up to three variants. */
    private const SLOT = 4;

    /**
     * alias => [title, number of values, share of main products carrying the group]
     *
     * "groesse" is a variant axis only; "farbe" is both a property and the
     * second axis.
     */
    private const GROUPS = [
        'merkmal' => ['Merkmal', 2, 1.0],
        'marke' => ['Marke', 40, 0.9],
        'herkunft' => ['Herkunft', 24, 0.7],
        'sorte' => ['Sorte', 12, 0.8],
        'farbe' => ['Farbe', 8, 0.5],
        'groesse' => ['Größe', 4, 0.0],
    ];

    public function __construct(
        public readonly int $products,
        public readonly int $seed,
        public readonly int $shopId,
        public readonly string $currency,
        public readonly string $secondCurrency,
        public readonly string $language,
        public readonly int $now,
    ) {
    }

    /**
     * @return list<array{id: int, alias: string, title: string, options: list<array{id: int, alias: string, title: string}>}>
     */
    public function groups(): array
    {
        $groups = [];
        $index = 0;

        foreach (self::GROUPS as $alias => [$title, $size]) {
            ++$index;
            $options = [];

            for ($k = 0; $k < $size; ++$k) {
                $options[] = [
                    'id' => self::optionId($index, $k),
                    'alias' => 'merkmal' === $alias ? (0 === $k ? 'bio' : 'konventionell') : \sprintf('%s-%d', $alias, $k + 1),
                    'title' => 'merkmal' === $alias ? (0 === $k ? 'Bio' : 'Konventionell') : \sprintf('%s %d', $title, $k + 1),
                ];
            }

            $groups[] = ['id' => self::OPTION_OFFSET + $index, 'alias' => 'seed-'.$alias, 'title' => $title, 'options' => $options];
        }

        return $groups;
    }

    /** The option on 60 % of the products. */
    public static function frequentOptionId(): int
    {
        return self::optionId(self::groupIndex('merkmal'), 0);
    }

    /**
     * Six values over five groups — the case from the original question.
     *
     * @return list<int>
     */
    public static function sixOptionIds(): array
    {
        return [
            self::optionId(self::groupIndex('merkmal'), 0),
            self::optionId(self::groupIndex('marke'), 0),
            self::optionId(self::groupIndex('marke'), 1),
            self::optionId(self::groupIndex('herkunft'), 0),
            self::optionId(self::groupIndex('sorte'), 0),
            self::optionId(self::groupIndex('farbe'), 0),
        ];
    }

    /** The property group an option of this catalogue belongs to. */
    public static function groupIdOf(int $optionId): int
    {
        return self::OPTION_OFFSET + intdiv($optionId - self::OPTION_OFFSET, 1000);
    }

    /** A value of the variant axis. */
    public static function variantAxisOptionId(): int
    {
        return self::optionId(self::groupIndex('groesse'), 1);
    }

    /**
     * The rows of main product number $n and its variants, per table.
     *
     * @return array{products: list<array<string, int|string>>, properties: list<array<string, int>>, options: list<array<string, int>>, shops: list<array<string, int>>, prices: list<array<string, int|string>>, translations: list<array<string, int|string>>}
     */
    public function rows(int $n): array
    {
        $random = new Randomizer(new Mt19937(($this->seed * 1_000_003 + $n) & 0x7FFFFFFF));
        $mainId = self::PRODUCT_OFFSET + $n * self::SLOT;

        // Every draw happens in this order whatever the options are, so a
        // product stays the same across catalogues.
        $published = $random->nextFloat() >= 0.05;
        $priced = $random->nextFloat() >= 0.03;
        $hasVariants = $random->nextFloat() < 0.15;
        $twoAxes = $random->nextFloat() < 0.3;
        $variantCount = $twoAxes ? 3 : $random->getInt(2, 3);
        $translated = $random->nextFloat() < 0.7;

        $rows = ['products' => [], 'properties' => [], 'options' => [], 'shops' => [], 'prices' => [], 'translations' => []];

        $rows['products'][] = $this->product($mainId, 0, \sprintf('Seed %d', $n), $published, $n);
        $rows['shops'][] = ['pid' => $mainId, 'shop' => $this->shopId, 'tstamp' => $this->now];

        $groupIndex = 0;

        foreach (self::GROUPS as $alias => [, $size, $share]) {
            ++$groupIndex;
            $carries = $random->nextFloat() < $share;
            $pick = 'merkmal' === $alias ? ($random->nextFloat() < 0.6 ? 0 : 1) : self::zipf($random, $size);

            if ($carries) {
                $rows['properties'][] = ['pid' => $mainId, 'optionId' => self::optionId($groupIndex, $pick), 'tstamp' => $this->now];
            }
        }

        $articles = [$mainId];

        if ($hasVariants) {
            $articles = [];

            for ($k = 1; $k <= $variantCount; ++$k) {
                $variantId = $mainId + $k;
                $articles[] = $variantId;
                $rows['products'][] = $this->product($variantId, $mainId, \sprintf('Seed %d V%d', $n, $k), true, $k);
                $rows['options'][] = ['pid' => $variantId, 'optionId' => self::optionId(self::groupIndex('groesse'), $k - 1), 'tstamp' => $this->now];

                if ($twoAxes) {
                    $rows['options'][] = ['pid' => $variantId, 'optionId' => self::optionId(self::groupIndex('farbe'), $k % 2), 'tstamp' => $this->now];
                }
            }
        }

        foreach ($articles as $articleId) {
            $cents = $random->getInt(300, 30000);
            $scale = $random->nextFloat() < 0.2;

            if (!$priced) {
                continue;
            }

            $rows['prices'][] = $this->price($articleId, $this->currency, $cents, 1);
            $rows['prices'][] = $this->price($articleId, $this->secondCurrency, (int) round($cents * 1.1), 1);

            if ($scale) {
                $rows['prices'][] = $this->price($articleId, $this->currency, (int) round($cents * 0.9), 10);
            }
        }

        if ('' !== $this->language && $translated) {
            $rows['translations'][] = [
                'pid' => $mainId,
                'ptable' => 'tl_consho_product',
                'language' => $this->language,
                'tstamp' => $this->now,
                'title' => \sprintf('Seed %d (%s)', $n, $this->language),
                'alias' => \sprintf('seed-%s-%d', $this->language, $mainId),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int|string>
     */
    private function product(int $id, int $pid, string $title, bool $published, int $sorting): array
    {
        return [
            'id' => $id,
            'pid' => $pid,
            'tstamp' => $this->now,
            'title' => $title,
            'alias' => 'seed-'.$id,
            'sku' => 'SEED-'.$id,
            'published' => $published ? 1 : 0,
            'sorting' => $sorting,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function price(int $articleId, string $currency, int $cents, int $minQuantity): array
    {
        // $cents is the end price the seed aims for at 20 %; only net is stored (C22).
        return [
            'pid' => $articleId,
            'tstamp' => $this->now,
            'currency' => $currency,
            'net' => number_format($cents / 100 / 1.2, 4, '.', ''),
            'customerGroup' => 0,
            'minQuantity' => number_format($minQuantity, 4, '.', ''),
        ];
    }

    private static function optionId(int $groupIndex, int $k): int
    {
        return self::OPTION_OFFSET + 1000 * $groupIndex + $k;
    }

    private static function groupIndex(string $alias): int
    {
        return (int) array_search($alias, array_keys(self::GROUPS), true) + 1;
    }

    /** The k-th value with a weight of 1/(k+1): a few are common, most are rare. */
    private static function zipf(Randomizer $random, int $size): int
    {
        $total = 0.0;

        for ($k = 0; $k < $size; ++$k) {
            $total += 1 / ($k + 1);
        }

        $target = $random->nextFloat() * $total;

        for ($k = 0; $k < $size; ++$k) {
            $target -= 1 / ($k + 1);

            if ($target < 0) {
                return $k;
            }
        }

        return $size - 1;
    }
}
