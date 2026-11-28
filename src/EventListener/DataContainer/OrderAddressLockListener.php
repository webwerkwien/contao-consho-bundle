<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;

/**
 * The customer and the addresses of an order stay correctable until a
 * document carries them (Connect-2, E2).
 *
 * A wrong house number is fixed before the parcel goes; afterwards the order
 * and the delivery note or invoice would tell two stories, and an invoice is
 * corrected by a document of its own, not by editing the order. So:
 *
 * - delivery address and the carrier's phone: read-only once a delivery is
 *   recorded or a delivery note issued, or where no physical goods are left
 *   to ship — digital goods, services and vouchers go into no parcel the
 *   address could steer, even while they still wait for their release;
 * - customer and billing address: read-only once an invoice is issued;
 * - the period for the remainder: read-only once the invoice is issued or
 *   nothing is left to ship. It is read for that one invoice only, to state
 *   when the rest of a partial delivery is expected; after it, a change
 *   would have no effect;
 * - everything but the status: read-only while the order is in a status that
 *   locks changes or completes the order. The status itself stays editable:
 *   a refund or chargeback after the completion has to be followed by the
 *   status, and a completion set by mistake has to be undone.
 *
 * Read-only rather than refused, as LockedRecordListener does it: a read-only
 * widget is not submitted, so nothing changes, and the status — the one field
 * the operator keeps maintaining — stays editable beside it.
 *
 * A select is disabled instead: browsers ignore read-only on it. Contao treats
 * both the same and does not submit the widget.
 *
 * The mask also says, on every open, what can be edited there and why the
 * rest is locked — everything else on an order is the state at the purchase
 * and read-only by definition, which a form of greyed fields does not explain.
 *
 * Contao honours read-only in every form, but the onload callback below sets
 * it for act=edit only — "edit multiple" and "override all" never got it.
 * So the save itself refuses a frozen field too —
 * onbeforesubmit, which every path runs, contao-ai's writer included.
 * DC_Table::save() hands over only values that differ from the stored one, so
 * a frozen value saved unchanged passes. The lock follows the status stored
 * before the save: a status changed in the same save takes effect afterwards,
 * as if the two had been saved one after the other.
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

    public const REMAINDER_FIELD = 'remainderDeliveryDays';

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly ContaoFramework $framework,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    #[AsCallback(table: 'tl_consho_order', target: 'config.onload')]
    public function __invoke(DataContainer|null $dc = null): void
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $dc || null === $request || 'edit' !== $request->query->get('act') || (int) $dc->id < 1) {
            return;
        }

        $state = $this->state((int) $dc->id);

        foreach ($this->locked($state) as $field) {
            if (isset($GLOBALS['TL_DCA']['tl_consho_order']['fields'][$field])) {
                $key = 'select' === ($GLOBALS['TL_DCA']['tl_consho_order']['fields'][$field]['inputType'] ?? null) ? 'disabled' : 'readonly';
                $GLOBALS['TL_DCA']['tl_consho_order']['fields'][$field]['eval'][$key] = true;
            }
        }

        $this->framework->getAdapter(Message::class)->addInfo($this->notice($state));
    }

    /**
     * @param array<string, mixed> $values the changed fields
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_order', target: 'config.onbeforesubmit')]
    public function refuseFrozen(array $values, DataContainer $dc): array
    {
        $changed = (int) $dc->id < 1 ? [] : array_values(array_intersect(array_keys($values), $this->locked($this->state((int) $dc->id))));

        if ([] !== $changed) {
            throw new \RuntimeException(\sprintf('%s (%s)', $GLOBALS['TL_LANG']['tl_consho_order']['addressesFrozen'] ?? 'addressesFrozen', implode(', ', $changed)));
        }

        return $values;
    }

    /**
     * @param array{invoiced: bool, delivered: bool, complete: bool, noParcel: bool, held: string|null} $state
     *
     * @return list<string>
     */
    private function locked(array $state): array
    {
        if (null !== $state['held']) {
            return [...self::CUSTOMER_FIELDS, ...self::SHIPPING_FIELDS, self::REMAINDER_FIELD];
        }

        return [
            ...($state['invoiced'] ? self::CUSTOMER_FIELDS : []),
            ...($state['delivered'] || $state['noParcel'] ? self::SHIPPING_FIELDS : []),
            ...($state['invoiced'] || $state['complete'] ? [self::REMAINDER_FIELD] : []),
        ];
    }

    /**
     * What the operator can still change on this order, and why the rest is
     * locked.
     *
     * @param array{invoiced: bool, delivered: bool, complete: bool, noParcel: bool, held: string|null} $state
     */
    private function notice(array $state): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_order'] ?? [];
        $parts = $lang['editNoticeParts'] ?? [];
        $locked = $this->locked($state);
        $editable = [$parts['status'] ?? 'status'];

        if (!\in_array(self::REMAINDER_FIELD, $locked, true)) {
            $editable[] = $parts['remainder'] ?? 'remainder';
        }

        if (!\in_array(self::CUSTOMER_FIELDS[0], $locked, true)) {
            $editable[] = $parts['customer'] ?? 'customer';
        }

        if (!\in_array(self::SHIPPING_FIELDS[0], $locked, true)) {
            $editable[] = $parts['shipping'] ?? 'shipping';
        }

        $last = array_pop($editable);
        // One item takes the singular ("ist" rather than "sind" in German).
        $notice = [] === $editable
            ? \sprintf($lang['editNoticeSingle'] ?? $lang['editNotice'] ?? '%s', $last)
            : \sprintf($lang['editNotice'] ?? '%s', implode(', ', $editable).($lang['editNoticeAnd'] ?? ' & ').$last);

        if (null !== $state['held']) {
            // Shown as HTML; a status title is free text.
            return $notice.' '.\sprintf($lang['editNoticeHeld'] ?? '%s', StringUtil::specialchars($state['held']));
        }

        if ($state['invoiced']) {
            $notice .= ' '.($lang['editNoticeInvoice'] ?? 'editNoticeInvoice');
        }

        if ($state['delivered']) {
            $notice .= ' '.($lang['editNoticeDelivery'] ?? 'editNoticeDelivery');
        } elseif ($state['noParcel']) {
            $notice .= ' '.($lang['editNoticeNoParcel'] ?? 'editNoticeNoParcel');
        }

        if ($state['invoiced']) {
            $notice .= ' '.($lang['editNoticeRemainderInvoiced'] ?? 'editNoticeRemainderInvoiced');
        } elseif ($state['complete']) {
            $notice .= ' '.($lang['editNoticeRemainderComplete'] ?? 'editNoticeRemainderComplete');
        }

        return $notice;
    }

    /**
     * @return array{invoiced: bool, delivered: bool, complete: bool, noParcel: bool, held: string|null}
     */
    private function state(int $orderId): array
    {
        $held = $this->connection->fetchOne(
            'SELECT s.title FROM tl_consho_order o INNER JOIN tl_consho_order_status s ON s.id = o.orderStatus WHERE o.id = ? AND (s.isCompleted = 1 OR s.locksChanges = 1)',
            [$orderId],
        );

        $open = $this->balances->of($orderId)?->openGoods;

        return [
            'invoiced' => $this->hasDocument($orderId, 'invoice'),
            'delivered' => $this->hasDocument($orderId, 'deliveryNote') || false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_delivery WHERE pid = ? LIMIT 1', [$orderId]),
            'complete' => [] === $open,
            'noParcel' => null !== $open && !$this->anyPhysical($orderId, array_keys($open)),
            'held' => false === $held ? null : (string) $held,
        ];
    }

    /**
     * Whether any of these order items is a physical good — the only kind
     * that goes into a parcel.
     *
     * @param list<int> $itemIds
     */
    private function anyPhysical(int $orderId, array $itemIds): bool
    {
        if ([] === $itemIds) {
            return false;
        }

        foreach ($this->connection->fetchAllKeyValue('SELECT id, kind FROM tl_consho_order_item WHERE pid = ?', [$orderId]) as $id => $kind) {
            if (\in_array((int) $id, $itemIds, true) && ProductKind::stored(null === $kind ? null : (string) $kind)->isPhysical()) {
                return true;
            }
        }

        return false;
    }

    private function hasDocument(int $orderId, string $type): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_document WHERE pid = ? AND type = ? LIMIT 1', [$orderId, $type]);
    }
}
