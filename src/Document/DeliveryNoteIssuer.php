<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Order\NumberAllocatorInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;

/**
 * The delivery note of a parcel (dev#2, decisions Michael 2026-10-21).
 *
 * `DocumentType::DeliveryNote` and its number range existed since A-7, but
 * nothing issued one: block R listed delivery notes under "not in this block",
 * and no block after it picked them up.
 *
 *  - One per delivery, issued when it is reported "Als versandt melden" — a
 *    partial shipment is a delivery like any other and gets its own note,
 *    with its own number.
 *  - Only where the shop has a number range "Lieferscheinnummer". The range
 *    IS the switch: a shop without one gets no note and no error, and the
 *    shops set up before dev#2 stay as they were. The setup does not create
 *    the range.
 *  - Physical goods only. A digital line is released, not packed (F-4), and
 *    a parcel carrying nothing physical — the electronic release, a
 *    subscription's follow-up — gets no note.
 *  - What follows separately: the physical goods still open after this
 *    parcel, from the same balance the "shipped" mail reads (`complete`).
 *    Frozen with the note; the next parcel gets a note of its own.
 *  - No prices, no cancellation, no correction: a reported delivery is
 *    locked (A2), so there is nothing to correct.
 *
 * Called inside the report's transaction, like the invoice issued on
 * shipment; the caller archives after the commit.
 */
class DeliveryNoteIssuer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentRepositoryInterface $repository,
        private readonly NumberAllocatorInterface $allocator,
        private readonly DocumentPayloadBuilder $payloadBuilder,
        private readonly DocumentSourceInterface $source,
    ) {
    }

    /**
     * @param array<int, string> $openGoods order item id => quantity still open AFTER this parcel
     *
     * @return int|null the delivery note — the existing one if the delivery has one —, null where none is issued
     */
    public function issue(int $deliveryId, array $openGoods): int|null
    {
        $delivery = $this->connection->fetchAssociative('SELECT pid, shippedAt, carrier, trackingNumber FROM tl_consho_delivery WHERE id = ?', [$deliveryId]);

        if (false === $delivery) {
            return null;
        }

        $existing = $this->connection->fetchOne('SELECT id FROM tl_consho_document WHERE delivery = ? AND type = ?', [$deliveryId, DocumentType::DeliveryNote->value]);

        if (false !== $existing) {
            return (int) $existing;
        }

        $orderId = (int) $delivery['pid'];
        $order = $this->repository->findOrderRow($orderId);
        $shopId = (int) ($order['shop'] ?? 0);

        if ([] === $order || !$this->issuesDeliveryNotes($shopId)) {
            return null;
        }

        $goods = $this->physicalGoods($orderId);
        $parcel = array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT orderItem, SUM(quantity) FROM tl_consho_delivery_item WHERE pid = ? GROUP BY orderItem',
            [$deliveryId],
        ));

        $lines = $this->lines($goods, $parcel);

        if ([] === $lines) {
            return null;
        }

        $shippedAt = (int) $delivery['shippedAt'];

        $payload = $this->payloadBuilder->deliveryNote(
            $order,
            $this->source->contextFor($order),
            $lines,
            $this->lines($goods, $openGoods),
            [
                // A calendar day, in the zone Contao runs in — the day the
                // operator typed, printed as such (InvoiceView reads it in UTC).
                'shippedAt' => $shippedAt > 0 ? date('Y-m-d', $shippedAt) : '',
                'carrier' => (string) $delivery['carrier'],
                'trackingNumber' => (string) $delivery['trackingNumber'],
            ],
        );

        // Checked first, numbered last (DocumentIssuer): a note that is not
        // issued must not burn a number.
        $number = $this->allocator->next($shopId, DocumentType::DeliveryNote->numberRange());
        $documentId = $this->repository->insert($orderId, $shopId, DocumentType::DeliveryNote, $number, time(), 0, $payload);
        $this->connection->update('tl_consho_document', ['delivery' => $deliveryId], ['id' => $documentId]);

        return $documentId;
    }

    private function issuesDeliveryNotes(int $shopId): bool
    {
        return $shopId > 0 && false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_number_range WHERE pid = ? AND type = ?',
            [$shopId, DocumentType::DeliveryNote->numberRange()],
        );
    }

    /**
     * The order's physical product lines, in the order of the order.
     *
     * @return array<int, array{productName: string, sku: string}>
     */
    private function physicalGoods(int $orderId): array
    {
        $goods = [];

        foreach ($this->repository->findOrderItemRows($orderId) as $row) {
            if (OrderLineType::stored($row['lineType'] ?? null)->isGoods() && ProductKind::stored($row['kind'] ?? null)->isPhysical()) {
                $goods[(int) $row['id']] = ['productName' => (string) ($row['productName'] ?? ''), 'sku' => (string) ($row['sku'] ?? '')];
            }
        }

        return $goods;
    }

    /**
     * @param array<int, array{productName: string, sku: string}> $goods
     * @param array<int, string>                                  $quantities order item id => quantity
     *
     * @return list<array{productName: string, sku: string, quantity: string}>
     */
    private function lines(array $goods, array $quantities): array
    {
        $lines = [];

        foreach ($goods as $itemId => $item) {
            $quantity = $quantities[$itemId] ?? '0';

            if (is_numeric($quantity) && BigDecimal::of($quantity)->isPositive()) {
                // Four places whatever the driver returns (MariaDB "2.0000",
                // SQLite "2"), as the column holds it; the view formats.
                $lines[] = $item + ['quantity' => (string) BigDecimal::of($quantity)->toScale(4)];
            }
        }

        return $lines;
    }
}
