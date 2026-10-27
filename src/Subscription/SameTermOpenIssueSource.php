<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A rule "verlängert sich jeweils um die volle Mindestlaufzeit" open beyond
 * business customers (variant A, decision A1).
 *
 * Until variant A the core held such a rule back from consumers by itself —
 * a consumer's contract that renews for another fixed year is what § 309
 * Nr. 9 lit. b BGB forbids in general terms. Not automatically any more:
 * the operator limits such yearly plans to business customers, and a note
 * rather than a lock says so — an operator in another country may mean it.
 *
 * Open means: no customer groups (everyone with an account), or a group that
 * no shop marks as a net price group — the core's only sign of a business
 * customer (PriceAudience). A note, not a warning.
 */
final class SameTermOpenIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionRules $rules,
    ) {
    }

    public function issues(): array
    {
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_subscription_rule WHERE renewal = ? ORDER BY id',
            [RenewalKind::SameTerm->value],
        ));

        if ([] === $ids) {
            return [];
        }

        $business = [];

        foreach ($this->connection->fetchFirstColumn('SELECT netPriceGroups FROM tl_consho_shop WHERE netPriceGroups IS NOT NULL') as $groups) {
            foreach (StringUtil::deserialize($groups, true) as $group) {
                $business[(int) $group] = true;
            }
        }

        $issues = [];

        foreach ($this->rules->byIds($ids) as $rule) {
            $open = [] === $rule->groups || [] !== array_filter($rule->groups, static fn (int $group): bool => !isset($business[$group]));

            if ($open) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::SubscriptionSameTermOpen, ['rule' => $rule->title]);
            }
        }

        return $issues;
    }
}
