<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Subscriptions resting for an unpaid invoice (Abo-5, P38), per shop.
 *
 * A note, like InvoiceOverdue: following it up is the operator's business,
 * nothing is misconfigured, and Consho neither dunns nor cancels (A6). The
 * state is SubscriptionDelinquency's; this only reads it — no balance here,
 * the hourly run has done that.
 */
final class SuspendedSubscriptionIssueSource implements ConfigurationIssueSource
{
    /** Numbers named in the finding; the count says how many more. */
    private const NAMED = 5;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.shop, s.title, a.subscriptionNumber FROM tl_consho_subscription a
               JOIN tl_consho_shop s ON s.id = a.shop
              WHERE a.state = 'suspended'
              ORDER BY a.shop, a.subscriptionNumber",
        );

        $byShop = [];
        $titles = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']][] = (string) $row['subscriptionNumber'];
            $titles[(int) $row['shop']] = (string) $row['title'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $numbers) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::SubscriptionsSuspended, [
                'shop' => $titles[$shopId],
                'count' => (string) \count($numbers),
                'numbers' => implode(', ', \array_slice($numbers, 0, self::NAMED)).(\count($numbers) > self::NAMED ? ', …' : ''),
            ], $shopId);
        }

        return $issues;
    }
}
