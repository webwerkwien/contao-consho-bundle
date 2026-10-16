<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityIssueSource;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Seo\SitemapCacheTags;

#[AsCommand(
    name: 'consho:visibility:rebuild',
    description: 'Rebuilds the visibility projection that listings, filters and the sitemap read',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    writes: true,
    tables: ['tl_consho_product_visibility', 'tl_consho_product', 'tl_consho_projection_state'],
    trace: [],
    repeatable: true,
)]
final class VisibilityRebuildCommand extends Command
{
    public function __construct(
        private readonly VisibilityProjector $projector,
        private readonly Connection $connection,
        private readonly CacheInterface|null $cache = null,
        private readonly SitemapCacheTags|null $sitemaps = null,
        private readonly CatalogCacheTags|null $catalog = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $start = hrtime(true);
        $this->projector->rebuild();
        $seconds = (hrtime(true) - $start) / 1e9;

        // The start page keeps the deviation count for an hour.
        $this->cache?->delete(VisibilityIssueSource::CACHE_KEY);

        // A rebuild can bring products into listings and the sitemap or take
        // them out — both are cached (B-7b).
        $this->sitemaps?->invalidateShopSitemaps();
        $this->catalog?->invalidate([CatalogCacheTags::LISTINGS]);

        $io->success(\sprintf(
            '%d visibility row(s) in %.1f s.',
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility'),
            $seconds,
        ));

        return Command::SUCCESS;
    }
}
