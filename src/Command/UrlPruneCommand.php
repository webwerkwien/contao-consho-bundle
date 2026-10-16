<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectPruner;

/**
 * Clears out product addresses that have been superseded long enough.
 *
 * ── Why it does not delete unless told twice ──────────────────────────────
 *
 * Reporting is the default and `--force` is required to remove anything. Same
 * posture as consho:price:verify: a command that deletes of its own accord gets
 * run by accident exactly once, and what it removes here are the redirects that
 * keep old links and search results alive.
 *
 * ── Why there is no built-in retention period ─────────────────────────────
 *
 * `--older-than` has no default that quietly applies. When an address may stop
 * working depends on the shop's backlinks and its visitors, and a period built
 * into the software is a period that surprises somebody eventually.
 */
#[AsCommand(
    name: 'consho:url:prune',
    description: 'Removes superseded product addresses older than a given age',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    // Only with --force.
    writes: true,
    tables: ['tl_consho_url_redirect'],
    trace: [],
    irreversible: 'With --force, deletes superseded product addresses for good: no undo entry, and the old links stop redirecting',
    repeatable: true,
)]
final class UrlPruneCommand extends Command
{
    public function __construct(private readonly RedirectPruner $pruner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Age beyond which an address may be removed, as a relative date such as "90 days" or "1 year"',
            )
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete. Without it the command only reports.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $age = (string) $input->getOption('older-than');

        if ('' === $age) {
            $io->error('Pass --older-than, for example --older-than="90 days". There is deliberately no default.');

            return Command::INVALID;
        }

        // Strictly a count and a unit: strtotime() reads far more than that,
        // and reads it wrongly without a word — "10y" took the "y" for a time
        // zone and gave a cut-off in the future, so --force would have removed
        // every address (found on the test server, CLI coverage). A positive
        // count of these units always lies in the past.
        $cutoff = 1 === preg_match('/^[1-9][0-9]*\s*(?:day|week|month|year)s?\z/i', trim($age)) ? strtotime('-'.trim($age)) : false;

        if (false === $cutoff) {
            $io->error(\sprintf('"%s" is not an age I can read. Try something like "90 days" or "1 year".', $age));

            return Command::INVALID;
        }

        $affected = $this->pruner->count($cutoff);

        if (0 === $affected) {
            $io->success(\sprintf('No superseded address is older than %s.', $age));

            return Command::SUCCESS;
        }

        if (!$input->getOption('force')) {
            $io->warning(\sprintf(
                '%d address(es) recorded before %s would be removed. Nothing was deleted — add --force to do it.',
                $affected,
                date('Y-m-d H:i', $cutoff),
            ));

            return Command::SUCCESS;
        }

        $removed = $this->pruner->prune($cutoff);

        $io->success(\sprintf('%d superseded address(es) removed. Links to them answer 404 from now on.', $removed));

        return Command::SUCCESS;
    }
}
