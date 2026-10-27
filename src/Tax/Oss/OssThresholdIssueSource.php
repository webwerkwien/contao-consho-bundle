<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax\Oss;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * The OSS threshold in sight (block R, O2, R6): a note from 80 % and from
 * 100 % of the 10,000 euro, this year or last, while the business does not
 * tax at destination. It reports, it does not warn — sales outside Consho
 * count as well, and whether to switch is the operator's to decide.
 */
final class OssThresholdIssueSource implements ConfigurationIssueSource
{
    private const NEAR = 80;

    /**
     * An hour (R): the start page asks on every call, and a year
     * of orders is read for it. A note that reports, not warns, may be an
     * hour behind — the page "Steuermeldung" counts afresh.
     */
    private const LIFETIME = 3600;

    public function __construct(
        private readonly OssReport $report,
        // Explicit, like OverdueInvoiceIssueSource (R).
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface|null $cache = null,
        // For the tests; the container leaves it at "now".
        private readonly int|null $now = null,
    ) {
    }

    /**
     * @phpstan-impure
     */
    public function issues(): array
    {
        $year = (int) date('Y', $this->now ?? time());
        $issues = [];

        foreach ($this->report->businesses() as $business) {
            if ($business->taxesAtDestination()) {
                continue;
            }

            // One finding per business, for the year at the higher level —
            // reached outranks near; at the same level this year's.
            $finding = null;
            $checked = $year;

            foreach ([$year, $year - 1] as $candidate) {
                $percent = $this->percent($business, $candidate);
                $level = match (true) {
                    $percent >= 100 => ConfigurationFinding::OssThresholdReached,
                    $percent >= self::NEAR => ConfigurationFinding::OssThresholdNear,
                    default => null,
                };

                if (null !== $level && (null === $finding || (ConfigurationFinding::OssThresholdNear === $finding && ConfigurationFinding::OssThresholdReached === $level))) {
                    [$finding, $checked] = [$level, $candidate];
                }
            }

            if (null === $finding) {
                continue;
            }

            /*
             * No amount in the context: it is part of the fingerprint, and a
             * note taken would come back with every sale. Taken at 80 %, it
             * comes back at 100 % — a different case.
             */
            $issues[] = new ConfigurationIssue(
                $finding,
                ['business' => $business->label(), 'year' => (string) $checked],
                $business->shopIds()[0] ?? 0,
            );
        }

        return $issues;
    }

    private function percent(OssBusiness $business, int $year): int
    {
        $count = fn (): int => OssReport::percentOf($this->report->threshold($business, $year));

        if (null === $this->cache) {
            return $count();
        }

        return $this->cache->get('consho.oss_threshold.'.sha1($business->key.'|'.$year), function (ItemInterface $item) use ($count): int {
            $item->expiresAfter(self::LIFETIME);

            return $count();
        });
    }
}
