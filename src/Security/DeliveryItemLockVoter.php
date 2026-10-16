<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The items of a reported delivery are what the customer was told is in the
 * parcel (D-1, A2): no new ones, no changes, no deletions.
 *
 * Unlike the delivery itself, editing IS refused here: an item is never the
 * thing whose saving locks it — the report on its delivery does.
 */
final class DeliveryItemLockVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    protected function getTable(): string
    {
        return 'tl_consho_delivery_item';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        /*
         * An update checks BOTH deliveries: the one the item is in and the
         * one it is moved to. DC_Table::cut() asks UpdateAction with the new
         * pid — checking only the current one let an item be moved INTO a
         * reported parcel (Fable review of D-1, S3).
         */
        $deliveries = match (true) {
            $action instanceof CreateAction => [(int) $action->getNewPid()],
            $action instanceof UpdateAction => [(int) $action->getCurrentPid(), (int) $action->getNewPid()],
            $action instanceof DeleteAction => [(int) $action->getCurrentPid()],
            default => [],
        };

        foreach (array_unique(array_filter($deliveries, static fn (int $id): bool => $id > 0)) as $deliveryId) {
            $locked = $this->connection->fetchOne('SELECT lockedAt FROM tl_consho_delivery WHERE id = ?', [$deliveryId]);

            if (null !== $locked && false !== $locked) {
                return false;
            }
        }

        return true;
    }
}
