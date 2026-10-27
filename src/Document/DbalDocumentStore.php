<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Doctrine\DBAL\Connection;

/**
 * Reading a document for delivery and recording its archive (8.3).
 *
 * Kept apart from DocumentRepositoryInterface on purpose: that one is what
 * the issuer needs inside the purchase transaction, this is what happens
 * afterwards — and the three archive columns are, with what an issuing module
 * reports back (ExternalInvoicing, block R), the only thing ever written to a
 * document after it was inserted.
 */
class DbalDocumentStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The document row with the order's language as `orderLanguage` — the
     * fallback for snapshots of version 1 (InvoiceView).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $documentId): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT d.*, o.language AS orderLanguage
               FROM tl_consho_document d
               LEFT JOIN tl_consho_order o ON o.id = d.pid
              WHERE d.id = ?',
            [$documentId],
        );

        return false === $row ? null : $row;
    }

    /**
     * The archive folder of a shop as a path under the project (files/…), ''
     * when the shop archives but no folder resolves, or null when it does
     * not archive at all.
     */
    public function archiveFolderOf(int $shopId): string|null
    {
        $shop = $this->connection->fetchAssociative('SELECT invoiceArchive, invoiceArchiveFolder FROM tl_consho_shop WHERE id = ?', [$shopId]);

        if (false === $shop || !$shop['invoiceArchive']) {
            return null;
        }

        if (!\is_string($shop['invoiceArchiveFolder']) || '' === $shop['invoiceArchiveFolder']) {
            return '';
        }

        $path = $this->connection->fetchOne("SELECT path FROM tl_files WHERE uuid = ? AND type = 'folder'", [$shop['invoiceArchiveFolder']]);

        return \is_string($path) ? $path : '';
    }

    public function markArchived(int $documentId, string $path, string $hash): void
    {
        $this->connection->update(
            'tl_consho_document',
            ['archiveState' => 'stored', 'archivedPath' => $path, 'archivedHash' => $hash],
            ['id' => $documentId],
        );
    }

    public function markArchiveFailed(int $documentId): void
    {
        $this->connection->update('tl_consho_document', ['archiveState' => 'failed'], ['id' => $documentId]);
    }

    /**
     * @return list<int>
     */
    public function idsWithArchive(bool $failedOnly): array
    {
        return array_map(
            intval(...),
            $this->connection->fetchFirstColumn(
                $failedOnly
                    ? "SELECT id FROM tl_consho_document WHERE archiveState = 'failed' ORDER BY id"
                    : "SELECT id FROM tl_consho_document WHERE archiveState IN ('stored', 'failed') ORDER BY id",
            ),
        );
    }
}
