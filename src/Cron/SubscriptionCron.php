<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionChargeFallback;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCharger;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycleRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPause;

/**
 * Everything a subscription does by itself, every hour, beside
 * DigitalReleaseCron on the same Contao cron (A14). In this order:
 *
 *  0. pauses that begin or end (Abo pausieren, P106) — first, so a
 *     subscription back from its pause is billed in this very run
 *  1. reminders before the end of a term (Abo-6)
 *  2. billing of the periods that have begun (Abo-4) — a transition waits
 *     until the term is billed to its end, so this comes first
 *  3. the ends of term: end, renew, open-ended (Abo-6)
 *  4. billing again — the first period after a transition is due at once;
 *     a period billed with a mandate is charged right there (Abo-4b, P94)
 *  5. charges a run before this one did not get to, and charges without an
 *     answer for 7 days "on account" (Abo-4b, P96)
 *  6. the payment state: rest and come back (Abo-5); after the billing, so a
 *     period billed in this run is the newest order the mails name
 *
 * A period starts at the time of day of the purchase, so an order comes at
 * most an hour after it.
 */
#[AsCronJob('hourly')]
class SubscriptionCron
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly SubscriptionBillingRun $run,
        private readonly SubscriptionDelinquencyRun $delinquency,
        private readonly SubscriptionLifecycleRun $lifecycle,
        // Required (second review Abo-4b): an optional one that the
        // container failed to wire would make the cron skip P94/P96 in silence.
        private readonly SubscriptionCharger $charger,
        private readonly SubscriptionChargeFallback $fallback,
        private readonly SubscriptionPause $pauses,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = null !== $this->clock ? ($this->clock)() : time();

        $this->pauses->run($now);
        $this->lifecycle->remind($now);
        $this->run->run($now);
        $this->lifecycle->transition($now);
        $this->run->run($now);
        $this->charger->chargeOutstanding($now);
        $this->fallback->lapse($now);
        $this->delinquency->run($now);
    }
}
