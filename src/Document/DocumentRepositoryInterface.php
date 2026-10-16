<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

interface DocumentRepositoryInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return int the new document id
     */
    public function insert(
        int $orderId,
        int $shopId,
        DocumentType $type,
        string $documentNumber,
        int $issuedAt,
        int $relatedDocumentId,
        array $payload,
    ): int;

    /**
     * The document of this type that STANDS — one a cancellation refers to
     * does not (C-10).
     */
    public function findIdByOrderAndType(int $orderId, DocumentType $type): int|null;

    /**
     * @return array<string, string>|null the tl_consho_document row, payload as stored
     */
    public function findDocumentRow(int $documentId): array|null;

    public function findCancellationOf(int $documentId): int|null;

    /**
     * @return array<string, string>
     */
    public function findOrderRow(int $orderId): array;

    /**
     * @return list<array<string, string>>
     */
    public function findOrderItemRows(int $orderId): array;

    /**
     * The end of the payment term an invoice states (E-2, Z5), 0 for none.
     * Kept on the order so the overdue note can ask SQL, not a JSON payload.
     */
    public function recordPaymentDue(int $orderId, int $dueAt): void;
}
