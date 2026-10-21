<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Archives a document once nothing can roll it back any more (review of
 * dev#1, finding 1).
 *
 * A document issued inside a caller's transaction — Contao's "Mehrere
 * bearbeiten" opens one (DC_Table::editAll), and an automatic status change
 * runs inside the transaction of the payment or delivery it follows — must
 * not be archived there: a later rollback would free the number
 * while the PDF stayed behind. Marking it for `consho:document:verify --retry`
 * was the earlier answer, but the retry uses the undecorated archiver, so the
 * accounting modules (HandingOverArchiver) never got the document.
 *
 * So after() archives at once outside a transaction, and inside one only
 * notes the id. archivePending() runs when the request or the command has
 * finished (kernel.terminate, console.terminate, after each worker message),
 * when every transaction is decided, through the DECORATED archiver — archive
 * and handover as for any other document. A document that was rolled back no
 * longer exists, and the archiver skips it by itself (DocumentArchiver::archive()).
 *
 * The archiver as a closure, like PaymentRecordListener: this listener is
 * built on every request, the archiver pulls in the PDF renderer.
 */
class DeferredArchive
{
    /** @var array<int, true> */
    private array $pending = [];

    /**
     * @param \Closure(): DocumentArchiverInterface $archiver
     */
    public function __construct(
        private readonly Connection $connection,
        #[AutowireServiceClosure(DocumentArchiverInterface::class)]
        private readonly \Closure $archiver,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function after(int $documentId): void
    {
        if (!$this->connection->isTransactionActive()) {
            ($this->archiver)()->archive($documentId);

            return;
        }

        $this->pending[$documentId] = true;
    }

    /**
     * Late in kernel.terminate, like EntitlementRevocations: after Contao's
     * web cron and web worker, so whatever they issue is archived in the same
     * request.
     */
    #[AsEventListener(KernelEvents::TERMINATE, priority: -4096)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    #[AsEventListener(WorkerMessageHandledEvent::class)]
    public function archivePending(): void
    {
        if ([] === $this->pending) {
            return;
        }

        // A transaction left open at the end is rolled back when the
        // connection closes, the documents with it — and a mark written now
        // would go the same way. Said in the log rather than lost.
        if ($this->connection->isTransactionActive()) {
            $this->logger->error(\sprintf('Documents %s were not archived: a transaction was still open at the end of the request.', implode(', ', array_keys($this->pending))));

            return;
        }

        $ids = array_keys($this->pending);
        $this->pending = [];

        foreach ($ids as $id) {
            // Each on its own: after fastcgi_finish_request() an exception
            // would only reach PHP's error log and skip the rest.
            try {
                ($this->archiver)()->archive($id);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Document %d could not be archived after the request: %s', $id, $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}
