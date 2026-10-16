<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCoverage;

/**
 * How each shop start point delivers its catalogue — reported, not judged
 * (plan B-8, specification 9.1).
 *
 * Whether pages are cached is the operator's decision, and Contao's default is
 * no shared cache. A proxy in front (Cloudflare, Varnish, nginx) makes Contao's
 * setting meaningless and cannot be seen from here. So: no exclamation marks,
 * no recommendation, and the limit said out loud.
 *
 * Read-only.
 */
#[AsCommand(
    name: 'consho:catalog:diagnose',
    description: 'Describes per shop start point how the catalogue is delivered',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    writes: false,
    tables: ['tl_consho_shop', 'tl_page'],
)]
final class CatalogDiagnoseCommand extends Command
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
        $summary = $this->coverage->summary($now);

        if ([] === $summary) {
            $io->text('No start point carries a shop configuration.');

            return Command::SUCCESS;
        }

        foreach ($summary as $root) {
            $io->section(\sprintf('Start point "%s" (%d, %s)%s', $root['rootTitle'], $root['rootId'], $root['language'], $root['published'] ? '' : ' — not published, skipped by consho:catalog:verify'));

            $io->definitionList(
                ['Visible products' => (string) $root['visible']],
                ['of which without a price' => (string) ($root['visible'] - $root['priced'])],
                ['Listing elements' => \sprintf('%d, of which without a preset %d', $root['listings'], $root['unfiltered'])],
                ['Page cache' => null === $root['pageCache']
                    ? 'off at the start point (Contao default) — catalogue pages are computed on every request'
                    : \sprintf('%d s at the start point', $root['pageCache'])],
            );

            $io->text('Pages below the start point may set their own cache. A proxy in front cannot be seen from here.');
        }

        return Command::SUCCESS;
    }
}
