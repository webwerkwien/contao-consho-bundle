<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A payment or delivery that had consequences opens read-only (D-1, A2).
 *
 * Read-only rather than refused: saving a payment is what locks it, and a
 * refused edit would turn the reload after "Speichern" into an error page.
 * A read-only widget is not submitted (Widget: `readonly` sets
 * blnSubmitInput to false), so nothing in the row changes. Deleting is
 * refused by LockedRecordVoter.
 *
 * Contao honours read-only in every form, but the onload callback sets it for
 * act=edit only; "edit multiple" and "override all" never got it (CW-6
 * review, K2). So the save refuses a
 * locked record as well — onbeforesubmit, on every path. The save that locks
 * a record still sees it unlocked: the stored row is asked, not the values.
 */
class LockedRecordListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_delivery', target: 'config.onload')]
    public function __invoke(DataContainer|null $dc = null): void
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $dc || null === $request || 'edit' !== $request->query->get('act') || (int) $dc->id < 1) {
            return;
        }

        $table = $dc->table;
        $locked = $this->connection->fetchOne(\sprintf('SELECT lockedAt FROM %s WHERE id = ?', $table), [(int) $dc->id]);

        if (null === $locked || false === $locked) {
            return;
        }

        foreach ($GLOBALS['TL_DCA'][$table]['fields'] as $name => $field) {
            if (isset($field['inputType'])) {
                $GLOBALS['TL_DCA'][$table]['fields'][$name]['eval']['readonly'] = true;
            }
        }

        /*
         * No version menu either. "Wiederherstellen" writes an old row back
         * with a plain UPDATE, past the voter and past onsubmit — and the old
         * row has lockedAt NULL: the payment was unlocked and changed at once
         * (second Fable review of D-1, B1). DC_Table runs compare and restore
         * only inside `if (!hideVersionMenu)`, so this closes both.
         */
        $GLOBALS['TL_DCA'][$table]['config']['hideVersionMenu'] = true;

        $this->framework->getAdapter(Message::class)->addInfo($GLOBALS['TL_LANG'][$table]['locked'] ?? 'locked');
    }

    /**
     * @param array<string, mixed> $values the changed fields
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_payment', target: 'config.onbeforesubmit')]
    #[AsCallback(table: 'tl_consho_delivery', target: 'config.onbeforesubmit')]
    public function refuseLocked(array $values, DataContainer $dc): array
    {
        $table = (string) $dc->table;
        $locked = $this->connection->fetchOne(\sprintf('SELECT lockedAt FROM %s WHERE id = ?', $table), [(int) $dc->id]);

        if (null !== $locked && false !== $locked) {
            throw new \RuntimeException($GLOBALS['TL_LANG'][$table]['locked'] ?? 'locked');
        }

        return $values;
    }
}
