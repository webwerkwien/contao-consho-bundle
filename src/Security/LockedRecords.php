<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

use Doctrine\DBAL\Connection;

/**
 * Whether a payment or delivery had consequences and is therefore locked
 * (D-1, A2) — asked by the voter, the mask and the payment's operations alike.
 *
 * A row is locked once `lockedAt` is set. A payment is also locked while any
 * row takes money back from it (`refundOf`): a refund may be booked against a
 * payment the order has not settled yet, and deleting or changing that
 * payment would leave a refund of nothing — "Bezahlt: −10,00".
 */
final class LockedRecords
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function isLocked(string $table, int $id): bool
    {
        if ($id < 1) {
            return false;
        }

        $locked = $this->connection->fetchOne(\sprintf('SELECT lockedAt FROM %s WHERE id = ?', $table), [$id]);

        if (null !== $locked && false !== $locked) {
            return true;
        }

        return 'tl_consho_payment' === $table && $this->hasRefunds($id);
    }

    /**
     * @param array<string, mixed> $row a row of tl_consho_payment
     */
    public function isPaymentLocked(array $row): bool
    {
        return null !== ($row['lockedAt'] ?? null) || $this->hasRefunds((int) ($row['id'] ?? 0));
    }

    private function hasRefunds(int $paymentId): bool
    {
        return $paymentId > 0 && false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_payment WHERE refundOf = ? LIMIT 1', [$paymentId]);
    }
}
