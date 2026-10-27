<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A shop with guest checkout whose published start points name no usable
 * "Lost password" page (K1-2, AccountPasswordPage) — one note per shop,
 * naming the start points.
 *
 * Without that page the order page offers no "Kundenkonto anlegen": the
 * account's password is set there. Only where there is an order page at
 * all — a start point without one has its own finding.
 */
final class AccountPasswordPageIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AccountPasswordPage $passwordPages,
    ) {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT root.id, root.title, s.id AS shop, s.title AS shopTitle
               FROM tl_page root
               JOIN tl_consho_shop s ON s.id = root.consho_shop
              WHERE root.type IN ('root', 'rootfallback')
                AND root.published = 1
                AND root.consho_orderPage > 0
                AND s.guestCheckout = 1
              ORDER BY s.id, root.id",
        );

        $byShop = [];

        foreach ($rows as $row) {
            // The same test as the offer on the order page.
            if (null !== $this->passwordPages->of((int) $row['id'])) {
                continue;
            }

            $byShop[(int) $row['shop']]['title'] = (string) $row['shopTitle'];
            $byShop[(int) $row['shop']]['roots'][] = (string) $row['title'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $shop) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::AccountPasswordPageMissing,
                ['shop' => $shop['title'], 'roots' => implode(', ', $shop['roots'])],
                $shopId,
            );
        }

        return $issues;
    }
}
