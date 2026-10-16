<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Sale modes that cannot be bought (Abo-1, A3, A4).
 *
 * - SaleModePriceMissing: a mode on offer — the one-time sale or a rule — has
 *   no saved price row. The product cannot be bought that way, and nothing
 *   else says so: the catalogue simply leaves the model out. One finding per
 *   product, naming the modes, because the operator has to know WHICH price
 *   to enter.
 * - SubscriptionNotListed: sold only by rules limited to customer groups.
 *   Listings show plans open to everyone (Abo-2b, P91), so the product is in
 *   none — only on its page. Since variant A (2026-10-08, A3) a rule's
 *   renewal kind no longer matters here; until then "sameTerm" counted as
 *   closed and had a note of its own (SubscriptionConsumersExcluded, gone
 *   with the fixed business-only lock).
 *
 * Published, digital MAIN products only: variants take their main product's
 * modes (SaleModes) and, lacking prices of their own, its prices
 * (ArticlePriceResolver); a physical product is always a one-time sale and
 * has its own finding for a missing price (ProductPriceMissing).
 */
final class SaleModeIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SaleModes $saleModes,
        private readonly SubscriptionRules $rules,
    ) {
    }

    public function issues(): array
    {
        $products = $this->connection->fetchAllKeyValue(
            'SELECT p.id, p.title
               FROM tl_consho_product p
               JOIN tl_consho_product_type t ON t.id = p.productType AND t.kind <> \'physical\'
              WHERE p.pid = 0 AND p.published = 1
              ORDER BY p.id',
        );

        $issues = [];

        foreach ($products as $id => $title) {
            $settings = $this->saleModes->of((int) $id);
            $titles = $this->ruleTitles($settings->rules);

            // tstamp > 0: a price mask opened and never saved is no price
            // (DbalPriceRepository::findCandidates()).
            $priced = array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT DISTINCT subscriptionRule FROM tl_consho_price WHERE pid = ? AND tstamp > 0',
                [(int) $id],
            ));

            $missing = array_values(array_diff($settings->priceRules(), $priced));

            if ([] !== $missing) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::SaleModePriceMissing, [
                    'product' => (string) $title,
                    'modes' => implode(', ', array_map(
                        fn (int $rule): string => 0 === $rule ? $this->oneTimeSale() : '„'.($titles[$rule] ?? (string) $rule).'"',
                        $missing,
                    )),
                ]);
            }

            if (!$settings->once && $settings->subscription() && !$this->anyOpenToAll($settings->rules)) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::SubscriptionNotListed, ['product' => (string) $title]);
            }
        }

        return $issues;
    }

    /**
     * @param list<int> $rules
     *
     * @return array<int, string>
     */
    private function ruleTitles(array $rules): array
    {
        if ([] === $rules) {
            return [];
        }

        $titles = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_subscription_rule WHERE id IN (?)', [$rules], [ArrayParameterType::INTEGER]) as $id => $title) {
            $titles[(int) $id] = (string) $title;
        }

        return $titles;
    }

    /**
     * A plan any visitor could book — the only kind a listing shows
     * (ArticlePriceResolver::listPrice(), P91).
     *
     * @param list<int> $rules
     */
    private function anyOpenToAll(array $rules): bool
    {
        foreach ($this->rules->byIds($rules) as $rule) {
            if ($rule->isFor([])) {
                return true;
            }
        }

        return false;
    }

    private function oneTimeSale(): string
    {
        return '„'.(string) ($GLOBALS['TL_LANG']['tl_consho_price']['oneTimeSale'] ?? 'Einmalkauf').'"';
    }
}
