<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttemptRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStarter;

/**
 * Closes open payment attempts nobody came back to (C-11, Michael 2026-09-26:
 * „ja zur doku und aufräumen").
 *
 * An open attempt lapses after PaymentStarter::ATTEMPT_LIFETIME, but until
 * now that only mattered at the next "try again". A buyer who left the
 * provider's page and never returned left the attempt `open` for good — and
 * the provider's session payable. This cancels it and tells the provider,
 * like a new start does for the one lapsed attempt in its way.
 *
 * Money that arrives afterwards is still booked (Z3): a cancelled attempt
 * stops nothing but a new start.
 *
 * Hourly: the lifetime is 30 minutes, and a nightly run would leave a
 * provider session payable for up to a day. The criterion is each row's own
 * createdAt, so a run that was missed catches up.
 */
#[AsCronJob('hourly')]
class PaymentAttemptCleanupCron
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly PaymentAttemptRepositoryInterface $attempts,
        private readonly PaymentHandlers $handlers,
        private readonly PaymentMethodLookupInterface $methods,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = null !== $this->clock ? ($this->clock)() : time();

        foreach ($this->attempts->openBefore($now - PaymentStarter::ATTEMPT_LIFETIME) as $attempt) {
            // Conditional: the notification may have paid it a moment ago.
            if (!$this->attempts->cancelIfOpen($attempt->id, $now)) {
                continue;
            }

            if (!$this->handlers->has($attempt->methodType)) {
                continue;
            }

            try {
                $this->handlers->get($attempt->methodType)->cancel($attempt, $this->methods->byId($attempt->methodId)->config ?? []);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Cancelling lapsed payment attempt %d with its provider failed: %s', $attempt->id, $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}
