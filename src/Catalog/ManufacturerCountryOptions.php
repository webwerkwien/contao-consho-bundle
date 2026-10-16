<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Symfony\Component\Intl\Countries;

/**
 * Supplies the country list for a manufacturer's seat.
 *
 * ⚠️ Deliberately NOT the list behind the tax rates and the delivery whitelist.
 * That one comes from Contao's Intl\Countries and honours
 * `contao.intl.countries`, which an operator restricts to the countries they
 * serve. A manufacturer's seat is a different dimension: the shop may ship
 * within Austria only and still stock goods made in Switzerland or China.
 *
 * Sharing the list would make the third-country manufacturer unrecordable —
 * and that is precisely the case the GPSR exists for, because it is the one
 * that needs a responsible person inside the Union.
 */
final class ManufacturerCountryOptions
{
    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'fields.country.options')]
    public function __invoke(): array
    {
        return Countries::getNames($GLOBALS['TL_LANGUAGE'] ?? 'en');
    }
}
