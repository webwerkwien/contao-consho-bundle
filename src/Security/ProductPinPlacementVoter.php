<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;

/**
 * A pin exists only inside a product listing (B-7).
 *
 * `allowedTypes` at the listing is one direction: nothing but pins inside it.
 * The other direction the core does not cover — ContentElementNestingVoter
 * looks only at elements whose ptable is tl_content, so a pin created straight
 * in an article, or nested in an accordion without a type list, passed.
 * Measured against Contao 5.7.
 *
 * Denying CreateAction is enough to take the type out of the choice:
 * ContentElementTypeListener builds the options by asking exactly that, for
 * the parent the editor is working in. UpdateAction catches a type switched
 * later and a pin cut and pasted elsewhere.
 *
 * Reading and deleting stay allowed — a misplaced pin from before this voter
 * must remain removable.
 */
final class ProductPinPlacementVoter extends AbstractDataContainerVoter implements ResetInterface
{
    public const LIST_TYPE = 'consho_product_list';

    /**
     * @var array<int, string>
     */
    private array $parentTypes = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->parentTypes = [];
    }

    protected function getTable(): string
    {
        return 'tl_content';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if ($action instanceof ReadAction || $action instanceof DeleteAction) {
            return true;
        }

        $new = $action->getNew() ?? [];
        $current = $action instanceof UpdateAction ? ($action->getCurrent() ?? []) : [];

        if (DbalPinRepository::TYPE !== ($new['type'] ?? $current['type'] ?? '')) {
            return true;
        }

        $ptable = $new['ptable'] ?? $current['ptable'] ?? null;

        /*
         * Without a parent there is nothing to judge, and the core voters do
         * not judge it either. Saying no here would lock out a create whose
         * context arrives later.
         */
        if (null === $ptable) {
            return true;
        }

        $pid = (int) ($new['pid'] ?? $current['pid'] ?? 0);

        return 'tl_content' === $ptable && $pid > 0 && self::LIST_TYPE === $this->parentType($pid);
    }

    private function parentType(int $pid): string
    {
        return $this->parentTypes[$pid] ??= (string) $this->connection->fetchOne('SELECT type FROM tl_content WHERE id = ?', [$pid]);
    }
}
