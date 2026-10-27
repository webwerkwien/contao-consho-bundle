<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Handoff;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentStore;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\Invoicing;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;

/**
 * Hands every issued document to the accounting modules (block R, R13).
 *
 * The archiver is already called once for every document, right after the
 * transaction that issued it has committed — at every place that issues one
 * (OrderPlacer, PaymentRecorded, DeliveryReported, PaymentChange,
 * SubscriptionChargeFallback, WithdrawalProcessing, DigitalRelease,
 * SubscriptionBiller, and through DeferredArchive the cancellation of an
 * order and the delivery note). Decorating it reaches all of them without
 * another call to forget. `consho:document:verify --retry` uses the
 * undecorated archiver, so a second archive attempt is not a second handover
 * — which is why a document issued inside a caller's transaction goes
 * through DeferredArchive, not the retry.
 *
 * Not the delivery note (dev#2, review): the modules were promised invoices,
 * corrections and cancellations (IssuedDocumentHandlerInterface), and a
 * module built on that would book a document without a single amount.
 *
 * Archived first, then handed over: a module that takes the PDF gets the
 * file the shop keeps.
 *
 * Only documents Consho issued (R21). A shop whose invoicing module passes
 * Consho's invoices on (InvoiceProviderInterface::issuesItself() false) has
 * that module receive them here as well, through handOver().
 */
#[AsDecorator(DocumentArchiverInterface::class)]
final class HandingOverArchiver implements DocumentArchiverInterface
{
    /**
     * @param iterable<IssuedDocumentHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireDecorated]
        private readonly DocumentArchiverInterface $inner,
        #[AutowireIterator('consho.issued_document_handler')]
        private readonly iterable $handlers,
        private readonly DbalDocumentStore $store,
        private readonly DocumentPdfInterface $pdfs,
        private readonly LoggerInterface $logger,
        private readonly InvoicingPolicy|null $policy = null,
    ) {
    }

    public function archive(int $documentId): void
    {
        $this->inner->archive($documentId);
        $this->handOver($documentId);
    }

    private function handOver(int $documentId): void
    {
        $handlers = [...$this->handlers];

        if ([] === $handlers && null === $this->policy) {
            return;
        }

        $row = $this->store->find($documentId);
        $type = DocumentType::tryFrom((string) ($row['type'] ?? ''));

        // Consho's documents only (R21): one a module issued is the
        // module's, and "off" has no document to book.
        if (null === $row || null === $type || DocumentType::DeliveryNote === $type
            || Invoicing::CONSHO !== (string) ($row['issuer'] ?? Invoicing::CONSHO)) {
            return;
        }

        /*
         * The shop's invoicing module where it passes Consho's invoices on
         * (R19, R21) — once, also when the same module is registered as a
         * handler too. Not a module that issues itself: after a switch to it,
         * the cancellation of an older Consho invoice is still Consho's (R20),
         * and handOver() is not that module's to receive.
         */
        $invoicing = $this->policy?->forShop((int) $row['shop']);
        $forward = null !== $invoicing && $invoicing->conshoNumbers ? $invoicing->provider : null;

        if (null !== $forward && !\in_array($forward, $handlers, true)) {
            $handlers[] = $forward;
        }

        if ([] === $handlers) {
            return;
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $pdfs = $this->pdfs;
        $document = new IssuedDocument(
            $documentId,
            (int) $row['shop'],
            (int) $row['pid'],
            $type,
            (string) $row['documentNumber'],
            (int) $row['issuedAt'],
            \is_array($payload) ? $payload : [],
            static fn (): string => $pdfs->pdf($documentId)['pdf'] ?? '',
        );

        foreach ($handlers as $handler) {
            try {
                if ($handler === $forward) {
                    $forward->handOver($document);
                } elseif ($handler instanceof IssuedDocumentHandlerInterface) {
                    $handler->handle($document);
                }
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('%s could not take document %s: %s', $handler::class, $document->number, $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}
