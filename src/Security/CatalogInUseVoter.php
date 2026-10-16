<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogReferences;

/**
 * A product type, manufacturer, property or value still in use cannot be
 * deleted in the mask (Connect Stufe 2, CW-4b, H4); since CW-5 (I6) neither
 * can a shop, an order status or a mail configuration. Why, and who counts as a
 * user, is in CatalogReferences — which the delete commands ask directly (H5),
 * because on the console no voter is asked.
 *
 * Like TaxClassDeleteVoter: DeleteAction reaches the voter twice per row (the
 * list's delete button, DC_Table::delete()), so the answer is memoised per
 * id; the DCAs name the core's default delete operation, which is what makes
 * the button ask at all. "Select multiple" → delete skips a row in use
 * silently (DC_Table::deleteAll() catches the AccessDeniedException).
 */
abstract class CatalogInUseVoter extends AbstractDataContainerVoter implements ResetInterface
{
    /**
     * @var array<int, bool>
     */
    private array $inUse = [];

    public function __construct(private readonly CatalogReferences $references)
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

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$action instanceof DeleteAction) {
            return true;
        }

        $id = (int) $action->getCurrentId();

        if ($id < 1) {
            return true;
        }

        $this->inUse[$id] ??= $this->references->isInUse($this->getTable(), $id);

        return !$this->inUse[$id];
    }
}
