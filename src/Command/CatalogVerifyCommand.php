<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCoverage;

/**
 * Reports products no listing shows and landing pages without their page
 * (plan B-8).
 *
 * The expensive check the start page must not run: every listing's preset and
 * pins against every product, per start point. It never writes — the same
 * stance as consho:price:verify. A product nobody lists can be deliberate; a
 * command that "fixed" it would make visible what was meant to stay a link.
 */
#[AsCommand(
    name: 'consho:catalog:verify',
    description: 'Reports products that no listing shows and orphaned landing pages, without changing anything',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    writes: false,
    tables: ['tl_consho_product', 'tl_consho_facet_page', 'tl_page'],
)]
final class CatalogVerifyCommand extends Command
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly CatalogCoverage $coverage,
        private readonly \Closure|null $clock = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = null !== $this->clock ? ($this->clock)() : time();

        $unlisted = $this->coverage->unlisted($now);
        $orphans = $this->coverage->orphanLandingPages();

        $io->section('Visible products that no listing shows');

        if ([] === $unlisted) {
            $io->text('Every visible product with a price stands in at least one listing.');
        } else {
            $io->table(
                ['Start point', 'Product', 'Title'],
                array_map(static fn (array $row): array => [\sprintf('%s (%d)', $row['rootTitle'], $row['rootId']), (string) $row['productId'], $row['title']], $unlisted),
            );
        }

        $io->section('Landing pages whose page no longer exists');

        if ([] === $orphans) {
            $io->text('None.');
        } else {
            $io->table(
                ['Landing page', 'Missing page', 'Alias'],
                array_map(static fn (array $row): array => [(string) $row['id'], (string) $row['pageId'], $row['alias']], $orphans),
            );
        }

        if ([] === $unlisted && [] === $orphans) {
            $io->success('Nothing to report.');

            return Command::SUCCESS;
        }

        $io->note(\sprintf('%d product(s) in no listing, %d orphaned landing page(s). Nothing was changed.', \count($unlisted), \count($orphans)));

        // FAILURE on findings, as consho:price:verify: it trips a cron job or a
        // CI step instead of writing into a log nobody reads.
        return Command::FAILURE;
    }
}
