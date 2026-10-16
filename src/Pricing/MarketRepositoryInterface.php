<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

interface MarketRepositoryInterface
{
    /**
     * Every market that is actually sold in, sorted by currency and country.
     *
     * A market pairs a currency with a home country, and since C-1c the two
     * come from different places: the currency from a START PAGE, the home
     * country from the shop that start page points at (spec 4.0). One shop can
     * therefore contribute several markets — it sells over /en/ in pounds —
     * and contributes none while it has no home country or none of its start
     * pages has picked a currency.
     *
     * Whether a start page is published does not enter into it; MarketCriteria
     * says why.
     *
     * @return list<Market>
     */
    public function findMarkets(): array;
}
