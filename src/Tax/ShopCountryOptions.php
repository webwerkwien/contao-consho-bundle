<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;

/**
 * Supplies the country list to DCA select fields.
 *
 * Both the tax rate and the shop's delivery whitelist draw from the same
 * source, so a rate can only ever be created for a country the shop could
 * actually select — and a shop restricted through `contao.intl.countries`
 * cannot be given a rate for a country it does not serve.
 */
final class ShopCountryOptions
{
    public function __construct(private readonly CountryProvider $countries)
    {
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_tax_rate', target: 'fields.country.options')]
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.country.options')]
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.deliveryCountries.options')]
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.invoiceCountry.options')]
    #[AsCallback(table: 'tl_member', target: 'fields.consho_shippingCountry.options')]
    #[AsCallback(table: 'tl_consho_shipping_method', target: 'fields.countries.options')]
    public function __invoke(): array
    {
        return $this->countries->all($GLOBALS['TL_LANGUAGE'] ?? 'en');
    }
}
