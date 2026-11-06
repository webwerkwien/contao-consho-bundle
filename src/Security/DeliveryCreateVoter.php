<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;

/**
 * No new delivery for an order no parcel can concern (DeliveryContents::
 * concernsParcels()): withdrawn, cancelled or shipped in full, or without
 * physical goods. A replacement is created from its complaint (RK-5).
 *
 * Asked while the list draws "Neue Lieferung" and again in
 * DC_Table::create(); the answer is memoised per order for the list.
 */
final class DeliveryCreateVoter extends AbstractDataContainerVoter implements ResetInterface
{
    /**
     * @var array<int, bool>
     */
    private array $concerns = [];

    public function __construct(private readonly DeliveryContents $contents)
    {
    }

    public function supportsType(string $subjectType): bool
    {
        return CreateAction::class === $subjectType;
    }

    public function reset(): void
    {
        $this->concerns = [];
    }

    protected function getTable(): string
    {
        return 'tl_consho_delivery';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$action instanceof CreateAction) {
            return true;
        }

        $order = (int) $action->getNewPid();

        if ($order < 1) {
            return true;
        }

        return $this->concerns[$order] ??= $this->contents->concernsParcels($order);
    }
}
