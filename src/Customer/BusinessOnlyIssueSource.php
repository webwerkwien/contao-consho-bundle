<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModes;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;

/**
 * Whether a shop that sells to businesses only (CustomerType::Business) is set
 * up that way.
 *
 * The core's only sign of a business customer is a net price group of the
 * shop — given by a verified VAT ID (NetGroupAssigner) or by the operator. A
 * business-only shop is OPEN where someone outside those groups can buy:
 *
 *  - guest checkout is on: a guest is in no group at all;
 *  - an article of the shop has a price for everyone (no customer group) or
 *    for a group that is not a net price group of the shop, in a way it is
 *    sold — once, or by a subscription rule that is itself open: no groups
 *    (everyone with an account) or a group outside the net price groups.
 *
 * Only what the article is sold as counts (SaleModes): a one-time price of an
 * article sold by subscription only is never offered.
 *
 * A warning, never a lock (decision BB6): who is a business is not the core's
 * to decide. Back end only — not a checkout readiness source.
 *
 * Beside it a note where a business-only shop still names a "Vertrag
 * widerrufen" page at a start point: Consho changes no pages.
 */
final class BusinessOnlyIssueSource implements ConfigurationIssueSource
{
    /**
     * Articles named in the finding; the rest are counted.
     */
    private const NAMED = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly SaleModes $saleModes,
        private readonly SubscriptionRules $rules,
    ) {
    }

    public function issues(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            'SELECT id, title, guestCheckout, netPriceGroups FROM tl_consho_shop WHERE customerType = ? ORDER BY id',
            [CustomerType::Business->value],
        );

        if ([] === $shops) {
            return [];
        }

        $issues = [];

        foreach ($shops as $shop) {
            $issue = $this->openShop((int) $shop['id'], (string) $shop['title'], (bool) $shop['guestCheckout'], $shop['netPriceGroups']);

            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        return [...$issues, ...$this->withdrawalPages(array_map(static fn (array $shop): int => (int) $shop['id'], $shops))];
    }

    private function openShop(int $shopId, string $title, bool $guests, mixed $netGroups): ConfigurationIssue|null
    {
        $net = array_fill_keys(array_filter(array_map('intval', StringUtil::deserialize($netGroups, true)), static fn (int $group): bool => $group > 0), true);
        $articles = $this->openArticles($shopId, $net);
        $lang = $GLOBALS['TL_LANG']['CONSHO']['businessOnly'] ?? [];
        $reasons = [];

        if ($guests) {
            $reasons[] = (string) ($lang['guests'] ?? 'guest checkout is on');
        }

        if ([] !== $articles) {
            $named = implode(', ', \array_slice($articles, 0, self::NAMED));
            $more = \count($articles) - self::NAMED;

            $reasons[] = \sprintf(
                (string) ($lang['articles'] ?? 'articles for customers outside the net price groups: %s'),
                $more > 0 ? \sprintf((string) ($lang['andMore'] ?? '%s and %d more'), $named, $more) : $named,
            );
        }

        if ([] === $reasons) {
            return null;
        }

        return new ConfigurationIssue(ConfigurationFinding::BusinessShopOpen, ['shop' => $title, 'reasons' => implode('; ', $reasons)], $shopId);
    }

    /**
     * Titles of the published articles of the shop that someone outside the
     * net price groups can buy.
     *
     * @param array<int, true> $net
     *
     * @return list<string>
     */
    private function openArticles(int $shopId, array $net): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.title, pr.customerGroup, pr.subscriptionRule
               FROM tl_consho_price pr
               JOIN tl_consho_product a ON a.id = pr.pid
               JOIN tl_consho_product m ON m.id = CASE WHEN a.pid > 0 THEN a.pid ELSE a.id END
              WHERE m.published = 1
                AND a.published = 1
                AND (m.start = \'\' OR CAST(m.start AS UNSIGNED) <= :now) AND (m.stop = \'\' OR CAST(m.stop AS UNSIGNED) > :now)
                AND (a.start = \'\' OR CAST(a.start AS UNSIGNED) <= :now) AND (a.stop = \'\' OR CAST(a.stop AS UNSIGNED) > :now)
                AND pr.tstamp > 0
                AND (pr.validFrom IS NULL OR pr.validFrom <= :now)
                AND (pr.validTo IS NULL OR pr.validTo > :now)
                AND EXISTS (SELECT 1 FROM tl_consho_product_shop ps WHERE ps.pid = m.id AND ps.shop = :shop)
              ORDER BY m.title, m.id',
            ['now' => time(), 'shop' => $shopId],
        );

        $open = static fn (int $group): bool => !isset($net[$group]);
        $ruleOpen = [];
        $priceRules = [];
        $found = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if (isset($found[$id]) || !$open((int) $row['customerGroup'])) {
                continue;
            }

            $rule = (int) $row['subscriptionRule'];

            // One look-up per article, not per price row.
            $priceRules[$id] ??= $this->saleModes->of($id)->priceRules();

            if (!\in_array($rule, $priceRules[$id], true)) {
                continue;
            }

            if ($rule > 0) {
                $ruleOpen[$rule] ??= $this->ruleOpen($rule, $open);

                if (!$ruleOpen[$rule]) {
                    continue;
                }
            }

            $found[$id] = (string) $row['title'];
        }

        return array_values($found);
    }

    /**
     * @param \Closure(int): bool $open
     */
    private function ruleOpen(int $ruleId, \Closure $open): bool
    {
        foreach ($this->rules->byIds([$ruleId]) as $rule) {
            return [] === $rule->groups || [] !== array_filter($rule->groups, $open);
        }

        return false;
    }

    /**
     * @param list<int> $shopIds
     *
     * @return list<ConfigurationIssue>
     */
    private function withdrawalPages(array $shopIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id, p.title, p.consho_shop AS shop, s.title AS shopTitle
               FROM tl_page p
               JOIN tl_consho_shop s ON s.id = p.consho_shop
              WHERE p.type IN ('root', 'rootfallback')
                AND p.consho_shop IN (?)
                AND p.consho_withdrawalFormPage > 0
              ORDER BY p.consho_shop, p.id",
            [$shopIds],
            [ArrayParameterType::INTEGER],
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']]['shop'] = (string) $row['shopTitle'];
            $byShop[(int) $row['shop']]['roots'][] = (string) $row['title'];
        }

        $issues = [];

        foreach ($byShop as $shopId => $entry) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::BusinessWithdrawalPageSet, ['shop' => $entry['shop'], 'roots' => implode(', ', $entry['roots'])], $shopId);
        }

        return $issues;
    }
}
