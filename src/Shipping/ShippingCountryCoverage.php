<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Which countries a shop's shipping methods reach (E-5, V3; spec A 707).
 *
 * The checkout offers only countries at least one offerable, selected
 * shipping method delivers to: a country no method serves could be chosen
 * and then end in "no shipping method" after the address is typed.
 *
 * ⚠️ Not silent — the lesson of DeliveryCountryProvider, which once narrowed
 * the list by tax rates and so disguised an operator's gap as a business
 * decision. Every delivery country of the shop that no method reaches is
 * named on the start page, as a warning: the operator ticked it, and the
 * checkout no longer shows it.
 */
final class ShippingCountryCoverage implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Upper-case ISO codes the shop's methods reach, or null for "no
     * restriction from shipping": a method without a country list serves
     * every country, and a shop without methods is another finding's matter.
     *
     * @return list<string>|null
     */
    public function servedBy(int $shopId): array|null
    {
        $ids = MethodSettings::selectedIds($this->connection->fetchOne('SELECT shippingMethods FROM tl_consho_shop WHERE id = ?', [$shopId]));

        if ([] === $ids) {
            return null;
        }

        $lists = $this->connection->fetchFirstColumn(
            'SELECT countries FROM tl_consho_shipping_method WHERE '.DbalShippingMethodRepository::offerable().' AND id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        if ([] === $lists) {
            return null;
        }

        $served = [];

        foreach ($lists as $blob) {
            $codes = self::codes($blob);

            if ([] === $codes) {
                return null;
            }

            $served = [...$served, ...$codes];
        }

        return array_values(array_unique($served));
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, deliveryCountries FROM tl_consho_shop ORDER BY id') as $shop) {
            $served = $this->servedBy((int) $shop['id']);

            if (null === $served) {
                continue;
            }

            $missing = array_values(array_diff(self::codes($shop['deliveryCountries'] ?? null), $served));

            if ([] !== $missing) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DeliveryCountryWithoutShipping, [
                    'shop' => (string) $shop['title'],
                    'countries' => implode(', ', $missing),
                ], (int) $shop['id']);
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private static function codes(mixed $blob): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $code): string => \is_string($code) ? strtoupper(trim($code)) : '',
            StringUtil::deserialize($blob, true),
        ))));
    }
}
