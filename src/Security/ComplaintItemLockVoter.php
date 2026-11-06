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
 * The lines of a decided complaint are what the decision was made on
 * (block Reklamation, RK-4): no new ones, no changes, no deletions. Its
 * quantities and amounts change through "Reklamation entscheiden" only.
 *
 * An update checks both complaints, the line's and the one it would move to,
 * as at a delivery's items.
 */
final class ComplaintItemLockVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    protected function getTable(): string
    {
        return 'tl_consho_complaint_item';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        $complaints = match (true) {
            $action instanceof CreateAction => [(int) $action->getNewPid()],
            $action instanceof UpdateAction => [(int) $action->getCurrentPid(), (int) $action->getNewPid()],
            $action instanceof DeleteAction => [(int) $action->getCurrentPid()],
            default => [],
        };

        foreach (array_unique($complaints) as $complaintId) {
            if (ComplaintLockVoter::decided($this->connection, $complaintId)) {
                return false;
            }
        }

        return true;
    }
}
