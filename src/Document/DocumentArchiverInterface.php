<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

interface DocumentArchiverInterface
{
    /**
     * Stores the PDF of a document in its shop's archive folder, if the shop
     * keeps one. Never throws: a failed archive is recorded on the document
     * and reported as a finding, and the purchase that issued it stands.
     */
    public function archive(int $documentId): void;
}
