<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;

/**
 * Reports deviations between the projection and the prices it derives from.
 *
 * It never writes. A materialised value that is silently wrong is worse than
 * none at all — in a filter nobody notices. Repairing here would hide the cause.
 */
#[AsCommand(
    name: 'consho:price:verify',
    description: 'Reports deviations in the list price projection without changing anything',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    writes: false,
    tables: ['tl_consho_product_list_price', 'tl_consho_price'],
)]
final class PriceVerifyCommand extends Command
{
    public function __construct(private readonly ListPriceProjector $projector)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deviations = $this->projector->verify();

        if ([] === $deviations) {
            $io->success('The list price projection matches the price table.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Product', 'Currency', 'Country', 'Stored net', 'Expected net', 'Stored gross', 'Expected gross'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['productId'],
                    $row['currency'],
                    $row['country'],
                    $row['storedNet'] ?? '(missing)',
                    $row['expectedNet'] ?? '(no price)',
                    $row['storedGross'] ?? '(missing)',
                    $row['expectedGross'] ?? '(no price)',
                ],
                $deviations,
            ),
        );

        $io->warning(sprintf(
            '%d deviation(s). Run consho:price:rebuild to correct them.',
            \count($deviations),
        ));

        /*
         * FAILURE on deviations is deliberate: the command then trips a cron
         * job or a CI step instead of writing a warning into a log nobody
         * reads.
         */
        return Command::FAILURE;
    }
}
