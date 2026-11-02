<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;

/**
 * Asks the providers about the refunds they answered "later" (block Zahlung
 * Mollie, R4). Hourly: a bank transfer back takes a day or two, and the order
 * page and the dashboard should not lag behind it by more than an hour.
 */
#[AsCronJob('hourly')]
class RefundPayoutCron
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly RefundPayout $payout,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(): void
    {
        $this->payout->checkPending(null !== $this->clock ? ($this->clock)() : time());
    }
}
