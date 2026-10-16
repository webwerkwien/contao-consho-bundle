<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

/**
 * Resolves a tax rate from class, delivery country and date.
 *
 * The rate is copied onto the order item and frozen there. A later rate change
 * must never reach an existing invoice.
 */
final class TaxResolver
{
    public function __construct(
        private readonly TaxRateRepositoryInterface $repository,
        private readonly CountryProvider $countries,
    ) {
    }

    /**
     * @throws TaxRateNotFound
     */
    public function resolveRate(int $taxClassId, string $countryCode, int $timestamp): string
    {
        $country = $this->countries->normalise($countryCode);

        $rate = $this->repository->findRate($taxClassId, $country, $timestamp);

        if (null === $rate) {
            throw TaxRateNotFound::for($taxClassId, $country, $timestamp);
        }

        return $rate;
    }
}
