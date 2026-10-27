<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\Invoicing;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;

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
 *
 * By issuer (block R, R16, R18): a document an invoicing module issued is the
 * module's PDF, once the module has issued it; a row written while invoicing
 * was off holds the invoice data but is no invoice, and has no PDF at all.
 */
final class DocumentDelivery implements DocumentDeliveryInterface
{
    public function __construct(
        private readonly DbalDocumentStore $store,
        private readonly InvoiceRenderer $renderer,
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
        private readonly InvoicingPolicy|null $policy = null,
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

        $issuer = self::issuerOf($document);

        if (Invoicing::CONSHO !== $issuer) {
            return $this->modulePdf($document, $issuer);
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

        // Consho's layout of a module's document would be a second original that differs.
        return null === $document || Invoicing::CONSHO !== self::issuerOf($document) ? null : $this->renderer->html($document);
    }

    /**
     * Consho's documents go out with its mails unless a module that passes
     * them on sends them itself; a module's documents unless it sends them
     * itself; "off" sends nothing (R21). Asked of the shop's setting today —
     * the mail goes out today.
     */
    public function sentElsewhere(int $documentId): bool
    {
        $document = $this->store->find($documentId);

        if (null === $document || null === $this->policy) {
            return false;
        }

        $issuer = self::issuerOf($document);
        $shopId = (int) ($document['shop'] ?? 0);

        if (Invoicing::CONSHO === $issuer) {
            $invoicing = $this->policy->forShop($shopId);

            return $invoicing->conshoNumbers && !$invoicing->conshoSends;
        }

        return !$this->policy->forName($issuer, $shopId)->conshoSends;
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
     * The module's PDF of a document it has issued; null before that, for
     * "off", and where the module is gone or has none.
     *
     * @param array<string, mixed> $document
     *
     * @return array{pdf: string, fileName: string, fromArchive: bool, archiveProblem: bool, logoMissing: bool}|null
     */
    private function modulePdf(array $document, string $issuer): array|null
    {
        $externalId = (string) ($document['externalId'] ?? '');

        if (Invoicing::OFF === $issuer || null === $this->policy || 'issued' !== (string) ($document['externalState'] ?? '') || '' === $externalId) {
            return null;
        }

        $shopId = (int) ($document['shop'] ?? 0);
        $pdf = $this->policy->forName($issuer, $shopId)->provider?->pdf($shopId, $externalId);

        if (null === $pdf || '' === $pdf) {
            return null;
        }

        return ['pdf' => $pdf, 'fileName' => DocumentArchiver::fileNameOf((string) $document['documentNumber']), 'fromArchive' => false, 'archiveProblem' => false, 'logoMissing' => false];
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function issuerOf(array $document): string
    {
        $issuer = (string) ($document['issuer'] ?? '');

        return '' === $issuer ? Invoicing::CONSHO : $issuer;
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
