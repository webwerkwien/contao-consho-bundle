<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;

/**
 * Has the issuing module issue a document right after the transaction that
 * wrote it has committed (block R, R18).
 *
 * The archiver is already called exactly there, once per document, at every
 * place that issues one (HandingOverArchiver lists them) — decorating it
 * reaches them all. Outermost (priority -1, HandingOverArchiver has 0): the
 * module numbers the document first, then the inner archivers see it as it
 * stands.
 */
#[AsDecorator(DocumentArchiverInterface::class, priority: -1)]
final class ExternalIssuingArchiver implements DocumentArchiverInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly DocumentArchiverInterface $inner,
        private readonly ExternalInvoicing $external,
    ) {
    }

    public function archive(int $documentId): void
    {
        $this->external->submit($documentId);
        $this->inner->archive($documentId);
    }
}
