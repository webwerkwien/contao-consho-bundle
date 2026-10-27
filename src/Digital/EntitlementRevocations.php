<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Tells the delivery handlers which entitlements were revoked (Nachlese E6).
 *
 * One place for the three ways an entitlement is revoked — a withdrawal of a
 * service (DL9), a subscription purchase withdrawn (P78), the operator's tick
 * — so a module does not depend on which of them happened. Only handlers
 * implementing RevocableDeliveryHandlerInterface are asked.
 *
 * ── After the commit, not inside it (Nachlese) ─────────────────
 *
 * The revocations happen inside transactions that may still roll back — a
 * withdrawal whose refund fails, a cancellation whose voucher rollback
 * throws. A module told inside would have closed an account Consho never
 * closed. So revoked() only notes the ids, and tellPending() sends them when
 * the request or the command has finished (kernel.terminate,
 * console.terminate), when every transaction is settled. What was rolled back
 * is no longer `revoked = 1` and drops out of the query by itself.
 */
class EntitlementRevocations
{
    /** @var array<int, true> */
    private array $pending = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly DeliveryHandlers $handlers,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<int> $entitlementIds revoked just now, perhaps in a transaction still open
     */
    public function revoked(array $entitlementIds): void
    {
        foreach ($entitlementIds as $id) {
            $this->pending[$id] = true;
        }
    }

    /**
     * Late in kernel.terminate — after Contao's web cron (priority 0) and its
     * web worker (-2048), so whatever they revoke is told in the same request.
     * And after every message a worker handled, so a long-running consumer
     * does not hold them for hours.
     */
    #[AsEventListener(KernelEvents::TERMINATE, priority: -4096)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    #[AsEventListener(WorkerMessageHandledEvent::class)]
    public function tellPending(): void
    {
        if ([] === $this->pending) {
            return;
        }

        // A transaction left open at the end: nothing is settled, nothing told —
        // but said, rather than lost without a trace.
        if ($this->connection->isTransactionActive()) {
            $this->logger->warning(\sprintf('Revoked entitlements %s were not passed to their delivery modules: a transaction was still open.', implode(', ', array_keys($this->pending))));

            return;
        }

        $ids = array_keys($this->pending);
        $this->pending = [];

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_consho_entitlement WHERE id IN (?) AND revoked = 1 ORDER BY id',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        foreach ($rows as $row) {
            $type = (string) $row['deliveryType'];

            if (!$this->handlers->has($type)) {
                continue;
            }

            $handler = $this->handlers->get($type);

            if (!$handler instanceof RevocableDeliveryHandlerInterface) {
                continue;
            }

            try {
                $handler->revoke(Entitlement::fromRow($row));
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The delivery module "%s" could not process the revocation of entitlement %d: %s', $type, (int) $row['id'], $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}
