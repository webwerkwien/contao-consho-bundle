<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;

final class DbalPaymentChangeRepository implements PaymentChangeRepositoryInterface
{
    private const PAYMENT_LINE_TYPES = [OrderLineType::PaymentFee->value, OrderLineType::PaymentDiscount->value];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function order(int $orderId): array|null
    {
        return $this->stringRow($this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]));
    }

    /**
     * FOR UPDATE written out, the way NumberRangeAllocator and the basket
     * write it (DBAL 4 has no platform method for it any more). Two changes
     * of the same order at once would otherwise both cancel the invoice that
     * stood and both issue a new one — the second cancellation would find
     * the first and stop, but the second invoice would stand beside the
     * first.
     *
     * SQLite has no FOR UPDATE; the service around it is tested against a
     * mocked repository for exactly that reason (OrderPlacerTest does the
     * same with the basket).
     */
    public function lockOrder(int $orderId): array|null
    {
        return $this->stringRow($this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ? FOR UPDATE', [$orderId]));
    }

    public function items(int $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_order_item WHERE pid = ? ORDER BY sorting, id', [$orderId]);

        return array_map(fn (array $row): array => $this->stringRow($row) ?? [], $rows);
    }

    public function paymentAmounts(int $orderId): array
    {
        return array_map(
            static fn ($amount): string => (string) $amount,
            $this->connection->fetchFirstColumn('SELECT amount FROM tl_consho_payment WHERE pid = ?', [$orderId]),
        );
    }

    public function statusHolds(int $statusId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_order_status WHERE id = ? AND (locksChanges = 1 OR isPaid = 1 OR isCancelled = 1 OR isCompleted = 1)',
            [$statusId],
        );
    }

    public function memberGroups(int $memberId): array
    {
        if ($memberId < 1) {
            return [];
        }

        // GROUPS is reserved in MySQL 8 — quoted, not trusted (the lesson of
        // `order`, spec open points).
        $groups = $this->connection->fetchOne(\sprintf('SELECT %s FROM tl_member WHERE id = ?', $this->connection->quoteIdentifier('groups')), [$memberId]);

        return array_values(array_filter(
            array_map('intval', StringUtil::deserialize(\is_string($groups) ? $groups : '', true)),
            static fn (int $id): bool => $id > 0,
        ));
    }

    public function openAttempts(int $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, pid, method, methodType, state, amount, currency, providerReference
               FROM tl_consho_payment_attempt WHERE pid = ? AND state IN (?)',
            [$orderId, [AttemptState::Open->value, AttemptState::Pending->value]],
            [1 => ArrayParameterType::STRING],
        );

        return array_map(
            static fn (array $row): PaymentAttempt => new PaymentAttempt(
                (int) $row['id'],
                (int) $row['pid'],
                (int) $row['method'],
                (string) $row['methodType'],
                AttemptState::tryFrom((string) $row['state']) ?? AttemptState::Open,
                (string) $row['amount'],
                (string) $row['currency'],
                (string) $row['providerReference'],
                '',
                '',
            ),
            $rows,
        );
    }

    public function cancelAttempt(int $attemptId, int $now): void
    {
        $this->connection->update(
            'tl_consho_payment_attempt',
            ['state' => AttemptState::Cancelled->value, 'updatedAt' => $now, 'tstamp' => $now],
            ['id' => $attemptId],
        );
    }

    public function replacePaymentLines(int $orderId, array $lines, int $now): void
    {
        $this->connection->executeStatement(
            'DELETE FROM tl_consho_order_item WHERE pid = ? AND lineType IN (?)',
            [$orderId, self::PAYMENT_LINE_TYPES],
            [1 => ArrayParameterType::STRING],
        );

        // After everything that stays, in the order the purchase wrote them:
        // goods, shipping, then payment.
        $sorting = (int) $this->connection->fetchOne('SELECT MAX(sorting) FROM tl_consho_order_item WHERE pid = ?', [$orderId]);

        foreach ($lines as $line) {
            $sorting += 128;
            $this->connection->insert('tl_consho_order_item', ['pid' => $orderId, 'sorting' => $sorting, 'tstamp' => $now] + $line);
        }
    }

    public function updateOrder(int $orderId, array $fields): void
    {
        $this->connection->update('tl_consho_order', $fields, ['id' => $orderId]);
    }

    public function hasReportedDelivery(int $orderId): bool
    {
        // reportedAt is NULL until reported (DeliveryReported), not 0.
        return false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_delivery WHERE pid = ? AND reportedAt IS NOT NULL', [$orderId]);
    }

    public function hasOneTimeEntitlements(int $orderId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_entitlement WHERE pid = ? AND subscription = 0 AND revoked = 0 LIMIT 1', [$orderId]);
    }

    public function belongsToSubscription(int $orderId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_order o WHERE o.id = ? AND (o.subscription > 0 OR EXISTS (SELECT 1 FROM tl_consho_subscription s WHERE s.firstOrder = o.id))',
            [$orderId],
        );
    }

    /**
     * @param array<string, mixed>|false $row
     *
     * @return array<string, string>|null
     */
    private function stringRow(array|false $row): array|null
    {
        return false === $row ? null : array_map(static fn ($v): string => (string) $v, $row);
    }
}
