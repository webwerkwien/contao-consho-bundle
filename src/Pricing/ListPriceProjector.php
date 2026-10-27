<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;

/**
 * Keeps the list price projection in step with the price table.
 *
 * refresh() is the one rule; rebuild() is refresh() over everything. Both
 * write what has a price and DELETE what no longer has one — per market.
 * A projection that only ever writes keeps a product in the listing with a
 * price that is gone.
 *
 * One row per ARTICLE and MARKET (C25): a market is a currency together with
 * a home country some shop sells in, because gross depends on that country.
 * A row disappears when its article has no price in the currency, when the
 * country has no rate for the article's tax class, or when no shop sells in
 * that market any more — three causes, one rule: not wanted, not kept.
 *
 * Inheritance applied: a variant without a price of its own is projected with
 * its main product's (A-4b, E2). The rule comes from ArticlePriceResolver, the
 * class the product page asks — so listing and page cannot disagree about what
 * a variant costs.
 *
 * `verify()` never writes. A verify that repairs hides the very cause one is
 * looking for — and a materialised value that is quietly wrong is worse than
 * none at all, because nobody notices it in a filter.
 *
 * verify() and rebuild() walk the same set: products with a price AND products
 * with a projected row, each widened to its variants. See
 * Block A, 5.2, and Block C, 5.4.
 */
final class ListPriceProjector
{
    public function __construct(
        private readonly PriceRepositoryInterface $repository,
        private readonly ArticlePriceResolver $prices,
        private readonly ProjectionWriterInterface $writer,
        private readonly ArticleTreeInterface $articles,
        private readonly MarketRepositoryInterface $markets,
        // Block H (H-2): the guest price over time and the strike-through
        // price taken from it. Optional for the tests built by hand.
        private readonly PriceHistory|null $history = null,
    ) {
    }

    /**
     * @param list<int> $productIds products that changed; their variants are included
     *
     * @return int number of rows whose amounts changed, were added or deleted
     */
    public function refresh(array $productIds, int|null $timestamp = null): int
    {
        $timestamp ??= time();
        $markets = $this->pricedMarkets();
        $changed = 0;

        foreach ($this->articlesOf($productIds) as $articleId => $mainProductId) {
            // Read BEFORE writing: the boundary cron (I-1) calls refresh() for a
            // price that is merely due, not necessarily changed — a market whose
            // country has no rate stays due every minute forever, and counting
            // every write() would invalidate the listings and sitemap that
            // often, for nothing.
            $existing = [];

            foreach ($this->writer->readAll($articleId) as $row) {
                $existing[$row['currency'].'|'.$row['country']] = $row;
            }

            $wanted = [];

            foreach ($markets as $market) {
                $price = $this->prices->listPrice($articleId, $mainProductId, $market->currency, $market->country, $timestamp);

                if (null === $price) {
                    continue;
                }

                $key = $market->key();
                $wanted[$key] = true;
                $stored = $existing[$key] ?? null;
                $price = $this->withHistory($articleId, $market, $price, $timestamp);

                // Always write — even an unchanged amount needs a fresh tstamp,
                // or a price that is due would stay due every minute (I-1). Only
                // an amount that actually differs counts as a change.
                $this->writer->write($articleId, $market->currency, $market->country, $price->net, $price->gross, $price->subscriptionRule, $price->alsoSubscription, $price->compareNet, $price->compareGross);

                if (null === $stored || !self::amountsMatch($stored, $price)) {
                    ++$changed;
                }
            }

            foreach ($existing as $key => $row) {
                if (!isset($wanted[$key])) {
                    [$currency, $country] = explode('|', $key, 2);
                    $this->writer->delete($articleId, $currency, $country);
                    ++$changed;
                }
            }
        }

        return $changed;
    }

    /**
     * SQLite gives back NUMERIC columns inconsistently — "12" rather than
     * "12.0000" — so a string comparison would count a row as changed on every
     * run. Compared as decimals instead, as FromPriceConsistencyTest does.
     *
     * The plan counts as much as the amount (Abo-2b): "1,20 € / Monat" and
     * "1,20 €" are two different tiles.
     *
     * The strike-through price as well (H-2): a tile that gains or loses it is
     * a changed tile, and the listings showing it have to be invalidated.
     *
     * @param array{currency: string, country: string, net: string, gross: string, subscriptionRule: int, alsoSubscription: bool, compareNet?: string|null, compareGross?: string|null} $stored
     */
    private static function amountsMatch(array $stored, ListPrice $price): bool
    {
        return BigDecimal::of($stored['net'])->isEqualTo($price->net)
            && BigDecimal::of($stored['gross'])->isEqualTo($price->gross)
            && $stored['subscriptionRule'] === $price->subscriptionRule
            && $stored['alsoSubscription'] === $price->alsoSubscription
            && self::sameOptional($stored['compareGross'] ?? null, $price->compareGross);
    }

    private static function sameOptional(string|null $stored, string|null $wanted): bool
    {
        if (null === $stored || null === $wanted) {
            return $stored === $wanted;
        }

        return BigDecimal::of($stored)->isEqualTo($wanted);
    }

    /**
     * Records the guest price and, for a one-time promotion, attaches the
     * lowest price of the 30 days before it began — only when the promotion
     * is below it (block H, B): otherwise there is nothing to strike through.
     */
    private function withHistory(int $articleId, Market $market, ListPrice $price, int $timestamp): ListPrice
    {
        if (null === $this->history) {
            return $price;
        }

        $this->history->record($articleId, $market->currency, $market->country, $price, $timestamp);

        if (!$price->promotion || 0 !== $price->subscriptionRule) {
            return $price;
        }

        $reference = $this->history->reference($articleId, $market->currency, $market->country, $timestamp);

        if (null === $reference || !BigDecimal::of($reference[1])->isGreaterThan($price->gross)) {
            return $price;
        }

        return $price->withCompare($reference[0], $reference[1]);
    }

