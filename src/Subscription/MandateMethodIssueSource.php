<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Running subscriptions whose mandate charges through a payment method the
 * shop no longer offers (Abo-4b, P99): deleted, unpublished, taken off the
 * shop, or its module gone. Their follow-up orders go "on account" — the
 * customer who chose to be charged now gets invoices, and nobody notices.
 *
 * The same question SubscriptionBillingMethod::charging() asks at billing,
 * so the finding disappears exactly when charging works again.
 */
final class MandateMethodIssueSource implements ConfigurationIssueSource
{
    /** Numbers named in the finding; the count says how many more. */
    private const NAMED = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionBillingMethod $billing,
    ) {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.shop, s.title, a.subscriptionNumber, a.mandateMethod FROM tl_consho_subscription a
               JOIN tl_consho_shop s ON s.id = a.shop
              WHERE a.state IN ('active', 'suspended', 'paused') AND a.mandate <> '' AND a.mandateMethod > 0
              ORDER BY a.shop, a.subscriptionNumber",
        );

        $byShop = [];
        $titles = [];
        $charges = [];

        foreach ($rows as $row) {
            $shopId = (int) $row['shop'];
            $key = $shopId.'/'.(int) $row['mandateMethod'];
            $charges[$key] ??= $this->billing->canCharge($shopId, (int) $row['mandateMethod']);

            if (!$charges[$key]) {
                $byShop[$shopId][] = (string) $row['subscriptionNumber'];
                $titles[$shopId] = (string) $row['title'];
            }
        }

        $issues = [];

        foreach ($byShop as $shopId => $numbers) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::MandateMethodUnavailable, [
                'shop' => $titles[$shopId],
                'count' => (string) \count($numbers),
                'numbers' => implode(', ', \array_slice($numbers, 0, self::NAMED)).(\count($numbers) > self::NAMED ? ', …' : ''),
            ], $shopId);
        }

        return $issues;
    }
}
