<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Can a customer get in? Three warnings (Nachlese KA5, KA6, E2), each of which
 * once turned a customer away without a
 * single line in the log.
 *
 * - **No registration path** — a shop that sells subscriptions, which need an
 *   account (P12), while no registration module both puts new members into the
 *   customer group and opens their account. Contao creates members disabled;
 *   without the activation mail or "Konten sofort freischalten" they stay so
 *   until the operator notices. Whether the module is placed on a page is not
 *   checked: modules sit in layouts, elements and insert tags, and a guess
 *   would warn falsely more often than it helped.
 *
 *   The same for a shop with guest checkout switched off, where every purchase
 *   needs an account. A warning there too, not a blocker: a shop whose
 *   accounts the operator creates by hand (B2B) needs no registration at all,
 *   and this check cannot tell that from a forgotten module.
 *
 * - **A customer page that locks customers out** — a page with "Meine
 *   Bestellungen" or "Meine Abos" that is protected, its own or inherited from a
 *   parent, without admitting the shop's customer group and every net price
 *   group. Contao knows no "all members", only groups (rollout, "Customer
 *   accounts for subscriptions"), and a net customer is not in the customer
 *   group by necessity.
 *
 * - **No login page** — a start point with such a protected page but no
 *   published 401 page below it. A visitor who is logged out gets Contao's
 *   generic error page instead of the login and the way back.
 *
 * Protection is inherited the way PageModel::loadDetails() inherits it: the
 * nearest protected page on the way up decides, with its groups.
 */
final class CustomerAccessIssueSource implements ConfigurationIssueSource
{
    public const CUSTOMER_ELEMENTS = ['consho_my_orders', 'consho_my_subscriptions'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $shops = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, customerGroup, netPriceGroups, guestCheckout FROM tl_consho_shop ORDER BY id') as $shop) {
            $shops[(int) $shop['id']] = [
                'title' => (string) $shop['title'],
                'customerGroup' => (int) $shop['customerGroup'],
                'guestCheckout' => (bool) $shop['guestCheckout'],
                'admitted' => array_values(array_unique(array_filter(
                    [(int) $shop['customerGroup'], ...array_map('intval', StringUtil::deserialize($shop['netPriceGroups'] ?? null, true))],
                    static fn (int $group): bool => $group > 0,
                ))),
            ];
        }

        if ([] === $shops) {
            return [];
        }

        return [...$this->registration($shops), ...$this->pages($shops)];
    }

    /**
     * @param array<int, array{title: string, customerGroup: int, guestCheckout: bool, admitted: list<int>}> $shops
     *
     * @return list<ConfigurationIssue>
     */
    private function registration(array $shops): array
    {
        // `reg_allowLogin` first (Nachlese): without it
        // Contao creates the member with `login = 0` — opened, in the group,
        // and still unable to sign in, so no subscription can be bought.
        $modules = $this->connection->fetchAllAssociative(
            "SELECT reg_groups, reg_activate, consho_activateAtOnce FROM tl_module WHERE type = 'registration' AND reg_allowLogin = 1",
        );

        $issues = [];
        $subscriptions = $this->shopsSellingSubscriptions();

        foreach ($shops as $shopId => $shop) {
            // Without guest checkout every purchase needs an account, not only
            // a subscription — the wider reason, so it is the one reported.
            if (!$shop['guestCheckout']) {
                $finding = ConfigurationFinding::RegistrationPathMissingNoGuests;
            } elseif (\in_array($shopId, $subscriptions, true)) {
                $finding = ConfigurationFinding::RegistrationPathMissing;
            } else {
                continue;
            }

            foreach ($modules as $module) {
                // The switch opens the account and adds the group itself (KA1, KA3).
                if (!empty($module['consho_activateAtOnce'])) {
                    continue 2;
                }

                $groups = array_map('intval', StringUtil::deserialize($module['reg_groups'] ?? null, true));

                if (!empty($module['reg_activate']) && \in_array($shop['customerGroup'], $groups, true)) {
                    continue 2;
                }
            }

            $issues[] = new ConfigurationIssue($finding, ['shop' => $shop['title']], $shopId);
        }

        return $issues;
    }

    /**
     * @param array<int, array{title: string, customerGroup: int, guestCheckout: bool, admitted: list<int>}> $shops
     *
     * @return list<ConfigurationIssue>
     */
    private function pages(array $shops): array
    {
        $customerPages = $this->customerPages();

        if ([] === $customerPages) {
            return [];
        }

        $pages = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, pid, type, title, protected, '.$this->connection->quoteIdentifier('groups').' AS grp, consho_shop FROM tl_page') as $row) {
            $pages[(int) $row['id']] = $row;
        }

        $groupNames = array_map('strval', $this->connection->fetchAllKeyValue('SELECT id, name FROM tl_member_group'));

        $issues = [];
        $guardedRoots = [];

        foreach ($customerPages as $pageId) {
            $root = null;
            $guard = null;

            // Up to the start point; the first protected page decides.
            for ($id = $pageId, $steps = 0; isset($pages[$id]) && $steps < 100; $id = (int) $pages[$id]['pid'], ++$steps) {
                if (null === $guard && !empty($pages[$id]['protected'])) {
                    $guard = $pages[$id];
                }

                if ('root' === $pages[$id]['type']) {
                    $root = $pages[$id];

                    break;
                }
            }

            $shopId = null === $root ? 0 : (int) $root['consho_shop'];

            if (null === $guard || !isset($shops[$shopId])) {
                continue;
            }

            $guardedRoots[$shopId][(int) $root['id']] = (string) $root['title'];

            $admits = array_map('intval', StringUtil::deserialize($guard['grp'] ?? null, true));
            $missing = array_values(array_diff($shops[$shopId]['admitted'], $admits));

            if ([] === $missing) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::CustomerPageLocksOut,
                [
                    'shop' => $shops[$shopId]['title'],
                    'page' => (string) $pages[$pageId]['title'],
                    'groups' => implode(', ', array_map(static fn (int $group): string => $groupNames[$group] ?? '#'.$group, $missing)),
                ],
                $shopId,
            );
        }

        foreach ($guardedRoots as $shopId => $roots) {
            $without = array_diff_key($roots, array_flip($this->rootsWithLoginPage(array_keys($roots))));

            if ([] !== $without) {
                $issues[] = new ConfigurationIssue(
                    ConfigurationFinding::LoginPageMissing,
                    ['shop' => $shops[$shopId]['title'], 'roots' => implode(', ', $without)],
                    $shopId,
                );
            }
        }

        return $issues;
    }

    /**
     * Published pages carrying a shown customer element, in an article or one
     * level down in a container element.
     *
     * @return list<int>
     */
    private function customerPages(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT p.id FROM tl_page p
               JOIN tl_article a ON a.pid = p.id AND a.published = 1
               JOIN tl_content c ON c.pid = a.id AND c.ptable = 'tl_article' AND c.invisible = 0
              WHERE p.published = 1 AND c.type IN (?)
             UNION
             SELECT DISTINCT p.id FROM tl_page p
               JOIN tl_article a ON a.pid = p.id AND a.published = 1
               JOIN tl_content o ON o.pid = a.id AND o.ptable = 'tl_article' AND o.invisible = 0
               JOIN tl_content c ON c.pid = o.id AND c.ptable = 'tl_content' AND c.invisible = 0
              WHERE p.published = 1 AND c.type IN (?)",
            [self::CUSTOMER_ELEMENTS, self::CUSTOMER_ELEMENTS],
            [ArrayParameterType::STRING, ArrayParameterType::STRING],
        );

        return array_map('intval', $ids);
    }

    /**
     * Contao looks for the 401 page directly below the start point
     * (PageModel::find401ByPid).
     *
     * @param list<int> $roots
     *
     * @return list<int>
     */
    private function rootsWithLoginPage(array $roots): array
    {
        // In its window too, as findFirstPublishedByTypeAndPid() asks
        // (Nachlese) — the full minute, the way Contao compares start/stop.
        $now = intdiv(time(), 60) * 60;

        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT DISTINCT pid FROM tl_page
              WHERE type = 'error_401' AND published = 1 AND pid IN (?)
                AND (start = '' OR start <= ?) AND (stop = '' OR stop > ?)",
            [$roots, $now, $now],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        ));
    }

    /**
     * As CancellationPageIssueSource reads "sells subscriptions": running ones
     * (resting and paused included), or a published main product for sale as a
     * subscription in the shop.
     *
     * @return list<int>
     */
    private function shopsSellingSubscriptions(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT s.id FROM tl_consho_shop s
              WHERE EXISTS (SELECT 1 FROM tl_consho_subscription a WHERE a.shop = s.id AND a.state IN ('active', 'suspended', 'paused'))
                 OR EXISTS (
                    SELECT 1 FROM tl_consho_product p
                     WHERE p.pid = 0 AND p.published = 1 AND p.saleSubscription = 1
                       AND EXISTS (SELECT 1 FROM tl_consho_product_shop ps WHERE ps.pid = p.id AND ps.shop = s.id))
              ORDER BY s.id",
        ));
    }
}
