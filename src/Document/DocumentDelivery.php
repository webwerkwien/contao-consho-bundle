<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hands out an invoice (8.3): the archived file where there is one and it is
 * intact, otherwise the PDF regenerated from the snapshot.
 *
 * ⚠️ Regenerated where an archive was expected means MARKED, never silently
 * replaced: the PDF says "regenerated" on its face, and the caller gets
 * `archiveProblem` to warn the operator. An archive that has quietly stopped
 * working is exactly what an operator who chose it cannot afford not to know.
 *
 * A shop that never archived gets the regenerated PDF unmarked — that is the
 * normal way (C8), not a fallback.
 */
final class DocumentDelivery implements DocumentDeliveryInterface
{
    public function __construct(
        private readonly DbalDocumentStore $store,
        private readonly InvoiceRenderer $renderer,
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
    ) {
    }

    /**
     * @return array{pdf: string, fileName: string, fromArchive: bool, archiveProblem: bool, logoMissing: bool}|null
     */
    public function pdf(int $documentId): array|null
    {
        $document = $this->store->find($documentId);

        if (null === $document) {
            return null;
        }

        $fileName = DocumentArchiver::fileNameOf((string) $document['documentNumber']);
        $state = (string) ($document['archiveState'] ?? '');

        if ('stored' === $state) {
            $archived = $this->readArchived((string) $document['archivedPath'], (string) $document['archivedHash']);

            if (null !== $archived) {
                return ['pdf' => $archived, 'fileName' => $fileName, 'fromArchive' => true, 'archiveProblem' => false, 'logoMissing' => false];
            }
        }

        // `removed`: the file went with the anonymisation after the retention
        // period (D-5, OrderAnonymizer) — on purpose, not a problem (D-5).
        $problem = '' !== $state && 'removed' !== $state;
        $rendered = $this->renderer->pdf($document, $problem);

        return ['pdf' => $rendered['pdf'], 'fileName' => $fileName, 'fromArchive' => false, 'archiveProblem' => $problem, 'logoMissing' => $rendered['logoMissing']];
    }

    /**
     * The accessible HTML version (4.3). Always rendered from the snapshot —
     * there is no archived HTML, and the figures are the same either way.
     *
     * @return array{html: string, logoMissing: bool}|null
     */
    public function html(int $documentId): array|null
    {
        $document = $this->store->find($documentId);

        return null === $document ? null : $this->renderer->html($document);
    }

    /**
     * `ok`, `missing` (the file is gone), `changed` (it no longer matches its
     * hash), `failed` (it was never written) — or '' for a document without
     * archive. For consho:document:verify.
     */
    public function archiveStatusOf(int $documentId): string
    {
        $document = $this->store->find($documentId);
        $state = (string) ($document['archiveState'] ?? '');

        if ('stored' !== $state) {
            return $state;
        }

        try {
            $contents = $this->files->read((string) $document['archivedPath']);
        } catch (VirtualFilesystemException) {
            return 'missing';
        }

        return hash_equals((string) $document['archivedHash'], hash('sha256', $contents)) ? 'ok' : 'changed';
    }

    /**
     * The archived bytes, or null when the file is gone or no longer the one
     * written at issue.
     */
    private function readArchived(string $path, string $hash): string|null
    {
        if ('' === $path || '' === $hash) {
            return null;
        }

        try {
            $contents = $this->files->read($path);
        } catch (VirtualFilesystemException) {
            return null;
        }

        return hash_equals($hash, hash('sha256', $contents)) ? $contents : null;
    }
}
