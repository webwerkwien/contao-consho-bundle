<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Privacy;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * D-5 on the start page: a shop without a retention period (L2), and the
 * orders whose data goes within the next 30 days (L5 — said beforehand, so
 * that nobody is surprised by an order that suddenly reads "anonymisiert").
 */
final class RetentionIssueSource implements ConfigurationIssueSource
{
    public const ANNOUNCE_DAYS = 30;

    /** Numbers named in the finding; the count says how many more. */
    private const NAMED = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly RetentionPolicy $policy,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title FROM tl_consho_shop WHERE retentionYears = 0 ORDER BY id') as $shop) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::RetentionPeriodMissing, ['shop' => (string) $shop['title']], (int) $shop['id']);
        }

        $now = null !== $this->clock ? ($this->clock)() : time();
        $byShop = [];

        foreach ($this->policy->dueBetween($now, $now + self::ANNOUNCE_DAYS * 86400) as $order) {
            $byShop[$order['shop']][] = $order['orderNumber'];
        }

        foreach ($byShop as $shopId => $numbers) {
            $title = (string) $this->connection->fetchOne('SELECT title FROM tl_consho_shop WHERE id = ?', [$shopId]);
            $named = \array_slice($numbers, 0, self::NAMED);

            $issues[] = new ConfigurationIssue(ConfigurationFinding::AnonymizationDue, [
                'shop' => $title,
                'count' => (string) \count($numbers),
                'numbers' => implode(', ', $named).(\count($numbers) > self::NAMED ? ', …' : ''),
            ], (int) $shopId);
        }

        return $issues;
    }
}
