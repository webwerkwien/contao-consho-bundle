<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The shops' currencies and, per currency, their home countries (C22, C25).
 *
 * Price rows — and shipping amounts in C-2 — belong to no shop: the country a
 * gross amount refers to comes from the shops that sell in its currency.
 * Loaded once per request, since the price mask asks from several callbacks,
 * and dropped on kernel.reset so a worker does not keep yesterday's shops.
 */
final class HomeCountries implements ResetInterface
{
    /**
     * @var array<string, list<string>>|null currency => sorted home countries
     */
    private array|null $byCurrency = null;

    public function __construct(private readonly MarketRepositoryInterface $markets)
    {
    }

    /**
     * @return list<string> sorted
     */
    public function currencies(): array
    {
        return array_keys($this->byCurrency());
    }

    /**
     * @return list<string> sorted; empty for a currency no shop sells in
     */
    public function countriesFor(string $currency): array
    {
        return $this->byCurrency()[$currency] ?? [];
    }

    /**
     * @return list<string> every home country, sorted
     */
    public function all(): array
    {
        $all = array_values(array_unique(array_merge([], ...array_values($this->byCurrency()))));
        sort($all);

        return $all;
    }

    /**
     * Whether some currency is sold from more than one home country — only
     * then does the price mask show the country field.
     */
    public function offersChoice(): bool
    {
        foreach ($this->byCurrency() as $countries) {
            if (\count($countries) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stored country if it still belongs to the currency, the only home
     * country if there is one, otherwise none.
     */
    public function resolve(string $currency, string $stored): string|null
    {
        $countries = $this->countriesFor($currency);

        if (\in_array($stored, $countries, true)) {
            return $stored;
        }

        return 1 === \count($countries) ? $countries[0] : null;
    }

    public function reset(): void
    {
        $this->byCurrency = null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function byCurrency(): array
    {
        if (null === $this->byCurrency) {
            $map = [];

            foreach ($this->markets->findMarkets() as $market) {
                $map[$market->currency][] = $market->country;
            }

            foreach ($map as $currency => $countries) {
                $countries = array_values(array_unique($countries));
                sort($countries);
                $map[$currency] = $countries;
            }

            ksort($map);
            $this->byCurrency = $map;
        }

        return $this->byCurrency;
    }
}
