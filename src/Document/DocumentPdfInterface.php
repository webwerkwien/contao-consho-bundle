<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

/**
 * A document as the PDF a customer or operator receives — the archived file
 * where it is intact, otherwise rendered from the snapshot (DocumentDelivery).
 */
interface DocumentPdfInterface
{
    /**
     * @return array{pdf: string, fileName: string, fromArchive: bool, archiveProblem: bool, logoMissing: bool}|null
     */
    public function pdf(int $documentId): array|null;

    /**
     * Whether the customer gets this document from somewhere other than
     * Consho's mails (block R, R21) — the shop's invoicing module sends it,
     * or nobody does because invoicing is off. A mail then neither attaches
     * it nor says it is attached.
     */
    public function sentElsewhere(int $documentId): bool;
}
