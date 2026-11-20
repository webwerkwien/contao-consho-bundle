<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\BackendDate;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * The price offers the operator should know about (Abo-10, P129), per shop:
 *
 *  - open offers, with each deadline — a note: the customer answers, or the
 *    deadline settles it (P122);
 *  - an accepted offer whose confirmation did not go out — a warning, while
 *    the new price is not yet effective: the customer has nothing that shows
 *    the agreement. A declined offer, or a consumer's lapse, ends in a
 *    cancellation, and its confirmation is CancellationNotConfirmed's.
 *
 * An offer's own mail needs no finding: one that did not leave is taken back
 * and tried again (P135). An ended subscription has nothing left to tell.
 *
 * What does need one is a mail that cannot leave at all: a published start
 * point of a shop with subscriptions that can get an offer (running, no known
 * end, renewing) that names no published "Meine Abos" page — a warning. The customer answers there (P119), so without it
 * no offer goes out, and the subscriptions run on at their old price.
 */
final class SubscriptionPriceOfferIssueSource implements ConfigurationIssueSource
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function issues(): array
    {
        $now = null === $this->clock ? time() : ($this->clock)();

        $open = $this->byShop(
            "SELECT s.shop, sh.title, s.subscriptionNumber, p.deadlineAt
               FROM tl_consho_subscription_price p
               JOIN tl_consho_subscription s ON s.id = p.pid
               JOIN tl_consho_shop sh ON sh.id = s.shop
              WHERE p.state = 'open' AND p.mailSentAt > 0 AND s.state <> 'ended'
              ORDER BY s.shop, p.deadlineAt, p.id",
            [],
        );

        $unconfirmed = $this->byShop(
            "SELECT s.shop, sh.title, s.subscriptionNumber
               FROM tl_consho_subscription_price p
               JOIN tl_consho_subscription s ON s.id = p.pid
               JOIN tl_consho_shop sh ON sh.id = s.shop
              WHERE p.state = 'accepted' AND p.decrease = 0 AND p.answerMailSentAt = 0 AND p.effectiveAt > ? AND s.state <> 'ended'
              ORDER BY s.shop, p.effectiveAt, p.id",
            [$now],
        );

        $issues = [];

        foreach ($open as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::PriceOffersOpen, [
                'shop' => (string) $entries[0]['title'],
                'count' => (string) \count($entries),
                'numbers' => implode(', ', array_map(static fn (array $e): string => \sprintf('%s → %s', $e['subscriptionNumber'], BackendDate::format((int) $e['deadlineAt'])), $entries)),
            ], $shopId);
        }

        foreach ($unconfirmed as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::PriceAgreementNotConfirmed, [
                'shop' => (string) $entries[0]['title'],
                'count' => (string) \count($entries),
                'numbers' => implode(', ', array_map(static fn (array $e): string => (string) $e['subscriptionNumber'], $entries)),
            ], $shopId);
        }

        foreach ($this->rootsWithoutPage() as $shopId => $entries) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::SubscriptionsPageMissing, [
                'shop' => (string) $entries[0]['shopTitle'],
                'roots' => implode(', ', array_map(static fn (array $e): string => (string) $e['title'], $entries)),
            ], $shopId);
        }

        return $issues;
    }

    /**
     * @return array<int, non-empty-list<array<string, mixed>>>
     */
    private function rootsWithoutPage(): array
    {
        // The full minute, the way Contao compares start/stop.
        $now = (string) (intdiv(null === $this->clock ? time() : ($this->clock)(), 60) * 60);

        return $this->byShop(
            "SELECT s.id AS shop, s.title AS shopTitle, root.title
               FROM tl_page root
               JOIN tl_consho_shop s ON s.id = root.consho_shop
          LEFT JOIN tl_page target
                 ON target.id = root.consho_subscriptionsPage
                AND target.published = 1
                AND (target.start = '' OR target.start <= :now)
                AND (target.stop = '' OR target.stop > :now)
              WHERE root.type IN ('root', 'rootfallback')
                AND root.published = 1
                AND (root.start = '' OR root.start <= :now)
                AND (root.stop = '' OR root.stop > :now)
                AND target.id IS NULL
                -- Only subscriptions that can get an offer (SubscriptionPriceCheck::candidate()).
                AND EXISTS (SELECT 1 FROM tl_consho_subscription a
                             WHERE a.shop = s.id AND a.state IN ('active', 'suspended', 'paused')
                               AND (a.endsAt IS NULL OR a.endsAt = 0) AND a.renewedBy = 0
                               AND (a.renewal = 'sameTerm' OR (a.renewal = 'indefinite' AND a.billingInterval = 1)))
              ORDER BY s.id, root.id",
            ['now' => $now],
        );
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return array<int, non-empty-list<array<string, mixed>>>
     */
    private function byShop(string $sql, array $params): array
    {
        $byShop = [];

        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $byShop[(int) $row['shop']][] = $row;
        }

        return $byShop;
    }
}
