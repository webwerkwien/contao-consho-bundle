<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Doctrine\DBAL\Connection;

/**
 * Looks up an address that a product used to answer on.
 *
 * ── Why the host matters ──────────────────────────────────────────────────
 *
 * The same path can exist on two sites of one installation — /produkt/x.html
 * under a German root page and under a French one. The table is keyed per root
 * page for exactly that reason, so the lookup has to say which site is asking.
 *
 * A root page without a domain (`dns = ''`) answers for every host, which is
 * Contao's own rule. When both a host-specific and a domain-less site match,
 * the host-specific one wins — otherwise a catch-all site would quietly take
 * over addresses that belong to a configured domain.
 */
final class DbalRedirectResolver implements RedirectResolverInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $path, string $host): ?RedirectTarget
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT r.productId, r.pid, root.consho_shop, root.language
                FROM tl_consho_url_redirect r
                JOIN tl_page root ON root.id = r.pid
                WHERE r.oldPath = :path
                  AND (root.dns = '' OR root.dns = :host)
                ORDER BY CASE WHEN root.dns = :host THEN 0 ELSE 1 END, r.id
                SQL,
            ['path' => $path, 'host' => $host],
        );

        if (false === $row) {
            return null;
        }

        $shopId = (int) ($row['consho_shop'] ?? 0);

        if ($shopId < 1) {
            // The site stopped being a shop. Its old product addresses are not
            // ours to redirect any more.
            return null;
        }

        return new RedirectTarget((int) $row['productId'], (int) $row['pid'], $shopId, (string) ($row['language'] ?? ''));
    }
}
