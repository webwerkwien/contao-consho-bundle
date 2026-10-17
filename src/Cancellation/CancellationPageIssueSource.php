<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\SetupIssueSource;

/**
 * A shop that sells subscriptions without a reachable "Verträge hier
 * kündigen" (Abo-7, P63; § 312k BGB) — a warning, one per shop, naming its
 * start points.
 *
 * "Sells subscriptions" as SubscriptionBillingIssueSource reads it: running
 * subscriptions (resting ones too), or a published main product with
 * `saleSubscription` assigned to the shop.
 *
 * "Reachable" as the withdrawal function reads it (DbalLegalReadinessIssue-
 * Source): the start point's `consho_cancellationPage` is published and in
 * its window, and carries the element — in an article or one level down in
 * a container element, each shown and in its window. Unlike the withdrawal,
 * a protected page fails whatever the shop's guest checkout: § 312k wants
 * the button "unmittelbar und leicht zugänglich", and a subscription always
 * has an account, so a login is exactly what the law keeps out of the way.
 */
final class CancellationPageIssueSource implements SetupIssueSource
{
    private bool $forSetup = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issuesForSetup(): array
    {
        $copy = clone $this;
        $copy->forSetup = true;

        return $copy->issues();
    }

    public function issues(): array
    {
        // For the setup page every start point with a shop (N1).
        $root = $this->forSetup
            ? '1 = 1'
            : "root.published = 1
                    AND (root.start = '' OR root.start <= :now)
                    AND (root.stop = '' OR root.stop > :now)";

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                "SELECT root.title, s.id AS shop, s.title AS shopTitle
                   FROM tl_page root
                   JOIN tl_consho_shop s ON s.id = root.consho_shop
              LEFT JOIN tl_page target
                     ON target.id = root.consho_cancellationPage
                    AND target.published = 1
                    AND (target.start = '' OR target.start <= :now)
                    AND (target.stop = '' OR target.stop > :now)
                  WHERE root.type IN ('root', 'rootfallback')
                    AND %5\$s
                    AND (
                        EXISTS (SELECT 1 FROM tl_consho_subscription a WHERE a.shop = s.id AND a.state IN ('active', 'suspended', 'paused'))
                        OR EXISTS (
                            SELECT 1 FROM tl_consho_product p
                             WHERE p.pid = 0 AND p.published = 1 AND p.saleSubscription = 1
                               AND EXISTS (SELECT 1 FROM tl_consho_product_shop ps WHERE ps.pid = p.id AND ps.shop = s.id))
                    )
                    AND (
                        target.id IS NULL
                        OR target.protected = 1
                        OR (
                            NOT EXISTS (
                                SELECT 1 FROM tl_content c
                                JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article'
                                WHERE a.pid = target.id AND %1\$s AND c.type = 'consho_cancellation' AND %2\$s
                            )
                            AND NOT EXISTS (
                                SELECT 1 FROM tl_content nested
                                JOIN tl_content outer_element ON outer_element.id = nested.pid AND nested.ptable = 'tl_content'
                                JOIN tl_article a ON a.id = outer_element.pid AND outer_element.ptable = 'tl_article'
                                WHERE a.pid = target.id AND %1\$s AND nested.type = 'consho_cancellation' AND %3\$s AND %4\$s
                            )
                        )
                    )
                  ORDER BY s.id, root.id",
                self::visible('a', 'published = 1'),
                self::visible('c', 'invisible = 0'),
                self::visible('nested', 'invisible = 0'),
                self::visible('outer_element', 'invisible = 0'),
                $root,
            ),
            // The full minute, the way Contao compares start/stop.
            ['now' => (string) (intdiv(time(), 60) * 60)],
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']]['title'] = (string) $row['shopTitle'];
            $byShop[(int) $row['shop']]['roots'][] = (string) $row['title'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $shop) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::CancellationPageMissing,
                ['shop' => $shop['title'], 'roots' => implode(', ', $shop['roots'])],
                $shopId,
            );
        }

        return $issues;
    }

    /**
     * Shown by its own flag and inside its own start/stop window.
     */
    private static function visible(string $alias, string $flag): string
    {
        return \sprintf(
            "%1\$s.%2\$s AND (%1\$s.start = '' OR %1\$s.start <= :now) AND (%1\$s.stop = '' OR %1\$s.stop > :now)",
            $alias,
            $flag,
        );
    }
}
