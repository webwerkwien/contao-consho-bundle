<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Members a verified VAT ID put into net price groups (NetGroupAssigner)
 * whose VAT ID has since gone invalid or been removed — and who still hold
 * one of those groups.
 *
 * Nothing takes the groups away by itself: whether the customer stays on
 * trade terms is the operator's call. The finding goes when the groups are
 * removed, the VAT ID is valid again, or the member is saved in the back end
 * (which confirms the groups as the operator's own, MemberVatIdListener).
 *
 * One finding for the installation: members and groups belong to no shop.
 */
final class NetGroupVatIdIssueSource implements ConfigurationIssueSource
{
    private const LISTED = 10;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            "SELECT id, username, firstname, lastname, company, %s AS grp, consho_netGroupsAuto
               FROM tl_member
              WHERE consho_netGroupsAuto IS NOT NULL
                AND (consho_vatId = '' OR consho_vatIdStatus = 'invalid')
              ORDER BY id",
            $this->connection->quoteIdentifier('groups'),
        ));

        $names = [];

        foreach ($rows as $row) {
            $held = array_intersect(
                array_map('intval', StringUtil::deserialize($row['consho_netGroupsAuto'], true)),
                array_map('intval', StringUtil::deserialize($row['grp'], true)),
            );

            if ([] !== array_filter($held, static fn (int $id): bool => $id > 0)) {
                $names[] = self::nameOf($row);
            }
        }

        if ([] === $names) {
            return [];
        }

        $listed = implode(', ', \array_slice($names, 0, self::LISTED));

        return [new ConfigurationIssue(
            ConfigurationFinding::NetGroupVatIdLost,
            [
                'count' => (string) \count($names),
                'members' => \count($names) > self::LISTED ? $listed.' …' : $listed,
            ],
        )];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nameOf(array $row): string
    {
        $person = trim((string) $row['firstname'].' '.(string) $row['lastname']);
        $name = trim((string) $row['company']) ?: $person ?: (string) $row['username'];

        return '' !== $name ? $name : 'ID '.$row['id'];
    }
}
