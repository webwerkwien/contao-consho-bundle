<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementRevocations;

/**
 * The operator's tick "gesperrt" on an entitlement reaches the delivery
 * module (Nachlese E6), like a revocation by withdrawal does.
 *
 * Two steps, because neither callback alone can tell: the save callback still
 * sees the stored value and so knows whether the tick is NEW, but runs before
 * the row is written; onsubmit runs after the write, when the module may read
 * the entitlement as revoked. Unticking tells nobody — there is no
 * counterpart in the interface, and a module re-reads `isActive()` anyway.
 */
final class EntitlementRevokedListener
{
    /** @var array<int, true> */
    private array $newlyRevoked = [];

    public function __construct(private readonly EntitlementRevocations $revocations)
    {
    }

    #[AsCallback(table: 'tl_consho_entitlement', target: 'fields.revoked.save')]
    public function remember(mixed $value, DataContainer $dc): mixed
    {
        $id = (int) $dc->id;

        if ($value && empty($dc->getCurrentRecord()['revoked'])) {
            $this->newlyRevoked[$id] = true;
        }

        return $value;
    }

    #[AsCallback(table: 'tl_consho_entitlement', target: 'config.onsubmit')]
    public function tell(DataContainer $dc): void
    {
        $id = (int) $dc->id;

        if (isset($this->newlyRevoked[$id])) {
            unset($this->newlyRevoked[$id]);
            $this->revocations->revoked([$id]);
        }
    }
}
