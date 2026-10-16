<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxClassReferences;

/**
 * A tax class a product or a shipping method still carries cannot be deleted.
 *
 * The open point from C-6, decided in A/C-12: it was written up for the
 * shipping method, but the same hole stood at the product — and a guard that
 * knows only one of them is half an answer. Why it has to be a guard rather
 * than a finding is in TaxClassReferences: deleting the class takes the price
 * away in silence.
 *
 * Like MethodInUseVoter, DeleteAction reaches this voter twice per row: once
 * while the list draws its delete button (DefaultOperationsListener), once in
 * DC_Table::delete(). The answer is memoised per id for the list. Both passes
 * only happen because tl_consho_tax_class names the core's default delete
 * operation instead of writing one out — an array is copied unchanged and
 * carries no permission check, which is what made the delete button of
 * seventeen DCAs a plain link.
 *
 * "Select multiple" → delete skips a class in use silently:
 * DC_Table::deleteAll() catches the AccessDeniedException per id and carries
 * on with the next. Same as for the methods since C-2.
 *
 * The rates are NOT a reason to refuse: they are child rows of the class
 * (ctable) and are meant to go along into the undo bin.
 */
final class TaxClassDeleteVoter extends AbstractDataContainerVoter implements ResetInterface
{
    /**
     * @var array<int, bool>
     */
    private array $inUse = [];

    public function __construct(private readonly TaxClassReferences $references)
    {
    }

    public function supportsType(string $subjectType): bool
    {
        return DeleteAction::class === $subjectType;
    }

    public function reset(): void
    {
        $this->inUse = [];
    }

    protected function getTable(): string
    {
        return 'tl_consho_tax_class';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$action instanceof DeleteAction) {
            return true;
        }

        $id = (int) $action->getCurrentId();

        if ($id < 1) {
            return true;
        }

        $this->inUse[$id] ??= $this->references->isInUse($id);

        return !$this->inUse[$id];
    }
}
