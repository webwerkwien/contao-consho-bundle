<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Keeps a copy of every invoice PDF in the shop's archive folder (C8, 8.3) —
 * the operator's option for a strict reading of "urschriftgetreu".
 *
 * ── After the purchase, never inside it ───────────────────────────────────
 *
 * The purchase transaction holds the lock on the number range until it
 * commits, and dompdf takes a noticeable moment (OrderPlacer). Archiving
 * therefore runs after the commit, and nothing it does can undo a purchase:
 * every failure is caught, recorded as `archiveState = failed`, logged and
 * reported (DbalInvoiceArchiveIssueSource). Retrieval then regenerates the
 * PDF and says so (DocumentDelivery), and `consho:document:verify --retry`
 * archives it later.
 *
 * ── Never into a public folder, never over an existing file ───────────────
 *
 * The folder is checked again here, not only when it is chosen
 * (ArchiveFolderListener): anyone may make it public in the file manager
 * afterwards, and an invoice written there is readable by everyone.
 *
 * An existing file is never overwritten. Two shops sharing a folder and a
 * number prefix would otherwise replace each other's invoices silently; a
 * failure is loud.
 *
 * Written through Contao's virtual file system, so the DBAFS (tl_files) knows
 * the file (8.3).
 */
final class DocumentArchiver implements DocumentArchiverInterface
{
    public function __construct(
        private readonly DbalDocumentStore $store,
        private readonly InvoiceRenderer $renderer,
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
        private readonly ArchiveFolderProtector $protector,
        #[Autowire('%contao.upload_path%')]
        private readonly string $uploadPath,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function archive(int $documentId): void
    {
        $document = $this->store->find($documentId);

        if (null === $document) {
            return;
        }

        $folder = $this->store->archiveFolderOf((int) $document['shop']);

        // The shop does not archive: the document is not a failed archive.
        if (null === $folder) {
            return;
        }

        try {
            if ('' === $folder) {
                throw new \RuntimeException('The shop archives invoices but has no archive folder.');
            }

            if (null !== ($public = $this->protector->publicFolderOf($folder))) {
                throw new \RuntimeException(\sprintf('The archive folder is public through "%s".', $public));
            }

            $path = Path::join(Path::makeRelative($folder, $this->uploadPath), self::fileNameOf((string) $document['documentNumber']));

            if ($this->files->fileExists($path)) {
                throw new \RuntimeException(\sprintf('"%s" already exists and is not overwritten.', $path));
            }

            $pdf = $this->renderer->pdf($document)['pdf'];

            $this->files->write($path, $pdf);
            $this->store->markArchived($documentId, $path, hash('sha256', $pdf));
        } catch (\Throwable $e) {
            $this->store->markArchiveFailed($documentId);
            $this->logger->error(\sprintf('Invoice %s could not be archived: %s', (string) $document['documentNumber'], $e->getMessage()), ['exception' => $e]);
        }
    }

    /**
     * The document number as a file name. Numbers come from the operator's
     * number range format, so anything outside a safe set is replaced — a
     * slash in a prefix must not become a subfolder.
     */
    public static function fileNameOf(string $documentNumber): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $documentNumber) ?? '';

        return ('' !== trim($name, '._') ? $name : 'document').'.pdf';
    }
}
