<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Model\TaxClassModel;
use Webwerkwien\ContaoConshoBundle\Tax\DeliveryCountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxClassReferences;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

/**
 * Explains why a shop offers the delivery countries it offers.
 *
 * Written for A-3, kept because the question it answers is one
 * an operator will ask in earnest: a country vanishes from checkout and nothing
 * says why. The rule — a country needs a rate for *every* tax class in use — is
 * correct but invisible from the outside.
 *
 * Read-only.
 */
#[AsCommand(
    name: 'consho:tax:diagnose',
    description: 'Show the tax classes, rates and derived delivery countries of a shop.',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    writes: false,
    tables: ['tl_consho_shop', 'tl_consho_tax_class', 'tl_consho_tax_rate'],
)]
class DiagnoseTaxCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TaxRateRepositoryInterface $repository,
        private readonly DeliveryCountryProvider $deliveryCountries,
        private readonly TaxResolver $resolver,
        private readonly TaxClassReferences $references,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('shop', InputArgument::REQUIRED, 'ID of the shop configuration')
            ->addArgument('date', InputArgument::OPTIONAL, 'Date to resolve for, e.g. 2020-09-01', 'now')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Resolve one tax class directly, ignoring the shop')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Country for --class')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);

        $shopId = (int) $input->getArgument('shop');
        $timestamp = strtotime((string) $input->getArgument('date'));

        if (false === $timestamp) {
            $io->error('Cannot read that date. Give it like 2020-09-01, or "now".');

            return Command::INVALID;
        }

        /*
         * The direct route answers "what applies to this class in this country
         * on this date" without going through a shop. It is what proves a
         * time-limited rate really is time-limited — the shop route only ever
         * asks about today.
         */
        $class = $input->getOption('class');
        $country = $input->getOption('country');

        if (null !== $class && null !== $country) {
            try {
                $io->success(sprintf(
                    'Class %d, %s, %s: %s',
                    (int) $class,
                    strtoupper((string) $country),
                    date('Y-m-d', $timestamp),
                    $this->resolver->resolveRate((int) $class, (string) $country, $timestamp),
                ));
            } catch (TaxRateNotFound $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        /*
         * 🔴 Through the framework adapter, not statically.
         *
         * Twelve places in `src/` do it this way; this file was the exception — and
         * the exception was exactly the one without a test. A static
         * `Model::findByPk()` cannot be replaced, so the test would need a database
         * and an initialised framework; through the adapter it is a mock. The gap
         * showed as "no test calls this place" — the cause was not the command but
         * that it did not follow the house rule.
         */
        $shops = $this->framework->getAdapter(ShopModel::class);
        $classes = $this->framework->getAdapter(TaxClassModel::class);

        $shop = $shops->findByPk($shopId);

        if (!$shop instanceof ShopModel) {
            $io->error(sprintf('No shop configuration with ID %d. %s', $shopId, KnownShops::sentence(KnownShops::of($this->connection))));

            return Command::FAILURE;
        }

        $io->title(sprintf('%s — %s', $shop->title, date('Y-m-d', $timestamp)));

        $taxClassIds = $this->repository->findTaxClassIdsUsedByShop($shopId);

        if ([] === $taxClassIds) {
            $io->warning('No product in this shop carries a tax class, so nothing narrows the whitelist.');
        }

        $rows = [];

        foreach ($taxClassIds as $taxClassId) {
            $class = $classes->findByPk($taxClassId);
            $countries = $this->repository->findConfiguredCountries($taxClassId, $timestamp);

            sort($countries);

            $rows[] = [
                $taxClassId,
                $class instanceof TaxClassModel ? $class->title : '?',
                [] === $countries ? '—' : implode(', ', $countries),
            ];
        }

        if ([] !== $rows) {
            $io->section('Tax classes in use');
            $io->table(['ID', 'Class', 'Countries with a valid rate'], $rows);
        }

        $offered = $this->deliveryCountries->forShop($shop, 'en');

        $io->section('Delivery countries offered');
        $io->writeln([] === $offered ? '—' : implode(', ', array_keys($offered)));

        /*
         * The country stays on offer even when a rate is missing — see
         * DeliveryCountryProvider for why. That makes naming the gap the whole
         * job of this command: nothing else says which country cannot be
         * priced, and the most likely cause is not carelessness. Denmark has no
         * reduced VAT rate at all, so a class called "Ermäßigt" invites leaving
         * it empty there.
         */
        $incomplete = $this->deliveryCountries->incompleteFor($shop, $timestamp);

        if ([] !== $incomplete) {
            $io->section('Offered but not fully priced');

            $rows = [];

            foreach ($incomplete as $country => $missingClassIds) {
                foreach ($missingClassIds as $taxClassId) {
                    $class = $classes->findByPk($taxClassId);

                    $rows[] = [
                        $country,
                        sprintf('%s (ID %d)', $class instanceof TaxClassModel ? $class->title : '?', $taxClassId),
                    ];
                }
            }

            $io->table(['Country', 'Has no rate for'], $rows);
            $io->warning(
                'An order from these countries will fail at the tax calculation. '
                .'A country without a reduced rate of its own still needs a rate — enter the standard one.',
            );
        }

        /*
         * A rate with tstamp 0 is a draft — an unsaved copy — and counts for
         * nothing (findRate()). A row written by an import or raw SQL without
         * tstamp lands here too, and would otherwise be a gap without a name
         * (CW-6).
         */
        $drafts = [] === $taxClassIds ? [] : $this->connection->fetchAllAssociative(
            'SELECT pid, country, rate FROM tl_consho_tax_rate WHERE tstamp = 0 AND country <> \'\' AND pid IN (?) ORDER BY pid, country',
            [$taxClassIds],
            [ArrayParameterType::INTEGER],
        );

        if ([] !== $drafts) {
            $io->section('Drafts that do not count');
            $io->table(['Class', 'Country', 'Rate'], array_map(static fn (array $row): array => [(int) $row['pid'], (string) $row['country'], (string) $row['rate']], $drafts));
            $io->note('These rates were never saved (tstamp 0) — an unsaved copy, or a row written past the back end. Open and save one in the back end to make it count.');
        }

        /*
         * Every combination the checkout could reach, resolved for real. The
         * section above says which ones are missing; this one proves the rest
         * actually answer, rather than merely looking configured.
         */
        if ([] !== $taxClassIds && [] !== $offered) {
            $io->section('Resolved rates');

            $resolved = [];

            foreach ($taxClassIds as $taxClassId) {
                foreach (array_keys($offered) as $country) {
                    try {
                        $resolved[] = [$taxClassId, $country, $this->resolver->resolveRate($taxClassId, $country, $timestamp)];
                    } catch (TaxRateNotFound $e) {
                        $resolved[] = [$taxClassId, $country, 'MISSING — '.$e->getMessage()];
                    }
                }
            }

            $io->table(['Class', 'Country', 'Rate'], $resolved);
        }

        $this->reportOrphans($io);

        return Command::SUCCESS;
    }

    /**
     * References to a tax class that no longer exists.
     *
     * TaxClassDeleteVoter closes the back end's way of producing these, so on
     * an installation that has only ever been used through the back end this
     * section stays empty. It is here for the ways the voter cannot reach: a
     * product deleted before the class and restored from the undo bin
     * afterwards, an import, a write straight to the database — and an
     * installation that carried the gap before the voter existed.
     *
     * 🎯 **Not on the back end start page**, although that is where an
     * operator would see it: the start page runs on every single call, and
     * this walks the whole catalogue. The expensive tax questions belong
     * here, which is what DbalConfigurationIssueSource says about itself.
     *
     * Whole installation, not the shop that was asked about — see
     * TaxClassReferences::orphans().
     *
     * ⚠️ **`DiagnoseTaxCommandTest` calls this method** — and the way there is
     * the real story. For a while no test reached this section. The reason was
     * not that a console command is hard to test, but that this file alone
     * fetched models **statically**, while the rest of `src/` takes the
     * framework adapter. A static call cannot be replaced; through the adapter
     * it is a mock.
     *
     * The test covers both: that the section **comes** when a reference is
     * orphaned, and that it **stays away** when none is — and that the
     * `--class/--country` path returns before, so that a later clean-up does not
     * remove the early return by accident.
     */
    private function reportOrphans(SymfonyStyle $io): void
    {
        $orphans = $this->references->orphans();

        if ([] === $orphans) {
            return;
        }

        $io->section('Pointing at a tax class that does not exist');
        $io->table(
            ['Table', 'ID', 'Title', 'Missing class'],
            array_map(
                static fn (array $row): array => [$row['table'], $row['id'], $row['title'], $row['taxClass']],
                $orphans,
            ),
        );
        $io->warning(
            'These rows cannot be priced: the rate cannot be resolved, so the product shows no price '
            .'and the basket line stays without one — quietly, with nothing in the log. '
            .'Open each record and pick a tax class. Whole installation, not just this shop.',
        );

        // Abo-3: a subscription is read-only in the mask and has
        // no price on show — its damage is the next bill.
        if ([] !== array_filter($orphans, static fn (array $row): bool => 'tl_consho_subscription' === $row['table'])) {
            $io->warning(
                'A subscription listed here cannot be billed again: every follow-up bill looks its tax class up. '
                .'Its mask is read-only — restore the tax class with the same ID (undo bin) rather than editing the subscription.',
            );
        }
    }
}
