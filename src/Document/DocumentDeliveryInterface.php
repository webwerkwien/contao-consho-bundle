<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

/**
 * A document as PDF and as its accessible HTML version (8.3, 4.3) — what the
 * order page hands the customer (C-10).
 */
interface DocumentDeliveryInterface extends DocumentPdfInterface
{
    /**
     * @return array{html: string, logoMissing: bool}|null
     */
    public function html(int $documentId): array|null;
}
