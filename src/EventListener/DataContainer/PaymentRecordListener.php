<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;

/**
 * A payment the operator records in the back end has the same consequences
 * as one a provider reports (D-1): invoice, status, mail.
 *
 * onsubmit: the row is stored by then. PaymentRecorded is idempotent, so a
 * second save of the same payment changes nothing.
 *
 * ── Inside Contao's own transaction: afterwards ──────────────────────────
 *
 * A single edit (act=edit) runs without a transaction, and the consequences
 * follow at once. "Mehrere bearbeiten" (editAll, overrideAll) wraps every
 * record in ONE transaction and rolls it back if any of them fails
 * validation. Run inside it, the mail would be out while paymentSettledAt
 * was rolled back — and the next save would send it again (Fable review of
 * D-1, S2). There the orders are noted and handled at kernel.terminate, when
 * the transaction is decided either way; PaymentRecorded then reads only
 * what was really stored.
 *
 * ── PaymentRecorded as a closure (Nachlese Stufe 2) ───────────────────────
 *
 * The terminate listener is built on EVERY request and every console command,
 * and with it whatever it takes in its constructor — PaymentRecorded pulls in
 * documents and mail, down to SecretCipher. Found when a missing
 * CONSHO_SECRET_KEY broke `contao-setup` on the sales shop (v0.1.2). Built
 * only when a payment was actually recorded.
 */
class PaymentRecordListener
{
    /** @var array<int, true> */
    private array $deferred = [];

    /**
     * @param \Closure(): PaymentRecorded $recorded
     */
    public function __construct(
        #[AutowireServiceClosure(PaymentRecorded::class)]
        private readonly \Closure $recorded,
        private readonly Connection $connection,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $orderId = (int) ($dc->getCurrentRecord()['pid'] ?? 0);

        if ($orderId < 1) {
            return;
        }

        if ($this->connection->isTransactionActive()) {
            $this->deferred[$orderId] = true;

            return;
        }

        ($this->recorded)()->handle($orderId, false, time());
    }

    /**
     * Also at the end of a console command: a CLI that writes payments
     * through the DCA inside a transaction never sees kernel.terminate.
     *
     * Each order on its own: after fastcgi_finish_request() the editor has
     * the page already, and an exception here would only reach PHP's error
     * log and skip the orders after it (second Fable review of D-1, S-b).
     */
    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function onTerminate(): void
    {
        $orders = array_keys($this->deferred);
        $this->deferred = [];

        foreach ($orders as $orderId) {
            try {
                ($this->recorded)()->handle($orderId, false, time());
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The consequences of a payment of order %d failed: %s', $orderId, $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}
