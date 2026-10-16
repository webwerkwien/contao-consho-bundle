<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * Reads what an article is sold as (Abo-1, A4, plan P2).
 *
 * - From the MAIN product: sale modes and rules are hidden at
 *   variants (SaleModeListener), and a variant's price row picks from its main
 *   product's rules. A rule per variant would be a second product.
 * - A physical article is always a one-time sale, whatever its ticks say:
 *   the ticks sit in the digital legend, and an unticked "Einmalkauf" on a
 *   parcel would otherwise make it unsellable. Digital and service articles
 *   may be subscriptions (DL1); the kind by the rule of DbalArticleShippingData — the main product's type first, no type
 *   at all counts as physical.
 * - Only rules that still exist, in the order chosen: a deleted rule drops
 *   out here rather than being guarded (SubscriptionRuleDeleteVoter).
 */
class SaleModes
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function of(int $articleId): SaleModeSettings
    {
        $row = $this->connection->fetchAssociative(
            'SELECT m.saleOnce, m.saleSubscription, m.subscriptionRules,
                    COALESCE(tm.kind, ta.kind, \'physical\') AS kind
               FROM tl_consho_product a
               JOIN tl_consho_product m ON m.id = CASE WHEN a.pid > 0 THEN a.pid ELSE a.id END
               LEFT JOIN tl_consho_product_type tm ON tm.id = m.productType AND a.pid > 0
               LEFT JOIN tl_consho_product_type ta ON ta.id = a.productType
              WHERE a.id = ?',
            [$articleId],
        );

        $kind = ProductKind::stored(false === $row ? null : (string) $row['kind']);

        if (false === $row || !$kind->allowsSubscription()) {
            return new SaleModeSettings(true, []);
        }

        return new SaleModeSettings(
            (bool) $row['saleOnce'],
            (bool) $row['saleSubscription'] ? $this->existing($row['subscriptionRules']) : [],
        );
    }

    /**
     * @return list<int>
     */
    private function existing(mixed $selection): array
    {
        $chosen = array_values(array_filter(
            array_map('intval', StringUtil::deserialize($selection, true)),
            static fn (int $id): bool => $id > 0,
        ));

        if ([] === $chosen) {
            return [];
        }

        $existing = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_subscription_rule WHERE id IN (?)',
            [$chosen],
            [ArrayParameterType::INTEGER],
        ));

        return array_values(array_unique(array_filter($chosen, static fn (int $id): bool => \in_array($id, $existing, true))));
    }
}
