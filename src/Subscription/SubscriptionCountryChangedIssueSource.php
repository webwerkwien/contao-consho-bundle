<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Running subscriptions whose customer names another country in the account
 * than on the subscription's last invoice (Abo-Nachlese; review S1, Michael,
 * 2026-10-01: „ja, S1 und S2 wie empfohlen"). The follow-up invoices keep the
 * previous address (SubscriptionBiller): the billing country is the tax
 * country of a digital subscription, and a move abroad touches tax, reverse
 * charge and the contract — the operator's to decide, not the cron's.
 *
 * Not for a service (DL4): it is taxed in the shop's country wherever the
 * customer lives, so the account's new address is simply taken.
 *
 * Per shop, with the subscription numbers. A country Consho does not take
 * over (empty, or not two letters like `GB-ENG`) is not a change.
 */
final class SubscriptionCountryChangedIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.shop, sh.title, s.subscriptionNumber, m.country AS accountCountry,
                    (SELECT o.billingCountry FROM tl_consho_order o
                      WHERE o.id = s.firstOrder OR o.subscription = s.id
                      ORDER BY o.createdAt DESC, o.id DESC LIMIT 1) AS orderCountry
               FROM tl_consho_subscription s
               JOIN tl_member m ON m.id = s.member
               JOIN tl_consho_shop sh ON sh.id = s.shop
              WHERE s.state IN ('active', 'suspended', 'paused') AND s.kind <> 'service'
              ORDER BY s.shop, s.id",
        );

        $byShop = [];

        foreach ($rows as $row) {
            $account = strtoupper(trim((string) $row['accountCountry']));

            if (2 !== \strlen($account) || $account === (string) $row['orderCountry']) {
                continue;
            }

            $byShop[(int) $row['shop']][] = $row;
        }

        $issues = [];

        foreach ($byShop as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::SubscriptionCountryChanged,
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
