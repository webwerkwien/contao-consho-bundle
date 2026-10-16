<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;

/**
 * Reports where the visibility projection differs from the catalogue.
 *
 * Never writes, like consho:price:verify — a repair here would hide the cause.
 */
#[AsCommand(
    name: 'consho:visibility:verify',
    description: 'Reports deviations in the visibility projection without changing anything',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    writes: false,
    tables: ['tl_consho_product_visibility', 'tl_consho_product'],
)]
final class VisibilityVerifyCommand extends Command
{
    public function __construct(private readonly VisibilityProjector $projector)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deviations = $this->projector->verify();

        if ([] === $deviations) {
            $io->success('The visibility projection matches the catalogue.');

            return Command::SUCCESS;
        }

        $describe = static fn (array|null $row): string => null === $row
            ? '(none)'
            : \sprintf('%s of %d, from %s', 1 === $row['variant'] ? 'variant' : 'main', $row['main'], $row['gross'] ?? '-');

        $io->table(
            // Currency since C-1c task 5b: one shop can be reached in one
            // language in two currencies, and without the column two rows of
            // the same article would read as the same deviation twice.
            ['Shop', 'Language', 'Currency', 'Article', 'Stored', 'Expected'],
            array_map(
                static fn (array $deviation): array => [
                    (string) $deviation['shop'],
                    $deviation['language'],
                    $deviation['currency'],
                    (string) $deviation['pid'],
                    $describe($deviation['stored']),
                    $describe($deviation['expected']),
                ],
                $deviations,
            ),
        );

        $io->warning(\sprintf('%d deviation(s). Run consho:visibility:rebuild to correct them.', \count($deviations)));

        // FAILURE on deviations, as consho:price:verify: it trips a cron job or
        // a CI step instead of writing a warning into a log nobody reads.
        return Command::FAILURE;
    }
}
