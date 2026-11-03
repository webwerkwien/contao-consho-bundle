<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Aftermath;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;

/**
 * What the provider reported after a payment and still needs the operator
 * (spec 9, P5), one finding per event — each is judged on its own, so a
 * second chargeback is not hidden behind the note taken of the first:
 *
 * - **charged back**: while the chargeback stands and the order still owes
 *   money. Paid again, or the chargeback undone, and it is gone by itself.
 * - **refund outside Consho**: until recorded or assigned (state `open`).
 * - **reversed and paid again**: the customer paid twice; while the order
 *   is overpaid.
 *
 * The order number and the provider's reference, no amounts: the start page
 * is seen by more people than the order list.
 */
final class ProviderEventIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.pid, e.kind, e.reference, e.reason, o.orderNumber, o.shop, s.id AS shopExists
               FROM tl_consho_provider_event e
               JOIN tl_consho_order o ON o.id = e.pid
               LEFT JOIN tl_consho_shop s ON s.id = o.shop
              WHERE e.state = 'open' AND (e.kind = 'refund' OR e.reversedAt = 0)
              ORDER BY e.id",
        );

        $issues = [];
        $owes = [];

        foreach ($rows as $row) {
            $orderId = (int) $row['pid'];
            // A deleted shop keeps its orders: a general finding then.
            $shop = null !== $row['shopExists'] ? (int) $row['shop'] : 0;

            if ('refund' === $row['kind']) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::RefundOutsideConsho, [
                    'number' => (string) $row['orderNumber'],
                    'reference' => (string) $row['reference'],
                ], $shop);

                continue;
            }

            $owes[$orderId] ??= $this->owes($orderId);

            if (!$owes[$orderId]) {
                continue;
            }

            $issues[] = new ConfigurationIssue(ConfigurationFinding::PaymentChargedBack, [
                'number' => (string) $row['orderNumber'],
                'reason' => '' !== (string) $row['reason'] ? (string) $row['reason'] : '—',
                // Not shown; tells two chargebacks of one order apart.
                'reference' => (string) $row['reference'],
            ], $shop);
        }

        return [...$issues, ...$this->paidTwice()];
    }

    /**
     * Paid again after the chargeback, and the bank reversed it: the customer
     * paid twice. While the order is overpaid. In either order: an attempt
     * opened while the chargeback stood may be paid after the reversal, and
     * then nothing marks the event `settled`.
     *
     * @return list<ConfigurationIssue>
     */
    private function paidTwice(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.pid, e.reference, o.orderNumber, o.shop, s.id AS shopExists
               FROM tl_consho_provider_event e
               JOIN tl_consho_order o ON o.id = e.pid
               LEFT JOIN tl_consho_shop s ON s.id = o.shop
              WHERE e.kind = 'chargeback' AND e.state IN ('open', 'settled') AND e.reversedAt > 0
              ORDER BY e.id",
        );

        $issues = [];

        foreach ($rows as $row) {
            $balance = $this->balances->of((int) $row['pid']);

            if (null === $balance || !BigDecimal::of($balance->outstanding())->isNegative()) {
                continue;
            }

            $issues[] = new ConfigurationIssue(ConfigurationFinding::ChargebackReversedAfterPayment, [
                'number' => (string) $row['orderNumber'],
                'reference' => (string) $row['reference'],
            ], null !== $row['shopExists'] ? (int) $row['shop'] : 0);
        }

        return $issues;
    }

    private function owes(int $orderId): bool
    {
        $balance = $this->balances->of($orderId);

        return null !== $balance && BigDecimal::of($balance->outstanding())->isPositive();
    }
}