    /**
     * @return int number of rows whose amounts changed, were added or deleted
     */
    public function rebuild(int|null $timestamp = null): int
    {
        // Taken BEFORE the refresh, stored after it. Taken afterwards, a change
        // of markets saved while this rebuild was running would be recorded as
        // applied although part of the rows still carry the old country — and
        // nothing would ever compare again. Taken before, such a change leaves
        // a stale fingerprint behind and costs one rebuild too many at worst.
        $fingerprint = self::marketsFingerprint($this->markets->findMarkets());

        $changed = $this->refresh($this->productIdsToCheck(), $timestamp);

        // Every rebuild() walks every market by definition, so this is the one
        // place the fingerprint needs to be written — consho:price:rebuild and
        // every migration that calls rebuild() carry it along for free.
        $this->writer->storeMarketsFingerprint($fingerprint);

        return $changed;
    }

    /**
     * Brings the projection in line with the markets the shops sell in.
     *
     * Compared by a fingerprint of the shops' markets (currency and home
     * country), not by the markets the writer's rows actually carry: a market
     * in which not a single article can be priced — no rate yet for any class
     * in that country, or only future prices in the currency — never gets a
     * row, so a row-based comparison would differ forever and rebuild the
     * whole catalogue in PHP on every single save of tl_page or
     * tl_consho_shop (I-2). The fingerprint is built from
     * MarketRepositoryInterface::findMarkets() — the shops' markets — not
     * pricedMarkets(): built from priced markets, it would already change the
     * moment the first price in a new currency is saved, defeating the point.
     *
     * Without a stored fingerprint this rebuilds exactly once. A market that
     * later gains its first rate or price is caught by
     * TaxRateRefreshTriggerListener, PriceRefreshTriggerListener (a first
     * price in a new currency) or the boundary cron, not by this
     * comparison — the fingerprint does not change just because a market
     * became priceable. Same pattern, same reason, as
     * VisibilityProjector::syncShops(): a fingerprint of what the projection
     * was built for, not a comparison against what happens to be in the table.
     *
     * @return bool whether a rebuild ran
     */
    public function syncMarkets(int|null $timestamp = null): bool
    {
        $fingerprint = self::marketsFingerprint($this->markets->findMarkets());

        if ($fingerprint === $this->writer->marketsFingerprint()) {
            return false;
        }

        $this->rebuild($timestamp);

        return true;
    }

    /**
     * @param list<Market> $markets
     */
    private static function marketsFingerprint(array $markets): int
    {
        $keys = array_map(static fn (Market $market): string => $market->key(), $markets);
        sort($keys);

        // crc32() is never negative on 64-bit PHP and fits `value int unsigned`
        // — same as VisibilityProjector::fingerprints().
        return crc32(implode(',', $keys));
    }

    /**
     * @return list<array{productId: int, currency: string, country: string, storedNet: string|null, storedGross: string|null, expectedNet: string|null, expectedGross: string|null}>
     */
    public function verify(int|null $timestamp = null): array
    {
        $timestamp ??= time();
        $markets = $this->pricedMarkets();
        $deviations = [];

        foreach ($this->articlesOf($this->productIdsToCheck()) as $articleId => $mainProductId) {
            $stored = [];

            foreach ($this->writer->readAll($articleId) as $row) {
                $stored[$row['currency'].'|'.$row['country']] = $row;
            }

            $expected = [];

            foreach ($markets as $market) {
                $price = $this->prices->listPrice($articleId, $mainProductId, $market->currency, $market->country, $timestamp);

                if (null !== $price) {
                    $expected[$market->key()] = $price;
                }
            }

            foreach (array_unique([...array_keys($expected), ...array_keys($stored)]) as $key) {
                $price = $expected[$key] ?? null;
                $row = $stored[$key] ?? null;

                if (null !== $price && null !== $row && $row['net'] === $price->net && $row['gross'] === $price->gross
                    && $row['subscriptionRule'] === $price->subscriptionRule && $row['alsoSubscription'] === $price->alsoSubscription) {
                    continue;
                }

                [$currency, $country] = explode('|', (string) $key, 2);

                $deviations[] = [
                    'productId' => $articleId,
                    'currency' => $currency,
                    'country' => $country,
                    'storedNet' => $row['net'] ?? null,
                    'storedGross' => $row['gross'] ?? null,
                    'expectedNet' => $price?->net,
                    'expectedGross' => $price?->gross,
                ];
            }
        }

        return $deviations;
    }

    /**
     * The markets worth asking: a currency without a single price row cannot
     * produce a list price, whatever the country.
     *
     * @return list<Market>
     */
    private function pricedMarkets(): array
    {
        $currencies = $this->repository->findCurrencies();

        return array_values(array_filter(
            $this->markets->findMarkets(),
            static fn (Market $market): bool => \in_array($market->currency, $currencies, true),
        ));
    }

    /**
     * @return list<int>
     */
    private function productIdsToCheck(): array
    {
        return array_values(array_unique([
            ...$this->repository->findProductIdsWithPrices(),
            ...$this->writer->findProductIds(),
        ]));
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, int> article id => main product id
     */
    private function articlesOf(array $productIds): array
    {
        $articles = $this->articles->articlesOf($productIds);

        // A deleted product is gone from the product table, but its projected
        // rows are not: it stands for itself, finds no price, and loses them.
        foreach ($productIds as $productId) {
            $articles[$productId] ??= $productId;
        }

        return $articles;
    }
}
