<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityIssueSource;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceIssueSource;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Seo\SitemapCacheTags;

#[AsCommand(
    name: 'consho:price:rebuild',
    description: 'Rebuilds the list price projection used for filtering and sorting',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    writes: true,
    // The visibility projection is rebuilt along with it (B-10); a changed
    // list price goes into the price history (PriceHistory).
    tables: ['tl_consho_product_list_price', 'tl_consho_product_visibility', 'tl_consho_price', 'tl_consho_price_history', 'tl_consho_projection_state'],
    // No version, undo entry or log line — a rebuild is undone by the next.
    trace: [],
    repeatable: true,
)]
final class PriceRebuildCommand extends Command
{
    public function __construct(
        private readonly ListPriceProjector $projector,
        private readonly CacheInterface|null $cache = null,
        private readonly SitemapCacheTags|null $sitemaps = null,
        private readonly CatalogCacheTags|null $catalog = null,
        private readonly VisibilityProjector|null $visibility = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $written = $this->projector->rebuild();

        // The visibility projection carries "priced" and the "from" price,
        // both derived from the list prices just rebuilt (B-10).
        $this->visibility?->rebuild();

        // The start page keeps the deviation counts for an hour; after a repair
        // it would otherwise go on reporting what no longer exists.
        $this->cache?->delete(ListPriceIssueSource::CACHE_KEY);
        $this->cache?->delete(VisibilityIssueSource::CACHE_KEY);

        // A rebuild can bring products into the listing or take them out, and
        // with them into or out of the sitemap Contao caches for a month.
        $this->sitemaps?->invalidateShopSitemaps();

        // ... and so can it the cached listing pages (B-7b).
        $this->catalog?->invalidate([CatalogCacheTags::LISTINGS]);

        $io->success(sprintf('%d list price(s) changed, added or deleted.', $written));

        return Command::SUCCESS;
    }
}
