<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * An amount PriceInput will not store, with what a message needs to say why.
 */
final class PriceInputRefused extends \DomainException
{
    /**
     * @param list<string> $countries the home countries to choose from (CountryMissing)
     */
    public function __construct(
        public readonly PriceInputProblem $problem,
        public readonly string $currency = '',
        public readonly array $countries = [],
        public readonly string $country = '',
        public readonly int $timestamp = 0,
    ) {
        parent::__construct('Price input refused: '.$problem->value);
    }
}
