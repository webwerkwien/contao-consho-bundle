<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * Why PriceInput refused an amount. A case, not a text: the mask turns it into
 * its language string, a console command into its own answer.
 */
enum PriceInputProblem: string
{
    case InvalidAmount = 'invalidAmount';
    case Negative = 'negative';
    case CountryMissing = 'countryMissing';
    case TaxClassMissing = 'taxClassMissing';
    case TaxRateMissing = 'taxRateMissing';
    case TooLarge = 'tooLarge';
    case CurrencyWithoutShop = 'currencyWithoutShop';
}
