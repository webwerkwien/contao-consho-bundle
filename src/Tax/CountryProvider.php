<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

use Contao\CoreBundle\Intl\Countries;

/**
 * Thin wrapper around Contao's country list.
 *
 * Consho ships no country list of its own — Contao has one, translated and
 * restrictable via `contao.intl.countries`. This class exists for one reason
 * beyond delegation: `normalise()`. Country codes must be ISO 3166-1 alpha-2
 * in upper case everywhere — in the tax rate, in the frozen order address, in
 * the shop's whitelist. Let `AT`, `at` and "Österreich" sit side by side and
 * tax resolution finds nothing, which surfaces at the first order from abroad
 * rather than at the desk of whoever typed it.
 */
final class CountryProvider
{
    public function __construct(private readonly Countries $countries)
    {
    }

    /**
     * @return array<string, string> ISO code => translated label
     */
    public function all(string $locale): array
    {
        return $this->countries->getCountries($locale);
    }

    public function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function isValid(string $code): bool
    {
        $code = $this->normalise($code);

        if ('' === $code) {
            return false;
        }

        return \in_array($code, $this->countries->getCountryCodes(), true);
    }
}
