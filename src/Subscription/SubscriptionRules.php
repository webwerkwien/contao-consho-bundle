<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads subscription rules (Abo-2).
 *
 * 🔴 `groups` is a reserved word in MySQL 8, and Doctrine does not quote
 * reserved names on its own — so the column goes
 * through quoteIdentifier(), here and anywhere else it is named.
 */
class SubscriptionRules
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, SubscriptionRule> in the order asked for; unknown ids drop out
     */
    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT id, title, termLength, termUnit, billingInterval, renewal, noticeDays, reminderDays, graceDays, pauseMonths, %s AS ruleGroups
                   FROM tl_consho_subscription_rule WHERE id IN (?)',
                $this->connection->quoteIdentifier('groups'),
            ),
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = new SubscriptionRule(
                (int) $row['id'],
                (string) $row['title'],
                (int) $row['termLength'],
                (string) $row['termUnit'],
                (int) $row['billingInterval'],
                RenewalKind::tryFrom((string) $row['renewal']) ?? RenewalKind::Ends,
                (int) $row['noticeDays'],
                (int) $row['reminderDays'],
                (int) $row['graceDays'],
                array_values(array_filter(
                    array_map('intval', StringUtil::deserialize($row['ruleGroups'], true)),
                    static fn (int $id): bool => $id > 0,
                )),
                (int) ($row['pauseMonths'] ?? 0),
            );
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[$id] = $byId[$id];
            }
        }

        return $ordered;
    }
}
