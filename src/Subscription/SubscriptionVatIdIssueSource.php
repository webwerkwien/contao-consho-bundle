<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Running subscriptions bought with reverse charge whose last follow-up order
 * found the VAT ID invalid at VIES (SubscriptionBiller). That order was taxed;
 * whether the customer has a new number, or is no business any more, is the
 * operator's to clarify. The next period asks VIES again — the finding goes
 * once an order finds the number valid.
 *
 * Per shop, with the subscription numbers.
 */
final class SubscriptionVatIdIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.shop, sh.title, s.subscriptionNumber,
                    (SELECT o.vatIdStatus FROM tl_consho_order o
                      WHERE o.id = s.firstOrder OR o.subscription = s.id
                      ORDER BY o.createdAt DESC, o.id DESC LIMIT 1) AS lastStatus
               FROM tl_consho_subscription s
               JOIN tl_consho_shop sh ON sh.id = s.shop
              WHERE s.state IN ('active', 'suspended', 'paused') AND s.reverseCharge = 1
              ORDER BY s.shop, s.id",
        );

        $byShop = [];

        foreach ($rows as $row) {
            if (VatIdStatus::Invalid->value === (string) $row['lastStatus']) {
                $byShop[(int) $row['shop']][] = $row;
            }
        }

        $issues = [];

        foreach ($byShop as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::SubscriptionVatIdInvalid,
                [
                    'shop' => (string) $entries[0]['title'],
                    'numbers' => implode(', ', array_map(static fn (array $e): string => (string) $e['subscriptionNumber'], $entries)),
                ],
                $shopId,
            );
        }

        return $issues;
    }
}
