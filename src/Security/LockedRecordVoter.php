<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * A payment or delivery that had consequences can be neither deleted nor
 * changed (D-1, A2).
 *
 * An invoice may name that payment; a customer has the mail about that
 * parcel. A correction is a counter-entry.
 *
 * ── Update: refused only where new values arrive ─────────────────────────
 *
 * Opening the mask asks with no new values and is allowed — it opens
 * read-only (LockedRecordListener), and the reload after the very save that
 * locks the row must not become an error page. A read-only widget submits
 * nothing, so saving that mask asks with nothing new either. What IS
 * refused: new values for a locked row, which is how "Mehrere bearbeiten"
 * (editAll, overrideAll) reached it — the read-only mask only covers
 * act=edit (Fable review of D-1, S2).
 */
abstract class LockedRecordVoter extends AbstractDataContainerVoter
{
    /**
     * The multi-record modes. There a locked row is refused outright, so it
     * drops out of the mask (DC_Table checks each row before showing it)
     * instead of appearing editable and being skipped without a word on save
     * (second Fable review of D-1, S-c).
     */
    private const MULTI_EDIT = ['editAll', 'overrideAll'];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack|null $requests = null,
    ) {
    }

    public function supportsType(string $subjectType): bool
    {
        return DeleteAction::class === $subjectType || UpdateAction::class === $subjectType;
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$action instanceof DeleteAction && !$action instanceof UpdateAction) {
            return true;
        }

        $id = (int) $action->getCurrentId();

        if ($id < 1) {
            return true;
        }

        $locked = $this->connection->fetchOne(\sprintf('SELECT lockedAt FROM %s WHERE id = ?', $this->getTable()), [$id]);

        if (null === $locked || false === $locked) {
            return true;
        }

        if (!$action instanceof UpdateAction || \in_array($this->requests?->getCurrentRequest()?->query->get('act'), self::MULTI_EDIT, true)) {
            return false;
        }

        return !self::changes($action);
    }

    /**
     * Whether the update brings a value that differs from the stored one.
     * tstamp does not count: Contao touches it on every save. Numbers are
     * compared as numbers — the database says "40.0000", a form may say "40".
     */
    private static function changes(UpdateAction $action): bool
    {
        $current = $action->getCurrent() ?? [];

        foreach ($action->getNew() ?? [] as $field => $value) {
            if ('tstamp' === $field) {
                continue;
            }

            $stored = (string) ($current[$field] ?? '');
            $given = (string) $value;

            if (is_numeric($stored) && is_numeric($given) ? !BigDecimal::of($stored)->isEqualTo($given) : $stored !== $given) {
                return true;
            }
        }

        return false;
    }
}
