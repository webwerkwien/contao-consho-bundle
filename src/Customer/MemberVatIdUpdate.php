<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;

/**
 * Where a member's VAT ID is SET — registration, "Persönliche Daten", the back
 * end: check it (or reuse a result under twelve months, MemberVatId) and add
 * the net price groups (NetGroupAssigner).
 *
 * Never from the checkout: a VAT ID typed there belongs to the order and is
 * not written to the member, so a group from it would rest on an ID the
 * member does not have.
 */
final class MemberVatIdUpdate
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MemberVatId $vatIds,
        private readonly NetGroupAssigner $groups,
    ) {
    }

    public function update(int $memberId, string $requester, int $now): void
    {
        $this->vatIds->ensure($memberId, $requester, false, $now);
        $this->groups->assign($memberId, $now);
    }

    /**
     * The VAT ID of the shop on this start point, '' for none.
     */
    public function requesterForRoot(int $rootId): string
    {
        if ($rootId < 1) {
            return '';
        }

        return (string) $this->connection->fetchOne(
            'SELECT s.vatId FROM tl_page r JOIN tl_consho_shop s ON s.id = r.consho_shop WHERE r.id = ?',
            [$rootId],
        );
    }

    /**
     * The back end has no shop at hand. The member's shop is the one whose
     * customer group the member is in; failing that, a requester is only
     * certain where all shops share one VAT ID. Otherwise none — a simple
     * query still says valid or invalid, it only yields no consultation
     * number, and the checkout asks again with its own shop's ID.
     */
    public function requesterForMember(int $memberId): string
    {
        $groups = $this->connection->fetchOne(
            \sprintf('SELECT %s FROM tl_member WHERE id = ?', $this->connection->quoteIdentifier('groups')),
            [$memberId],
        );
        $groupIds = array_map('intval', StringUtil::deserialize($groups, true));

        $shops = $this->connection->fetchAllAssociative("SELECT customerGroup, vatId FROM tl_consho_shop WHERE vatId != '' ORDER BY id");

        foreach ($shops as $shop) {
            if (\in_array((int) $shop['customerGroup'], $groupIds, true) && (int) $shop['customerGroup'] > 0) {
                return (string) $shop['vatId'];
            }
        }

        $distinct = array_values(array_unique(array_map(
            static fn (array $shop): string => MemberVatId::normalize((string) $shop['vatId']),
            $shops,
        )));

        return 1 === \count($distinct) ? $distinct[0] : '';
    }
}
