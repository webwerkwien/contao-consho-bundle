<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Reports projected list prices that do not match the prices (A-4b, E3).
 *
 * The back end triggers keep the projection in step with every change made
 * through the back end. This finding is for everything else: a write through
 * the model layer (contao-ai-cli saves models, which fires no DCA callback),
 * raw SQL, a cron that did not run. It reports — it never repairs; that stays
 * consho:price:rebuild, for the reason ListPriceProjector gives.
 *
 * ── Why the count is cached ──────────────────────────────────────────────
 *
 * Sources run on EVERY call of the start page (BackendSystemMessagesListener),
 * and verify() recalculates every article in every market. Measured nowhere
 * as cheap enough to repeat per page view, so the number is kept for an hour.
 * consho:price:rebuild deletes it, so the finding disappears with the repair
 * rather than an hour later.
 */
final class ListPriceIssueSource implements ConfigurationIssueSource
{
    public const CACHE_KEY = 'consho.list_price_deviations';

    private const LIFETIME = 3600;

    public function __construct(
        private readonly ListPriceProjector $projector,
        private readonly CacheInterface $cache,
    ) {
    }

    public function issues(): array
    {
        $count = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): int {
            $item->expiresAfter(self::LIFETIME);

            return \count($this->projector->verify());
        });

        if ($count < 1) {
            return [];
        }

        return [new ConfigurationIssue(ConfigurationFinding::ListPriceDeviation, ['count' => (string) $count])];
    }
}
