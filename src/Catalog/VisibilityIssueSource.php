<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Reports rows of the visibility projection that do not match the catalogue
 * (B-10) — the counterpart of ListPriceIssueSource.
 *
 * The back end triggers and the crons keep the projection in step. This is
 * for everything else: raw SQL, a write through the model layer, a cron that
 * did not run. It reports and never repairs; consho:visibility:rebuild does.
 *
 * Cached for an hour for the same reason as the list prices: sources run on
 * every call of the start page, and verify() recalculates every shop in every
 * language. consho:visibility:rebuild deletes the entry.
 */
final class VisibilityIssueSource implements ConfigurationIssueSource
{
    public const CACHE_KEY = 'consho.visibility_deviations';

    private const LIFETIME = 3600;

    public function __construct(
        private readonly VisibilityProjector $projector,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Impure: the answer depends on the cache entry and the database, and
     * changes when either does — two calls may return different findings.
     *
     * @phpstan-impure
     */
    public function issues(): array
    {
        $count = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): int {
            $item->expiresAfter(self::LIFETIME);

            return \count($this->projector->verify());
        });

        if ($count < 1) {
            return [];
        }

        return [new ConfigurationIssue(ConfigurationFinding::VisibilityDeviation, ['count' => (string) $count])];
    }
}
