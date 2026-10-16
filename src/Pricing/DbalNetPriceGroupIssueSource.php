<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A shop showing net prices to a member group that cannot reach anyone (C20).
 *
 * Contao does not clean up references to a deleted member group, and there is
 * no reason it should: the group is gone, the selection that named it is a
 * blob on another table. PriceAudienceResolver skips ids it cannot resolve —
 * this finding is what makes that skip visible.
 *
 * ⚠️ A group that is merely INACTIVE counts the same, and it took two rounds of
 * the C-4 review to get the whole list: FrontendUser::setUserFromDb() intersects
 * the member's groups with MemberGroupModel::findAllActive(), which drops a
 * group that is disabled, whose start is still ahead or whose stop has passed.
 * None of them ever reaches the resolver; the selection still names them, the
 * display still falls back to gross, and nothing about the cause is visible
 * anywhere.
 *
 * The three differ in how they got there and in nothing else that matters here,
 * so the query below is Contao's own condition rather than a subset of it —
 * anything less would be right about one case and silent about the next.
 *
 * ⚠️ Why it needs saying at all, when nothing breaks: the damage is entirely on
 * the customer's screen. A trade customer who is meant to read net reads gross,
 * their own basket adds up, nothing errors, and the operator has no way of
 * noticing short of logging in as that customer. Findings that nobody can
 * discover by using the shop are exactly the ones the start page is for.
 *
 * One finding per shop, not one for all of them: the operator fixes this in a
 * shop's mask, and a sentence naming three shops has to be read three times to
 * work out which is meant.
 */
final class DbalNetPriceGroupIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            "SELECT id, title, netPriceGroups FROM tl_consho_shop WHERE netPriceGroups IS NOT NULL AND netPriceGroups <> ''",
        );

        if ([] === $shops) {
            return [];
        }

        /*
         * Contao's own condition, copied from MemberGroupModel::findAllActive()
         * — `disable=0` rather than a comparison against '', because the column
         * is a boolean and MySQL reads 0 = '' as true either way.
         *
         * Floored to the minute like Contao does, so a group does not count as
         * expired here a few seconds before the front end agrees.
         */
        $minute = (int) (floor(time() / 60) * 60);

        /** @var list<int> $known */
        $known = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_member_group
             WHERE disable = 0
               AND (start = '' OR start <= :now)
               AND (stop = '' OR stop > :now)",
            ['now' => $minute],
        ));

        $issues = [];

        foreach ($shops as $shop) {
            $selected = array_map('intval', StringUtil::deserialize($shop['netPriceGroups'], true));

            // Ids only: for a deleted group the name went with it, and naming
            // one but not the other would read like two different problems.
            // Sorted so the fingerprint does not depend on the order the
            // operator happened to tick them in.
            $missing = array_values(array_diff($selected, $known));
            sort($missing);

            if ([] === $missing) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::NetPriceGroupMissing,
                [
                    'shop' => (string) $shop['title'],
                    'groups' => implode(', ', $missing),
                ],
            );
        }

        return $issues;
    }
}
