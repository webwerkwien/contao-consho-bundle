<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Webwerkwien\ContaoConshoBundle\Privacy\OrderAnonymizer;
use Webwerkwien\ContaoConshoBundle\Privacy\RetentionPolicy;

/**
 * Anonymises orders whose retention period has ended (D-5, L5): in shops that
 * anonymise automatically, and wherever erasure was requested (L3).
 *
 * Daily, like the other clean-ups: the period ends on 31.12., a day more or
 * less decides nothing. No marker of the last run — each order's own period
 * decides, so a cron that was down catches up in one go. The start page
 * announces what is due 30 days ahead (RetentionIssueSource).
 */
#[AsCronJob('daily')]
class RetentionCron
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly RetentionPolicy $policy,
        private readonly OrderAnonymizer $anonymizer,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = null !== $this->clock ? ($this->clock)() : time();

        foreach ($this->policy->due($now) as $order) {
            $this->anonymizer->anonymize($order['id'], $now);
        }

        // Contracts that ended after their last order was anonymised (Abo-DSGVO, P85).
        $this->anonymizer->anonymizeEndedSubscriptions($now);

        // Withdrawals that never found their order (WA, A6) — in every shop:
        // no voucher to keep, so no operator's choice to wait for.
        $this->anonymizer->anonymizeUnassignedWithdrawals($now);
    }
}
