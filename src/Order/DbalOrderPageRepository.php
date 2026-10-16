<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;

/**
 * What the order page (7.7, C-10) reads — and the one thing it writes.
 */
class DbalOrderPageRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, string>|null
     */
    public function order(int $orderId): array|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]);

        return false === $row ? null : array_map(static fn ($v): string => (string) $v, $row);
    }

    // Payment and delivery state come from OrderBalanceReader since D-1, the
    // same reader the order list asks.

    /**
     * Invoices, cancellations and — since D-3 — invoice corrections
     * (`creditNote`), oldest first: what the customer may retrieve. The
     * correction went out by mail with the refund; the page must not show
     * less than the mailbox. `cancelled` marks an invoice a cancellation
     * refers to.
     *
     * @return list<array{id: int, type: string, number: string, issuedAt: int, cancelled: bool}>
     */
    public function documents(int $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT d.id, d.type, d.documentNumber, d.issuedAt,
                    EXISTS (SELECT 1 FROM tl_consho_document c WHERE c.type = 'cancellation' AND c.relatedDocument = d.id) AS cancelled
               FROM tl_consho_document d
              WHERE d.pid = ? AND d.type IN ('invoice', 'cancellation', 'creditNote')
              ORDER BY d.issuedAt, d.id",
            [$orderId],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'number' => (string) $row['documentNumber'],
                'issuedAt' => (int) $row['issuedAt'],
                'cancelled' => (bool) $row['cancelled'],
            ],
            $rows,
        );
    }

    /**
     * The payments as they stand (acceptance WG-7): what the customer paid,
     * how, and what went back — the invoice states it as it was when issued,
     * the page as it is now. `method` as the payment row names it; `refund`
     * is the one name Consho writes itself.
     *
     * @return list<array{method: string, amount: string}>
     */
    public function payments(int $orderId): array
    {
        return array_map(
            static fn (array $row): array => ['method' => (string) $row['method'], 'amount' => (string) $row['amount']],
            $this->connection->fetchAllAssociative('SELECT method, amount FROM tl_consho_payment WHERE pid = ? ORDER BY receivedAt, id', [$orderId]),
        );
    }

    /**
     * The first action on the order page confirms the address (C21).
     *
     * `IS NULL`, not `= 0`: a comparison against NULL is never true, and with
     * `= 0` every later action would move the moment forward — the same trap
     * DbalTokenRepository::markUsed() names.
     */
    public function confirmEmail(int $orderId, int $now): void
    {
        $this->connection->executeStatement(
            'UPDATE tl_consho_order SET emailConfirmedAt = ? WHERE id = ? AND emailConfirmedAt IS NULL',
            [$now, $orderId],
        );
    }
}
