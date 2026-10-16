<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Doctrine\DBAL\Connection;

final class DbalDocumentRepository implements DocumentRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function insert(
        int $orderId,
        int $shopId,
        DocumentType $type,
        string $documentNumber,
        int $issuedAt,
        int $relatedDocumentId,
        array $payload,
    ): int {
        $this->connection->insert('tl_consho_document', [
            'pid' => $orderId,
            'shop' => $shopId,
            'type' => $type->value,
            'documentNumber' => $documentNumber,
            'issuedAt' => $issuedAt,
            'relatedDocument' => $relatedDocumentId,

            /*
             * JSON_UNESCAPED_UNICODE so an address keeps its umlauts as
             * characters rather than ü escapes: the payload is read by
             * people during an audit, not only by machines.
             *
             * JSON_THROW_ON_ERROR because a silently truncated snapshot is
             * worse than no document at all — json_encode returns false on
             * invalid UTF-8, and `false` in a text column is an empty string.
             */
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'tstamp' => time(),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function recordPaymentDue(int $orderId, int $dueAt): void
    {
        $this->connection->update('tl_consho_order', ['paymentDueAt' => $dueAt], ['id' => $orderId]);
    }

    public function findIdByOrderAndType(int $orderId, DocumentType $type): int|null
    {
        /*
         * Not cancelled (C-10). Changing the payment method cancels the invoice
         * and issues a new one; found here, the cancelled one would make
         * issueOnce() hand it back and the new method would never get an
         * invoice of its own.
         */
        $id = $this->connection->fetchOne(
            "SELECT d.id FROM tl_consho_document d
              WHERE d.pid = :pid AND d.type = :type
                AND NOT EXISTS (SELECT 1 FROM tl_consho_document c WHERE c.type = 'cancellation' AND c.relatedDocument = d.id)
              ORDER BY d.id ASC LIMIT 1",
            ['pid' => $orderId, 'type' => $type->value],
        );

        return false === $id ? null : (int) $id;
    }

    public function findDocumentRow(int $documentId): array|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_document WHERE id = ?', [$documentId]);

        return false === $row ? null : array_map(static fn ($v): string => (string) $v, $row);
    }

    public function findCancellationOf(int $documentId): int|null
    {
        $id = $this->connection->fetchOne(
            "SELECT id FROM tl_consho_document WHERE type = 'cancellation' AND relatedDocument = ? ORDER BY id ASC LIMIT 1",
            [$documentId],
        );

        return false === $id ? null : (int) $id;
    }

    public function findOrderRow(int $orderId): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = :id', ['id' => $orderId]);

        return false === $row ? [] : array_map(static fn ($v): string => (string) $v, $row);
    }

    public function findOrderItemRows(int $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_consho_order_item WHERE pid = :pid ORDER BY sorting, id',
            ['pid' => $orderId],
        );

        return array_map(
            static fn (array $row): array => array_map(static fn ($v): string => (string) $v, $row),
            $rows,
        );
    }
}
