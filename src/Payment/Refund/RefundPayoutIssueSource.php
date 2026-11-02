<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Refunds through a provider that need the operator (block Zahlung Mollie,
 * 4.5), per shop:
 *
 * - **failed**: the provider refused, or no module could be asked;
 * - **stuck**: `sending` for longer than a request takes — whether the
 *   provider got it is unknown, so nothing sends it again by itself —,
 *   `pending` at the provider for a week, or booked and still unsent a day
 *   later, when the cron should have sent it.
 *
 * The order numbers and the count, no amounts: the start page is seen by
 * more people than the order list.
 */
final class RefundPayoutIssueSource implements ConfigurationIssueSource
{
    /**
     * A request to a provider takes seconds; a row `sending` after this did
     * not get its answer stored.
     */
    public const SENDING_STUCK_AFTER = 3600;

    /**
     * A card refund settles within days, a bank transfer back within two;
     * a week "pending" is worth a look in the provider's account.
     */
    public const PENDING_STUCK_AFTER = 7 * 86400;

    /**
     * A booked row the request never sent is the hourly cron's to send; one
     * still unsent after a day says the cron does not run.
     */
    public const UNSENT_STUCK_AFTER = 86400;

    public function __construct(
        private readonly Connection $connection,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function issues(): array
    {
        $now = null === $this->clock ? time() : (int) ($this->clock)();

        return [
            ...$this->grouped(ConfigurationFinding::RefundPayoutFailed, "r.payoutState = 'failed'", []),
            ...$this->grouped(
                ConfigurationFinding::RefundPayoutStuck,
                "(r.payoutState = 'sending' AND r.tstamp < ?)
                 OR (r.payoutState = 'requested' AND r.payoutReference <> '' AND r.receivedAt < ?)
                 OR (r.payoutState = 'requested' AND r.payoutReference = '' AND r.tstamp < ?)",
                [$now - self::SENDING_STUCK_AFTER, $now - self::PENDING_STUCK_AFTER, $now - self::UNSENT_STUCK_AFTER],
            ),
        ];
    }

    /**
     * @param list<int> $params
     *
     * @return list<ConfigurationIssue>
     */
    private function grouped(ConfigurationFinding $finding, string $where, array $params): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT o.shop, s.id AS shopExists, o.orderNumber
               FROM tl_consho_payment r
               JOIN tl_consho_order o ON o.id = r.pid
               LEFT JOIN tl_consho_shop s ON s.id = o.shop
              WHERE r.method = 'refund' AND ($where)
              ORDER BY o.shop, r.id",
            $params,
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']][] = $row;
        }

        $issues = [];

        foreach ($byShop as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(
                $finding,
                [
                    'count' => (string) \count($entries),
                    'numbers' => implode(', ', array_values(array_unique(array_map(static fn (array $e): string => (string) $e['orderNumber'], $entries)))),
                ],
                // A deleted shop keeps its orders, and the money is still
                // owed: a general finding then (as at open withdrawals).
                null !== $entries[0]['shopExists'] ? $shopId : 0,
            );
        }

        return $issues;
    }
}
