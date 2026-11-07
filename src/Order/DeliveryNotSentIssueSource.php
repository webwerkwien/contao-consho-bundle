<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Reported deliveries whose mail to the customer did not leave, per shop —
 * as ConfirmationNotSent for the order confirmation. A state, not a log:
 * `notifiedAt` NULL after the report says it; 0 says nothing was recorded
 * (DeliveryNotifiedMigration, electronic releases).
 *
 * "Benachrichtigung erneut senden" at the delivery clears it. An anonymised
 * order is left out: nothing can be sent to it any more, so the warning
 * could never be cleared.
 */
final class DeliveryNotSentIssueSource implements ConfigurationIssueSource
{
    /** The count says how many; the numbers say where to start. */
    private const EXAMPLES = 5;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.shop, s.title, o.orderNumber
               FROM tl_consho_delivery d
               JOIN tl_consho_order o ON o.id = d.pid
          LEFT JOIN tl_consho_shop s ON s.id = o.shop
              WHERE d.reportedAt IS NOT NULL AND d.notifiedAt IS NULL AND o.anonymizedAt IS NULL
              ORDER BY o.shop, d.reportedAt, d.id',
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']]['title'] = (string) ($row['title'] ?? '');
            $byShop[(int) $row['shop']]['numbers'][] = (string) $row['orderNumber'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $shop) {
            $count = \count($shop['numbers']);

            $issues[] = new ConfigurationIssue(ConfigurationFinding::DeliveryNotSent, [
                'shop' => $shop['title'],
                'count' => (string) $count,
                'numbers' => implode(', ', \array_slice($shop['numbers'], 0, self::EXAMPLES)).($count > self::EXAMPLES ? ', …' : ''),
            ], $shopId);
        }

        return $issues;
    }
}
