<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Versions of order records are history, never a way back (Connect-2, CW-1,
 * E1).
 *
 * Contao's restore writes the whole old row back with a plain UPDATE: the
 * read-only fields, and what Consho set itself — the payment received, the
 * status, the lock. It runs past every check that guards those (the balance
 * check of OrderStatusListener, the voters, LockedRecordListener). In the
 * back end that was open on every order; since contao-ai-cli restores any
 * table that keeps versions (`version restore`), it is open on the command
 * line too.
 *
 * Two doors, two answers:
 *
 * - The back end restores inside DC_Table::edit(), WITHOUT a transaction:
 *   by the time onrestore_version runs, the row is already overwritten. So
 *   the form is refused before that — in onload, which DC_Table runs in its
 *   constructor. "Vergleichen" keeps working; hiding the whole version menu
 *   (as LockedRecordListener does for a locked payment) would take it away.
 * - Every other path — contao-ai's VersionManager restores in a transaction —
 *   is refused in onrestore_version: the exception rolls the UPDATE back, and
 *   contao-ai answers with a refusal.
 *
 * The versions themselves stay: they are the record of who changed what.
 */
class FrozenVersionListener
{
    public const TABLES = [
        'tl_consho_order',
        'tl_consho_order_item',
        'tl_consho_payment',
        'tl_consho_delivery',
        'tl_consho_complaint',
        'tl_consho_entitlement',
        'tl_consho_voucher',
    ];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_consho_order', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_order_item', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_payment', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_delivery', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_complaint', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_entitlement', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_voucher', target: 'config.onload')]
    public function refuseRestoreForm(): void
    {
        $request = $this->requests->getCurrentRequest();

        // DC_Table restores on exactly this form (FORM_SUBMIT tl_version, a POST body field).
        if (null === $request || 'tl_version' !== $request->request->get('FORM_SUBMIT')) {
            return;
        }

        $this->framework->getAdapter(Message::class)->addError(self::message());

        throw new RedirectResponseException($request->getUri());
    }

    /**
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_order', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_order_item', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_payment', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_delivery', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_complaint', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_entitlement', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_voucher', target: 'config.onrestore_version')]
    public function refuseRestore(string $table, int $id, int $version, array $data): void
    {
        // Exactly this class: contao-ai reports it as a refusal, not as a crash
        // (core 250e844) — a subclass would count as a defect there.
        throw new \InvalidArgumentException(\sprintf('%s %d: %s', $table, $id, self::message()));
    }

    private static function message(): string
    {
        return $GLOBALS['TL_LANG']['CONSHO']['versionFrozen'] ?? 'Versions of order records cannot be restored.';
    }
}
