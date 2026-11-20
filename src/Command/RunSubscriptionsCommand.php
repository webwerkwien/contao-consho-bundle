<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Subscription\DelinquencyChange;
use Webwerkwien\ContaoConshoBundle\Subscription\PriceOfferDue;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionChargeFallback;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCharger;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycleRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPause;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPriceOfferRun;

/**
 * Every stage of SubscriptionCron now, in its order (Abo-5 P41, Abo-6 P52):
 * pauses (Abo pausieren, P106), price offers (Abo-10), reminders, billing, ends of term, billing again, the charges of Abo-4b
 * (P94 catch-up, P96 "on account" after 7 days), payment state.
 *
 * No "as of" option, for the reason BillSubscriptionsCommand gives.
 */
#[AsCommand(
    name: 'consho:subscription:run',
    description: 'Run every stage of the subscription cron now: pauses, price offers, reminders, billing, ends of term, charges, overdue invoices.',
)]
class RunSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SubscriptionBillingRun $billing,
        private readonly SubscriptionDelinquencyRun $delinquency,
        private readonly SubscriptionLifecycleRun $lifecycle,
        // Required, like in SubscriptionCron: "Charges caught up: 0" must not
        // mean "not wired" (Abo-4b).
        private readonly SubscriptionCharger $charger,
        private readonly SubscriptionChargeFallback $fallback,
        private readonly SubscriptionPause $pauses,
        private readonly SubscriptionPriceOfferRun $prices,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The time zone first (see BillSubscriptionsCommand): the grace period
        // counts calendar days in it.
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $now = time();

        $pauses = $this->pauses->run($now);
        $prices = $this->prices->run($now);
        $reminders = $this->lifecycle->remind($now);
        $billed = $this->billing->run($now);
        $transitions = $this->lifecycle->transition($now);
        $billed = [...$billed, ...$this->billing->run($now)];
        $caughtUp = $this->charger->chargeOutstanding($now);
        $lapsed = $this->fallback->lapse($now);
        $changes = $this->delinquency->run($now);

        $io->writeln(\sprintf('Pauses begun: %d%s', \count($pauses['paused']), [] === $pauses['paused'] ? '' : ' ('.implode(', ', $pauses['paused']).')'));
        $io->writeln(\sprintf('Pauses ended: %d%s', \count($pauses['resumed']), [] === $pauses['resumed'] ? '' : ' ('.implode(', ', $pauses['resumed']).')'));
        $io->writeln(\sprintf('Price offers sent: %d', \count($prices['offers'])));

        foreach ($prices['offers'] as $offer) {
            $io->writeln(\sprintf('  %s %s (effective %s)', $offer->subscriptionNumber, $offer->event, date('Y-m-d', $offer->effectiveAt)));
        }

        $io->writeln(\sprintf('Price offer reminders sent: %d%s', \count($prices['reminders']), [] === $prices['reminders'] ? '' : ' ('.implode(', ', array_map(static fn (PriceOfferDue $due): string => $due->subscriptionNumber, $prices['reminders'])).')'));
        $io->writeln(\sprintf('Price offers run out without an answer: %d', \count($prices['lapsed'])));

        foreach ($prices['lapsed'] as $offer) {
            $io->writeln(\sprintf('  %s %s', $offer->subscriptionNumber, 'lapsedBusiness' === $offer->event ? 'new price agreed' : 'ends'));
        }

        $io->writeln(\sprintf('Price offers void: %d%s', \count($prices['voided']), [] === $prices['voided'] ? '' : ' ('.implode(', ', $prices['voided']).')'));

        $io->writeln(\sprintf('Reminders sent: %d', \count($reminders)));

        foreach ($reminders as $reminder) {
            $io->writeln(\sprintf('  %s (term ends %s)', $reminder->subscriptionNumber, date('Y-m-d H:i', $reminder->termEndsAt)));
        }

        $io->writeln(\sprintf('Ends of term: %d', \count($transitions)));

        foreach ($transitions as $number => $transition) {
            $io->writeln(\sprintf('  %s %s', $number, $transition->value));
        }

        $io->writeln(\sprintf('Follow-up orders created: %d', \count($billed)));

        foreach ($billed as $period) {
            $io->writeln(\sprintf('  %s → order ID %d', $period->subscriptionNumber, $period->orderId));
        }

        $io->writeln(\sprintf('Charges caught up: %d%s', \count($caughtUp), [] === $caughtUp ? '' : ' (order ID '.implode(', ', $caughtUp).')'));
        $io->writeln(\sprintf('Charges gone "on account" after %d days: %d%s', SubscriptionChargeFallback::PENDING_DAYS, \count($lapsed), [] === $lapsed ? '' : ' (order ID '.implode(', ', $lapsed).')'));

        $io->writeln(\sprintf('Payment state changed: %d', \count($changes)));

        foreach ($changes as $change) {
            $io->writeln(\sprintf('  %s %s', $change->subscriptionNumber, self::describe($change)));
        }

        $io->success('Done. Failures, if any, are in the log.');

        return Command::SUCCESS;
    }

    private static function describe(DelinquencyChange $change): string
    {
        return $change->suspended
            ? 'rests (overdue order ID '.implode(', ', $change->overdueOrders).')'
            : 'active again';
    }
}
