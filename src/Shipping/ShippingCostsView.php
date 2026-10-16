<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Brick\Math\BigDecimal;
use Webwerkwien\ContaoConshoBundle\Payment\CashOnDeliveryHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * What the shipping costs page shows (E-6, V5) — from the DATA, so the costs
 * stand in one place and not a second time, by hand, on a page. Not a legal
 * text: which statements a shop owes about shipping is the operator's to
 * judge (Michael, 2026-09-22: Consho sets no legal texts).
 *
 * Gross as every price is shown before a delivery country is known: with the
 * rate of the shop's own country (C25), for the method's tax class. Where the
 * method splits its tax over the goods, that gross holds for goods at the
 * class's rate only — the page says so rather than print a number the
 * checkout will not repeat.
 *
 * A module's type calculates its price in code, and there is nothing stored
 * to show: its row says "calculated at checkout".
 */
final class ShippingCostsView
{
    public function __construct(
        private readonly ShippingMethodRepositoryInterface $methods,
        private readonly ShippingMethodTypes $types,
        private readonly PaymentMethodRepositoryInterface $payments,
        private readonly GrossCalculator $gross,
        private readonly MoneyFormatter $money,
        private readonly CountryProvider $countries,
    ) {
    }

    /**
     * @return array{
     *     methods: list<array{
     *         title: string,
     *         countries: list<string>,
     *         prices: list<array{upTo: string, amount: string}>,
     *         calculated: bool,
     *         proportional: bool,
     *         cashOnDelivery: bool,
     *         limits: array{length: string, girth: string, weight: string},
     *     }>,
     *     codFee: bool,
     * }
     */
    public function of(int $shopId, string $currency, string $shopCountry, string $language, string $fallback, int $now): array
    {
        $names = $this->countries->all('' !== $language ? $language : 'en');
        $cod = $this->cod($shopId, $language, $fallback);
        $rows = [];

        foreach ($this->methods->forShop($shopId, $currency, $language, $fallback) as $method) {
            if (!$this->types->has($method->type)) {
                continue;
            }

            $stored = \in_array($method->type, [FlatRateShipping::getType(), WeightRateShipping::getType()], true);
            $prices = [];

            if (FlatRateShipping::getType() === $method->type && \is_string($method->config['net'] ?? null)) {
                $prices[] = ['upTo' => '', 'amount' => $this->amount($method->config['net'], $method, $currency, $shopCountry, $language, $now)];
            }

            if (WeightRateShipping::getType() === $method->type) {
                foreach (\is_array($method->config['rates'] ?? null) ? $method->config['rates'] : [] as $rate) {
                    if (!\is_array($rate) || !is_numeric($rate['maxWeight'] ?? null) || !BigDecimal::of((string) $rate['maxWeight'])->isPositive()) {
                        continue;
                    }

                    $prices[] = [
                        'upTo' => $this->money->quantity((string) $rate['maxWeight'], $language),
                        'amount' => $this->amount((string) $rate['net'], $method, $currency, $shopCountry, $language, $now),
                    ];
                }
            }

            // Stored prices, none in this currency: the checkout will not
            // offer it either (DbalShippingPriceIssueSource says so).
            if ($stored && [] === $prices) {
                continue;
            }

            $rows[] = [
                'title' => $method->title,
                'countries' => array_values(array_filter(array_map(static fn (string $code): string => $names[$code] ?? '', $method->countries))),
                'prices' => $prices,
                'calculated' => !$stored,
                'proportional' => ShippingTaxMode::Proportional === $method->taxMode,
                // Only where the shop also offers cash on delivery as a
                // payment method (Fable review E, K4).
                'cashOnDelivery' => $cod['offered'] && $this->types->get($method->type)->capabilities($method->config)->cashOnDelivery,
                'limits' => [
                    'length' => $this->limit($method->limits->maxLength, $language),
                    'girth' => $this->limit($method->limits->maxGirth, $language),
                    'weight' => $this->limit($method->limits->maxPieceWeight, $language),
                ],
            ];
        }

        return ['methods' => $rows, 'codFee' => $cod['fee']];
    }

    private function amount(string $net, ShippingMethod $method, string $currency, string $country, string $language, int $now): string
    {
        try {
            return $this->money->format($this->gross->gross($net, $method->taxClassId, $country, $now), $currency, $language);
        } catch (TaxRateNotFound) {
            // No rate: the checkout refuses too, and TaxRateMissing says why.
            return '';
        }
    }

    private function limit(string $value, string $language): string
    {
        return BigDecimal::of($value)->isPositive() ? $this->money->quantity($value, $language) : '';
    }

    /**
     * Whether the shop offers cash on delivery, and with a fee — the fee as a
     * hint, not an amount: how it is taxed can depend on the basket (E-1).
     *
     * @return array{offered: bool, fee: bool}
     */
    private function cod(int $shopId, string $language, string $fallback): array
    {
        $cod = ['offered' => false, 'fee' => false];

        foreach ($this->payments->forShop($shopId, $language, $fallback) as $method) {
            if (CashOnDeliveryHandler::getType() === $method->type) {
                $cod['offered'] = true;
                $cod['fee'] = $cod['fee'] || null !== CashOnDeliveryHandler::fee($method->config);
            }
        }

        return $cod;
    }
}
