<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

interface ProjectionWriterInterface
{
    /**
     * @param int  $subscriptionRule 0 for the one-time price, else the plan the amount is per period of (Abo-2b)
     * @param bool $alsoSubscription a one-time price whose article is also sold as a subscription (P89)
     * @param string|null $compareNet the price struck through next to a promotion (block H, H-2), null for none
     */
    public function write(int $productId, string $currency, string $country, string $net, string $gross, int $subscriptionRule = 0, bool $alsoSubscription = false, string|null $compareNet = null, string|null $compareGross = null): void;

    /**
     * @return list<array{currency: string, country: string, net: string, gross: string, subscriptionRule: int, alsoSubscription: bool, compareNet: string|null, compareGross: string|null}> sorted by currency and country
     */
    public function readAll(int $productId): array;

    public function delete(int $productId, string $currency, string $country): void;

    /**
     * Every product that has a projected row — including rows whose prices
     * are gone. A rebuild that only walks the price table never sees those.
     *
     * @return list<int>
     */
    public function findProductIds(): array;

    /**
     * The fingerprint of the shops' markets the projection was last fully
     * rebuilt for, or null if it never was. Compared by
     * ListPriceProjector::syncMarkets() — not the markets found in the table,
     * which a market without a single priced article never shows.
     */
    public function marketsFingerprint(): int|null;

    public function storeMarketsFingerprint(int $fingerprint): void;
}
