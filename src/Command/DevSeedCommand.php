<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogSeedPlan;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogSeedWriter;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Fills the catalogue with a reproducible test catalogue (plan B-9).
 *
 * 🔴 Exists only in the dev environment (#[When]). A command that writes
 * 100,000 products past versions and recycle bin has no business on a real
 * shop, and "the operator will not call it" is no safeguard.
 *
 * Removes an earlier seed first — only the seed ID ranges, never data entered
 * by hand — and rebuilds the list price projection afterwards; without it every
 * listing is empty and the measurement measures nothing.
 */
#[When(env: 'dev')]
#[AsCommand(
    name: 'consho:dev:seed',
    description: 'Development only: writes a reproducible test catalogue for consho:dev:benchmark',
)]
final class DevSeedCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogSeedWriter $writer,
        private readonly ListPriceProjector $projector,
        private readonly ShopLanguages $languages,
        private readonly VisibilityProjector $visibility,
        private readonly ShopCurrencies $currencies,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('products', null, InputOption::VALUE_REQUIRED, 'Number of main products', '5000')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Random seed — the same seed writes the same catalogue', '20260913')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Shop configuration the products are assigned to', '1')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per INSERT transaction', '1000')
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Pause between batches in milliseconds, for a shared database server', '0')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Only remove an earlier seed')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $removed = $this->writer->remove();
        $io->text(\sprintf('Removed %d row(s) of an earlier seed.', $removed));

        if ($input->getOption('remove')) {
            $this->projector->rebuild();
            $this->visibility->rebuild();
            $io->success('Seed removed, list price and visibility projections rebuilt.');

            return Command::SUCCESS;
        }

        $shopId = (int) $input->getOption('shop');

        /*
         * Existence and currency are two questions, asked separately since
         * C-1c. They used to share one query: an empty `currency` column meant
         * "no such shop". Since the currency moved to the start page, an empty
         * answer means the shop accepts none — a different fault with a
         * different remedy, and the old message named the wrong one.
         */
        if (false === $this->connection->fetchOne('SELECT id FROM tl_consho_shop WHERE id = ?', [$shopId])) {
            $io->error(\sprintf('There is no shop configuration %d.', $shopId));

            return Command::FAILURE;
        }

        $currencies = $this->currencies->of($shopId);

        if ([] === $currencies) {
            $io->error(\sprintf('Shop configuration %d accepts no currency, so no price can be written.', $shopId));

            return Command::FAILURE;
        }

        [$currency, $second] = CatalogSeedPlan::currenciesOf($currencies);

        $plan = new CatalogSeedPlan(
            max(1, (int) $input->getOption('products')),
            (int) $input->getOption('seed'),
            $shopId,
            $currency,
            $second,
            $this->languages->translatedOf($shopId)[0] ?? '',
            time(),
        );

        $start = hrtime(true);
        $written = $this->writer->writeGroups($plan);

        $io->progressStart($plan->products);
        $reported = 0;
        $written += $this->writer->write(
            $plan,
            max(1, (int) $input->getOption('batch')),
            max(0, (int) $input->getOption('pause')) * 1000,
            static function (int $done) use ($io, &$reported): void {
                $io->progressAdvance($done - $reported);
                $reported = $done;
            },
        );
        $io->progressFinish();

        $this->projector->rebuild();

        // After the list prices: "priced" and the "from" price come from them.
        // Timed on its own — the duration of a full rebuild is one of the
        // figures B-10 measures.
        $visibilityStart = hrtime(true);
        $this->visibility->rebuild();
        $visibilitySeconds = (hrtime(true) - $visibilityStart) / 1e9;

        $io->success(\sprintf(
            '%d products (seed %d, language "%s"), %d rows, %d list prices, %d visibility rows (rebuild %.1f s), %.1f s.',
            $plan->products,
            $plan->seed,
            $plan->language,
            $written,
            // A count of rows, not of rebuild()'s changes: a second seed with
            // the same prices changes nothing and would report 0.
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_list_price'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility'),
            $visibilitySeconds,
            (hrtime(true) - $start) / 1e9,
        ));

        return Command::SUCCESS;
    }
}
