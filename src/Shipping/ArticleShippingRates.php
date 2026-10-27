<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Brick\Math\BigDecimal;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogContext;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Tax\TaxPlace;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * The cheapest way to send one piece of an article to a country (Connect-1,
 * K3) — for the JSON-LD of a product page and for a feed's shipping column;
 * for a feed of an article with a minimum purchase, that many pieces.
 *
 * Asked the way the checkout asks a basket holding that one piece
 * (CheckoutCalculator::shippingOption()): the shop's methods in this
 * currency, the core's rules first (countries, bulky goods), then the type's
 * own isAvailable() and calculate(). A type a module calculates in code takes
 * part like any other — it answers for this basket as it would at checkout.
 *
 * The gross is the checkout's too: at the rate of the country the goods are
 * taxed in (TaxPlace), with the method's class — or, where the method splits
 * its tax over the goods, with the article's: a basket of one article has
 * one share, and ShippingTaxSplitter gives it all to that class.
 *
 * Reverse charge is no question here: it needs a VAT ID, and a statement to
 * the public names the price a consumer pays.
 *
 * Not final: the product page's test doubles it, as it does ComparePrices.
 */
class ArticleShippingRates
{
    public function __construct(
        private readonly ShippingMethodRepositoryInterface $methods,
        private readonly ShippingMethodTypes $types,
        private readonly ArticleShippingDataInterface $articles,
        private readonly ArticleTaxClassesInterface $taxClasses,
        private readonly GrossCalculator $gross,
    ) {
    }

    /**
     * @param string $net        the net of the whole line (ShippingLine) — the price at quantity one for one piece; a type may depend on the basket's value
     * @param int    $minimumAge the article's (ProductDetail::$minimumAge); a method that cannot check it may refuse
     * @param string $quantity   pieces in the basket — more than one for an article with a minimum purchase (Connect-1), so weight and value are the basket's
     */
    public function cheapest(CatalogContext $context, string $country, int $articleId, string $net, int $minimumAge = 0, string $quantity = '1'): ShippingRate|null
    {
        $country = strtoupper(trim($country));
        $data = $this->articles->of([$articleId])[$articleId] ?? null;
        $articleTaxClass = $this->taxClasses->taxClassOf($articleId);

        $line = new ShippingLine(
            $articleId,
            $quantity,
            $net,
            $articleTaxClass,
            $data['weight'] ?? '0.0000',
            $data['length'] ?? '0.0000',
            $data['width'] ?? '0.0000',
            $data['height'] ?? '0.0000',
        );

        $shippingContext = new ShippingContext([$line], $country, $context->shopId, $context->currency, $context->groups, $minimumAge);
        $taxCountry = TaxPlace::forDistanceSale($country, $context->shopCountry, $context->taxAtDestination);
        $best = null;

        foreach ($this->methods->forShop($context->shopId, $context->currency, $context->language, $context->fallback) as $method) {
            if (!$this->types->has($method->type) || !$method->carries($country, [$line])) {
                continue;
            }

            $type = $this->types->get($method->type);

            if (!$type->isAvailable($shippingContext, $method->config)) {
                continue;
            }

            $quote = $type->calculate($shippingContext, $method->config)->net;

            try {
                $gross = $this->gross->gross($quote, ShippingTaxMode::Proportional === $method->taxMode ? $articleTaxClass : $method->taxClassId, $taxCountry, $context->now);
            } catch (TaxRateNotFound) {
                // The checkout would find no rate either and refuse the basket.
                continue;
            }

            // The first on a tie: the shop's order, as the checkout's default.
            if (null === $best || BigDecimal::of($gross)->isLessThan($best->gross)) {
                $best = new ShippingRate($method->id, $method->title, $country, $quote, $gross);
            }
        }

        return $best;
    }
}
