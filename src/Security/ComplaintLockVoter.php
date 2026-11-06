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
 * A decided complaint is deleted no more (block Reklamation, P4): from the
 * decision on it counts in the order's claim, its stock and its documents.
 * Its note stays editable.
 */
final class ComplaintLockVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function supportsType(string $subjectType): bool
    {
        return DeleteAction::class === $subjectType;
    }

    protected function getTable(): string
    {
        return 'tl_consho_complaint';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        return !$action instanceof DeleteAction || !self::decided($this->connection, (int) $action->getCurrentId());
    }

    public static function decided(Connection $connection, int $complaintId): bool
    {
        if ($complaintId < 1) {
            return false;
        }

        $decided = $connection->fetchOne('SELECT decidedAt FROM tl_consho_complaint WHERE id = ?', [$complaintId]);

        return null !== $decided && false !== $decided;
    }
}
