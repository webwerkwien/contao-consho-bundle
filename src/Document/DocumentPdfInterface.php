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
}
