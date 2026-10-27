<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;

/**
 * Offerable, selected flat-rate shipping methods that have no saved amount in
 * one of the currencies the shop accepts (spec 6.1, 11.3). The checkout does
 * not offer them — without this the operator sees a method missing at checkout
 * and no reason why.
 *
 * ⚠️ One finding PER CURRENCY, since C-1c: a shop takes as many as its start
 * pages have picked (spec 4.0), and a method priced in euro alone is simply
 * absent from the franc start pages. The message names the currency, because
 * "no amount saved" would read as a lie next to a mask showing one.
 *
 * Restricted to the flat rate and, since E-4, the weight brackets
 * (WeightRateShipping): the types this bundle ships whose availability depends
 * on saved amount rows at all — a carrier module can calculate its price without one (a live rate
 * lookup, a fixed table baked into its own config). A later type that also
 * needs a stored amount to be offerable has to declare itself here too (open
 * point, first carrier module / C-6).
 *
 * Runs on every call of the start page: three queries, whatever the number of
 * shops. The selection sits in a blob, so it is resolved in PHP.
 */
final class DbalShippingPriceIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ShopCurrencies $currencies,
    ) {
    }

    public function issues(): array
    {
        $selections = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, shippingMethods FROM tl_consho_shop WHERE shippingMethods IS NOT NULL ORDER BY title, id') as $shop) {
            $ids = MethodSettings::selectedIds($shop['shippingMethods']);

            if ([] === $ids) {
                continue;
            }

            // One round per currency the shop can accept, not one for "the"
            // shop currency: since C-1c a shop takes several (spec 4.0), and a
            // flat rate priced in euro but not in francs is not offered on the
            // franc start pages. Asking only the first would have been the same
            // mistake the completeness source carried until C-1c task 4 —
            // checking one market and staying silent about the other.
            //
            // A shop that accepts nothing yields no round and therefore no
            // finding. That is the answer, not a gap: the cause is then
            // CurrencyMissing or RootWithoutCurrency, and this source would
            // otherwise report every flat rate with an empty currency in the
            // sentence.
            foreach ($this->currencies->of((int) $shop['id']) as $currency) {
                $selections[] = ['id' => (int) $shop['id'], 'title' => (string) $shop['title'], 'currency' => $currency, 'ids' => $ids];
            }
        }

        if ([] === $selections) {
            return [];
        }

        $ids = array_values(array_unique(array_merge(...array_column($selections, 'ids'))));

        $titles = $this->connection->fetchAllKeyValue(
            'SELECT id, title FROM tl_consho_shipping_method WHERE '.DbalShippingMethodRepository::offerable().' AND type IN (?) AND id IN (?)',
            [[FlatRateShipping::getType(), WeightRateShipping::getType()], $ids],
            [ArrayParameterType::STRING, ArrayParameterType::INTEGER],
        );

        // tstamp > 0: a row opened in the mask and never saved is no amount.
        $priced = [];

        foreach ($this->connection->fetchAllAssociative('SELECT DISTINCT pid, currency FROM tl_consho_shipping_price WHERE tstamp > 0 AND pid IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $row) {
            // Upper-cased, like the shop side — ShopCurrencies does that one
            // through CurrencySelection, this one has no such reader. Both can
            // hold a lower-case code out of a migration or an import.
            $priced[(int) $row['pid'].'|'.strtoupper((string) $row['currency'])] = true;
        }

        $issues = [];

        foreach ($selections as $shop) {
            foreach ($shop['ids'] as $id) {
                // No strtoupper() on this side: ShopCurrencies hands out what
                // CurrencySelection accepted, which is upper case by
                // construction (ShopCurrenciesTest pins it). A second one here
                // would be a line no test could ever turn red.
                if (!isset($titles[$id]) || isset($priced[$id.'|'.$shop['currency']])) {
                    continue;
                }

                // With the shop (block I): without it the finding stood under
                // every shop on the setup page — one shop lacked a franc
                // price, and another showed it.
                $issues[] = new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, [
                    'method' => (string) $titles[$id],
                    'shop' => $shop['title'],
                    'currency' => $shop['currency'],
                ], $shop['id']);
            }
        }

        return $issues;
    }
}
