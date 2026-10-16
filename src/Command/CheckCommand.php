<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\EventListener\BackendSystemMessagesListener;
use Webwerkwien\ContaoConshoBundle\Setup\SetupGuide;

/**
 * Every finding, per shop, and whether each shop sells (block I, I6).
 *
 * For deploy scripts and monitoring, not only for people: the exit code is 1
 * as soon as one shop does not sell — the same "ready" as the setup page,
 * the published start point included (I7). Without JSON: machine-readable
 * output is the API plugin's (Michael, 2026-10-08: "I6 ohne JSON in den
 * Kern").
 *
 * The same sources as the back end start page, all of them: the console has
 * no module rights and no "Zur Kenntnis genommen" — it shows everything.
 *
 * Read-only.
 */
#[AsCommand(
    name: 'consho:check',
    description: 'Lists the findings per shop; exit code 1 when a shop does not sell',
)]
final class CheckCommand extends Command
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly BackendSystemMessagesListener $findings,
        private readonly SetupGuide $guide,
        private readonly \Closure|null $clock = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Only this shop configuration (ID)')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Language of the findings', 'de')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = null !== $this->clock ? ($this->clock)() : time();
        $only = null === $input->getOption('shop') ? null : (int) $input->getOption('shop');

        $language = (string) $input->getOption('language');

        $this->framework->initialize();
        $this->framework->getAdapter(System::class)->loadLanguageFile('default', $language);

        // The sources name countries in TL_LANGUAGE (DbalConfigurationIssue-
        // Source), which nobody sets on the console — "Denmark" in a German
        // sentence, measured on c5 (2026-10-08).
        $previous = $GLOBALS['TL_LANGUAGE'] ?? null;
        $GLOBALS['TL_LANGUAGE'] = $language;

        try {
            // Read afresh: the listener keeps the list per request, and one
            // read earlier in this process would carry its language over.
            $this->findings->reset();
            $issues = $this->findings->issues();
        } finally {
            if (null === $previous) {
                unset($GLOBALS['TL_LANGUAGE']);
            } else {
                $GLOBALS['TL_LANGUAGE'] = $previous;
            }
        }
        $all = $this->guide->shops();
        $shops = null === $only ? $all : array_intersect_key($all, [$only => true]);

        if ([] === $shops) {
            $io->error(null === $only ? 'There is no shop configuration.' : \sprintf('There is no shop configuration %d. %s', $only, KnownShops::sentence($all)));

            return Command::FAILURE;
        }

        $selling = true;

        foreach ($shops as $shopId => $title) {
            $setup = $this->guide->forShop($shopId, $title, $issues, $now);
            $selling = $selling && $setup->ready;

            $io->section(\sprintf('Shop "%s" (%d): %s', $this->text($title), $shopId, $setup->ready ? 'sells' : 'DOES NOT SELL'));

            if ([] === $setup->roots) {
                $io->text('[blocks]  No published start point names this shop configuration.');
            }

            $mine = array_filter($issues, static fn (ConfigurationIssue $issue): bool => $issue->shopId === $shopId);
            $this->lines($io, $mine, $shopId);

            if ([] === $setup->roots && [] === $mine) {
                continue;
            }

            if ([] === $mine) {
                $io->text('No findings.');
            }
        }

        $general = array_filter($issues, static fn (ConfigurationIssue $issue): bool => 0 === $issue->shopId);

        if ([] !== $general) {
            $io->section('All shops');
            $this->lines($io, $general, 0);
        }

        return $selling ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Blocking first, then warnings, then notes.
     *
     * @param array<ConfigurationIssue> $issues
     */
    private function lines(SymfonyStyle $io, array $issues, int $shopId): void
    {
        // A blocking finding without a shop (0) blocks every shop. The
        // currency findings stop a sale without blocking (SetupGuide).
        $rank = static fn (ConfigurationIssue $issue): int => match (true) {
            CheckoutReadiness::blocks($issue, $shopId), $shopId > 0 && $issue->shopId === $shopId && \in_array($issue->finding, SetupGuide::UNSELLABLE, true) => 0,
            $issue->finding->isWarning() => 1,
            default => 2,
        };

        usort($issues, static fn (ConfigurationIssue $a, ConfigurationIssue $b): int => $rank($a) <=> $rank($b));

        foreach ($issues as $issue) {
            $io->text(\sprintf('%-9s %s', ['[blocks]', '[warning]', '[note]'][$rank($issue)], $this->text($this->findings->sentence($issue))));
        }
    }

    /**
     * The sentences are HTML for the back end; the console wants text.
     */
    private function text(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
