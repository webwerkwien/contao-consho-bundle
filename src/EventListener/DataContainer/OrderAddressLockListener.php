<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The customer and the addresses of an order stay correctable until a
 * document carries them (Connect-2, E2).
 *
 * A wrong house number is fixed before the parcel goes; afterwards the order
 * and the delivery note or invoice would tell two stories, and an invoice is
 * corrected by a document of its own, not by editing the order. So:
 *
 * - delivery address and the carrier's phone: read-only once a delivery is
 *   recorded or a delivery note issued;
 * - customer and billing address: read-only once an invoice is issued.
 *
 * Read-only rather than refused, as LockedRecordListener does it: a read-only
 * widget is not submitted, so nothing changes, and the status — the one field
 * the operator keeps maintaining — stays editable beside it.
 *
 * Contao honours read-only in every form, but the onload callback below sets
 * it for act=edit only — "edit multiple" and "override all" never got it
 * (CW-6). So the save itself refuses a frozen field too —
 * onbeforesubmit, which every path runs, contao-ai's writer included. In
 * "edit" and "edit multiple" DC_Table hands over the changed fields only, so a
 * frozen value saved unchanged passes; "override all" saves every ticked field
 * and is refused even with the same value.
 */
class OrderAddressLockListener
{
    public const CUSTOMER_FIELDS = [
        'salutation', 'firstname', 'lastname', 'email', 'phone', 'company',
        'billingStreet', 'billingPostal', 'billingCity', 'billingState', 'billingCountry',
    ];

    public const SHIPPING_FIELDS = [
        'shippingCompany', 'shippingFirstname', 'shippingLastname',
        'shippingStreet', 'shippingPostal', 'shippingCity', 'shippingState', 'shippingCountry',
        'shippingPhone',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_consho_order', target: 'config.onload')]
    public function __invoke(DataContainer|null $dc = null): void
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $dc || null === $request || 'edit' !== $request->query->get('act')) {
            return;
        }

        $locked = $this->frozenFields((int) $dc->id);

        if ([] === $locked) {
            return;
        }

        foreach ($locked as $field) {
            if (isset($GLOBALS['TL_DCA']['tl_consho_order']['fields'][$field])) {
                $GLOBALS['TL_DCA']['tl_consho_order']['fields'][$field]['eval']['readonly'] = true;
            }
        }

        $this->framework->getAdapter(Message::class)->addInfo($GLOBALS['TL_LANG']['tl_consho_order']['addressesFrozen'] ?? 'addressesFrozen');
    }

    /**
     * @param array<string, mixed> $values the changed fields
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_order', target: 'config.onbeforesubmit')]
    public function refuseFrozen(array $values, DataContainer $dc): array
    {
        $changed = array_values(array_intersect(array_keys($values), $this->frozenFields((int) $dc->id)));

        if ([] !== $changed) {
            throw new \RuntimeException(\sprintf('%s (%s)', $GLOBALS['TL_LANG']['tl_consho_order']['addressesFrozen'] ?? 'addressesFrozen', implode(', ', $changed)));
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function frozenFields(int $orderId): array
    {
        if ($orderId < 1) {
            return [];
        }

        $locked = [];

        if ($this->hasDocument($orderId, 'invoice')) {
            $locked = [...$locked, ...self::CUSTOMER_FIELDS];
        }

        if ($this->hasDocument($orderId, 'deliveryNote') || false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_delivery WHERE pid = ? LIMIT 1', [$orderId])) {
            $locked = [...$locked, ...self::SHIPPING_FIELDS];
        }

        return $locked;
    }

    private function hasDocument(int $orderId, string $type): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_document WHERE pid = ? AND type = ? LIMIT 1', [$orderId, $type]);
    }
}
