<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds a member with a verified VAT ID to the net price groups of every shop
 * whose switch says so (tl_consho_shop.vatIdNetGroups).
 *
 * Members and groups belong to the installation, not to a shop — hence the
 * union over all shops with the switch on, not the groups of the shop at
 * hand.
 *
 * Only ever adds. A VAT ID gone invalid or removed takes nothing away: the
 * groups may carry prices and discounts the operator granted on other
 * grounds, and an automatism that withdraws them would decide what only the
 * operator can. What was added is listed on the member (consho_netGroupsAuto),
 * a group the member already had is not — that one was the operator's.
 */
final class NetGroupAssigner
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'monolog.logger.contao.general')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return list<int> the groups added, empty where nothing changed
     */
    public function assign(int $memberId, int $now): array
    {
        $groupsColumn = $this->connection->quoteIdentifier('groups');
        $target = $this->targetGroups();

        /*
         * Read, add, and write only if nobody wrote in between: the list is
         * stored whole, and an operator saving the member at the same moment
         * would otherwise lose the group they just gave — the one thing this
         * class promises never to do. A lost race reads again; three are
         * plenty for a member saved by hand.
         */
        $written = 0;
        $missing = [];

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $member = $this->connection->fetchAssociative(
                \sprintf('SELECT %s AS grp, consho_vatIdStatus, consho_netGroupsAuto FROM tl_member WHERE id = ?', $groupsColumn),
                [$memberId],
            );

            if (false === $member || 'valid' !== $member['consho_vatIdStatus']) {
                return [];
            }

            $current = self::idsOf($member['grp']);
            $missing = array_values(array_diff($target, $current));

            if ([] === $missing) {
                return [];
            }

            $auto = array_values(array_unique([...self::idsOf($member['consho_netGroupsAuto']), ...$missing]));

            $written = $this->connection->executeStatement(
                \sprintf(
                    'UPDATE tl_member SET %1$s = ?, consho_netGroupsAuto = ?, consho_netGroupsAutoAt = ?, tstamp = ?
                      WHERE id = ? AND (%1$s = ? OR (%1$s IS NULL AND ? IS NULL))',
                    $groupsColumn,
                ),
                [
                    // Contao stores the ids as strings ("checkboxWizard"); keep its shape.
                    serialize(array_map('strval', [...$current, ...$missing])),
                    serialize(array_map('strval', $auto)),
                    $now,
                    $now,
                    $memberId,
                    $member['grp'],
                    $member['grp'],
                ],
            );

            if ($written > 0) {
                break;
            }
        }

        if ($written < 1) {
            // Nothing lost, nothing given: the next check of this member tries again.
            $this->logger->warning(\sprintf('Member ID %d was not added to the net price groups: the record changed during three attempts', $memberId));

            return [];
        }

        $this->logger->info(\sprintf(
            'Member ID %d added to the net price groups %s for a verified VAT ID',
            $memberId,
            implode(', ', $missing),
        ));

        return $missing;
    }

    /**
     * The net price groups of all shops with the switch on — only groups that
     * still exist: a group deleted under "Members → Groups" stays behind in
     * the shop's field.
     *
     * @return list<int>
     */
    private function targetGroups(): array
    {
        $ids = [];

        foreach ($this->connection->fetchFirstColumn('SELECT netPriceGroups FROM tl_consho_shop WHERE vatIdNetGroups = 1') as $value) {
            $ids = [...$ids, ...self::idsOf($value)];
        }

        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            return [];
        }

        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_member_group WHERE id IN (?) ORDER BY id',
            [$ids],
            [ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @return list<int>
     */
    private static function idsOf(mixed $serialized): array
    {
        return array_values(array_filter(
            array_map('intval', StringUtil::deserialize($serialized, true)),
            static fn (int $id): bool => $id > 0,
        ));
    }
}
