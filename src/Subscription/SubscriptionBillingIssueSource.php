<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A shop that sells subscriptions and has no method to bill them with (Abo-4,
 * P24): its follow-up orders are not created, and nothing but a log line
 * would say so — the customer keeps using what nobody bills.
 *
 * Asked where it matters: a shop with running subscriptions — resting ones
 * too, they are billed on (Abo-5, P36) —, or one that
 * sells a subscription product — published main products with `saleSubscription`
 * assigned to the shop. A main product without an assignment is sold nowhere
 * (VisibilityCriteria::assignedToShop; "empty = like the main product" is the
 * variants' rule). Until the acceptance of Abo-5 this read "every shop when the
 * product names none" and claimed that was the catalogue's reading — it was
 * not, it was written without looking (Prüfungsnotiz, Fall 29 pattern).
 */
final class SubscriptionBillingIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionBillingMethod $billing,
    ) {
    }

    public function issues(): array
    {
        $shops = $this->connection->fetchAllKeyValue(
            "SELECT s.id, s.title FROM tl_consho_shop s
              WHERE EXISTS (SELECT 1 FROM tl_consho_subscription a WHERE a.shop = s.id AND a.state IN ('active', 'suspended', 'paused'))
                 OR EXISTS (
                    SELECT 1 FROM tl_consho_product p
                     WHERE p.pid = 0 AND p.published = 1 AND p.saleSubscription = 1
                       AND EXISTS (SELECT 1 FROM tl_consho_product_shop ps WHERE ps.pid = p.id AND ps.shop = s.id))
              ORDER BY s.id",
        );

        $issues = [];

        foreach ($shops as $id => $title) {
            if (null === $this->billing->forShop((int) $id)) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::SubscriptionBillingMethodMissing, ['shop' => (string) $title]);
            }
        }

        return $issues;
    }
}
