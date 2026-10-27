<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingRun;

/**
 * Runs the subscription billing now (Abo-4, P32) — what the cron does every
 * hour, for an operator who does not want to wait and for a test run.
 *
 * No option to bill "as of" another moment: the orders would carry today's
 * date for a period the clock has not reached, and an invoice with a wrong
 * date is exactly what must never come out of a shop in operation.
 */
#[AsCommand(
    name: 'consho:subscription:bill',
    description: 'Create the follow-up orders of all subscriptions whose period has begun.',
)]
class BillSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SubscriptionBillingRun $run,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Before anything is computed: Contao sets the installation's time
        // zone here, and the period boundaries are wall-clock times in it
        // (Abo-3 — a command's services exist before this line).
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $billed = $this->run->run(time());

        if ([] === $billed) {
            $io->success('No subscription is due.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Subscription', 'Order ID', 'Period'],
            array_map(
                static fn ($period): array => [$period->subscriptionNumber, $period->orderId, date('Y-m-d H:i', $period->periodFrom).' – '.date('Y-m-d H:i', $period->periodUntil)],
                $billed,
            ),
        );
        $io->success(\sprintf('%d follow-up order(s) created. Failures, if any, are in the log.', \count($billed)));

        return Command::SUCCESS;
    }
}
