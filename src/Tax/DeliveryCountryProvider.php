<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

use Contao\StringUtil;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;

/**
 * The delivery countries of a shop are the ones its operator ticked.
 *
 * This used to narrow that list to the countries with a rate for every tax
 * class in use. That was wrong, and the reasoning is worth keeping because the
 * idea is tempting:
 *
 * - The blast radius stood in no relation to the cause. One missing rate for
 *   one class removed a whole country from the entire catalogue, including for
 *   customers buying only standard-rate goods.
 * - It disguised an operator's mistake as a business decision. A forgotten rate
 *   looks exactly like "we do not ship there" — lost orders nobody notices,
 *   because nothing looks broken.
 * - It was the only stage of the three in section 7 that acted silently. The
 *   back end check tells, the hard error shouts, this one just shrank the shop.
 *
 * So the whitelist stands, and incompleteFor() reports what is missing instead
 * of absorbing it. TaxResolver still throws, so nothing is ever mispriced.
 * Changed on 2026-09-09 on Michael's challenge; see specification section 7.
 */
final class DeliveryCountryProvider
{
    public function __construct(
        private readonly TaxRateRepositoryInterface $repository,
        private readonly CountryProvider $countries,
    ) {
    }

    /**
     * @return array<string, string> ISO code => translated label
     */
    public function forShop(ShopModel $shop, string $locale): array
    {
        $whitelist = $this->whitelistOf($shop);

        if ([] === $whitelist) {
            return [];
        }

        // Iterating over the labels rather than over the whitelist keeps
        // Contao's own ordering, which is sorted by translated name.
        $result = [];

        foreach ($this->countries->all($locale) as $code => $label) {
            if (\in_array($code, $whitelist, true)) {
                $result[$code] = $label;
            }
        }

        return $result;
    }

    /**
     * Countries the shop needs but cannot price completely: the ones it offers,
     * and its home country, whose rate makes the gross of every price shown
     * before a delivery country is known (C25) — whether or not it delivers
     * there.
     *
     * The answer the back end check of section 7 needs, and the one an operator
     * asks when an order from abroad fails: which country, and which class.
     *
     * Only countries whose rate is ever asked for: with "Steuer im
     * Bestimmungsland" off, an EU consumer is taxed at the shop's rate
     * (TaxPlace::forDistanceSale(), the rule checkout, order and catalogue
     * use), so that country needs no rate of its own. Found on the sales shop
     * (Nachlese Stufe 2): 26 warnings "Bestellung scheitert" for orders that
     * would have gone through.
     *
     * @return array<string, list<int>> ISO code => tax class ids without a rate
     */
    public function incompleteFor(ShopModel $shop, int $timestamp): array
    {
        $home = $this->countries->normalise((string) $shop->country);
        $taxAtDestination = (bool) $shop->taxAtDestination;

        $whitelist = array_values(array_filter(
            $this->whitelistOf($shop),
            static fn (string $country): bool => strtoupper(TaxPlace::forDistanceSale($country, $home, $taxAtDestination)) === $country,
        ));

        if ('' !== $home && !\in_array($home, $whitelist, true)) {
            $whitelist[] = $home;
        }

        $taxClassIds = $this->repository->findTaxClassIdsUsedByShop((int) $shop->id);

        $missing = [];

        foreach ($taxClassIds as $taxClassId) {
            $configured = array_map(
                fn (string $code): string => $this->countries->normalise($code),
                $this->repository->findConfiguredCountries($taxClassId, $timestamp),
            );

            foreach ($whitelist as $country) {
                if (!\in_array($country, $configured, true)) {
                    $missing[$country][] = $taxClassId;
                }
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function whitelistOf(ShopModel $shop): array
    {
        return array_values(array_unique(array_map(
            fn (string $code): string => $this->countries->normalise($code),
            array_filter(StringUtil::deserialize($shop->deliveryCountries, true), 'is_string'),
        )));
    }
}
