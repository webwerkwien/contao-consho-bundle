<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;

/**
 * Reports where tl_consho_sold_out differs from the stock (block
 * Verfügbarkeit, V1). Writes only with --rebuild — a verify that repairs
 * hides the cause one is looking for, like consho:visibility:verify.
 */
#[AsCommand(
    name: 'consho:sold-out:verify',
    description: 'Reports where the "sold out" marks of the listing filter differ from the stock',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    // Only with --rebuild.
    writes: true,
    tables: ['tl_consho_sold_out', 'tl_consho_product'],
    trace: [],
    repeatable: true,
)]
final class SoldOutVerifyCommand extends Command
{
    public function __construct(
        private readonly SoldOutMarks $marks,
        private readonly CatalogCacheTags|null $catalog = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rebuild', null, InputOption::VALUE_NONE, 'Rebuild the marks afterwards');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deviations = $this->marks->verify();

        if ([] === $deviations['missing'] && [] === $deviations['surplus']) {
            $io->success('The sold-out marks match the stock.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Article', 'Deviation'],
            [
                ...array_map(static fn (int $article): array => [(string) $article, 'sold out, not marked'], $deviations['missing']),
                ...array_map(static fn (int $article): array => [(string) $article, 'marked, not sold out'], $deviations['surplus']),
            ],
        );

        if ($input->getOption('rebuild')) {
            $this->marks->rebuild();
            $this->catalog?->invalidate([CatalogCacheTags::LISTINGS]);
            $io->success('Rebuilt.');

            return Command::SUCCESS;
        }

        $io->warning(\sprintf('%d deviation(s). Run with --rebuild to correct them.', \count($deviations['missing']) + \count($deviations['surplus'])));

        return Command::FAILURE;
    }
}
