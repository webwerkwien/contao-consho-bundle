<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax\Oss;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Tax\TaxPlace;

/**
 * The supplies to consumers in other member states and their corrections,
 * dated (block R, O2, R7, R14) — what the threshold counts and the quarterly
 * return sums.
 *
 * A supply happens at the EARLIER of full payment and delivery (R14, Art. 63
 * and 65 MwStSystRL): a prepayment makes the tax due when it is received, a
 * delivery on account when it is made. Delivery is the first one reported,
 * read the way the invoice reads its delivery date (DbalDocumentSource);
 * a digital release and a follow-up order report one too. An order paid in
 * part before the delivery counts at the delivery — a simplification, named
 * in the spec.
 *
 * Corrections:
 *  - a withdrawal takes back what its refund document states — a credit
 *    note for a part, a cancellation for the whole —, at its issue, but not
 *    before the supply it corrects;
 *  - a cancellation of the order after the supply takes back what no
 *    withdrawal took back before it, at the time of the cancellation.
 *
 * Which lines count (O2): those to another member state than the shop's,
 * neither under reverse charge nor outside the scope of VAT, and no service
 * (taxed where the shop is). Shipping, fees and reductions count with the
 * order — in an order that also holds a service, a reduction is not split by
 * kind while the switch is off (spec, simplification). A line is an OSS line
 * when it was taxed in the country it went to.
 */
class OssLedger
{
    private const CHUNK = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<int> $shopIds
     *
     * @return list<OssEntry> what happened from $from (inclusive) until $until (exclusive)
     */
    public function entries(array $shopIds, int $from, int $until): array
    {
        if ([] === $shopIds) {
            return [];
        }

        $shopCountries = array_map(
            static fn ($country): string => strtoupper(trim((string) $country)),
            $this->connection->fetchAllKeyValue('SELECT id, country FROM tl_consho_shop WHERE id IN (?)', [$shopIds], [ArrayParameterType::INTEGER]),
        );

        $candidates = $this->connection->fetchFirstColumn(
            'SELECT o.id FROM tl_consho_order o
               JOIN tl_consho_shop s ON s.id = o.shop
              WHERE o.shop IN (?)
                -- Only orders that went abroad: the start page asks this on every call.
                AND EXISTS (SELECT 1 FROM tl_consho_order_item x WHERE x.pid = o.id AND x.destinationCountry <> \'\' AND x.destinationCountry <> s.country)
                AND ((o.paymentSettledAt >= ? AND o.paymentSettledAt < ?)
                  OR (o.cancelledAt >= ? AND o.cancelledAt < ?)
                  OR EXISTS (SELECT 1 FROM tl_consho_delivery d WHERE d.pid = o.id AND d.reportedAt IS NOT NULL
                              AND CASE WHEN d.shippedAt > 0 THEN d.shippedAt ELSE d.reportedAt END >= ?
                              AND CASE WHEN d.shippedAt > 0 THEN d.shippedAt ELSE d.reportedAt END < ?))',
            [$shopIds, $from, $until, $from, $until, $from, $until],
            /*
             * Integers, bound as integers: a CASE has no column affinity, and
             * SQLite compares its number with a parameter bound as text as
             * "never equal" — a delivery-only supply never counted in the
             * tests (review R, found while fixing F11). MariaDB converts.
             */
            [ArrayParameterType::INTEGER, ...array_fill(0, 6, ParameterType::INTEGER)],
        );

        /*
         * The corrections of a withdrawal (review R, F1): a credit note for a
         * part, a CANCELLATION for the whole — told apart from the
         * cancellation of a re-issue (PaymentChange, SubscriptionChargeFallback)
         * by the withdrawal that names it as its refund document. Read for
         * the orders above too, whenever issued: a credit issued before the
         * supply belongs to the supply's period (F11).
         */
        $candidateIds = array_map('intval', $candidates);
        $credits = $this->connection->fetchAllAssociative(
            'SELECT d.id, d.pid, d.documentNumber, d.issuedAt, d.payload FROM tl_consho_document d
               JOIN tl_consho_order o ON o.id = d.pid
              WHERE o.shop IN (?) AND d.type IN (?)
                AND EXISTS (SELECT 1 FROM tl_consho_withdrawal w WHERE w.refundDocument = d.id)
                AND ((d.issuedAt >= ? AND d.issuedAt < ?) OR d.pid IN (?))
              ORDER BY d.issuedAt, d.id',
            [$shopIds, [DocumentType::CreditNote->value, DocumentType::Cancellation->value], $from, $until, [] !== $candidateIds ? $candidateIds : [0]],
            [ArrayParameterType::INTEGER, ArrayParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        $creditsByOrder = [];

        foreach ($credits as $credit) {
            $creditsByOrder[(int) $credit['pid']][] = $credit;
        }

        $orders = $this->orders(array_values(array_unique([...$candidateIds, ...array_keys($creditsByOrder)])));
        $entries = [];
        $within = static fn (int $at): bool => $at >= $from && $at < $until;

        foreach ($orders as $order) {
            $shopCountry = $shopCountries[$order['shop']] ?? '';
            $supplyAt = $order['supplyAt'];

            if (null === $supplyAt) {
                continue;
            }

            if ($within($supplyAt)) {
                $this->add($entries, $order, $order['items'], $shopCountry, false, $supplyAt, $order['invoice'], 1);
            }

            $cancelledAt = $order['cancelledAt'];
            $cancelledAfterSupply = null !== $cancelledAt && $cancelledAt >= $supplyAt;

            foreach ($creditsByOrder[$order['id']] ?? [] as $credit) {
                // Not before the supply it corrects (F11).
                $at = max((int) $credit['issuedAt'], $supplyAt);

                /*
                 * After the cancellation there is nothing left to take back:
                 * it took all of it (second review R, B1). Issued AT the
                 * cancellation it counts, and the cancellation leaves it out
                 * below — the same instant on both sides of one comparison.
                 */
                if ($cancelledAfterSupply && (int) $credit['issuedAt'] > $cancelledAt) {
                    continue;
                }

                if ($within($at)) {
                    $this->add($entries, $order, $this->creditLines($order, $credit), $shopCountry, true, $at, (string) $credit['documentNumber'], 1);
                }
            }

            if ($cancelledAfterSupply && $within($cancelledAt)) {
                $this->add($entries, $order, $order['items'], $shopCountry, true, $cancelledAt, $order['invoice'], -1);

                /*
                 * What a withdrawal took back already stays taken back once
                 * (review R, F2): the cancellation takes only the rest — its
                 * lines, less the credits issued before it.
                 */
                foreach ($creditsByOrder[$order['id']] ?? [] as $credit) {
                    if ((int) $credit['issuedAt'] <= $cancelledAt) {
                        $this->add($entries, $order, $this->creditLines($order, $credit), $shopCountry, true, $cancelledAt, $order['invoice'], -1);
                    }
                }
            }
        }

        usort($entries, static fn (OssEntry $a, OssEntry $b): int => [$a->at, $a->orderId, $a->correction] <=> [$b->at, $b->orderId, $b->correction]);

        return $entries;
    }

    /**
     * @param list<OssEntry>                                                                                                                                                                                                         $entries
     * @param array{id: int, orderNumber: string, shop: int, currency: string, paidAt: int|null, deliveredAt: int|null, supplyAt: int|null, cancelledAt: int|null, destination: string, invoice: string, items: list<array<string, string>>} $order
     * @param list<array<string, string>>                                                                                                                                                                                           $lines
     */
    private function add(array &$entries, array $order, array $lines, string $shopCountry, bool $correction, int $at, string $document, int $sign): void
    {
        foreach ($lines as $line) {
            $entry = $this->entry($order, $line, $shopCountry, $correction, $at, $document, $sign);

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }
    }

    /**
     * The lines of a credit note or cancellation, as the order's lines look:
     * a document names no destination, so the order's.
     *
     * @param array{destination: string} $order
     * @param array<string, mixed>        $credit
     *
     * @return list<array<string, string>>
     */
    private function creditLines(array $order, array $credit): array
    {
        $payload = json_decode((string) ($credit['payload'] ?? ''), true);
        $lines = [];

        foreach (\is_array($payload) && \is_array($payload['items'] ?? null) ? $payload['items'] : [] as $line) {
            if (!\is_array($line)) {
                continue;
            }

            $lines[] = [
                'lineType' => (string) ($line['lineType'] ?? ''),
                'kind' => (string) ($line['kind'] ?? ''),
                'taxCountry' => (string) ($line['taxCountry'] ?? ''),
                'destinationCountry' => $order['destination'],
                'taxRate' => (string) ($line['taxRate'] ?? ''),
                'taxAmount' => (string) ($line['taxAmount'] ?? '0'),
                'lineTotal' => (string) ($line['lineTotal'] ?? '0'),
                'priceSource' => (string) ($line['priceSource'] ?? ''),
                'productName' => (string) ($line['productName'] ?? ''),
            ];
        }

        return $lines;
    }

    /**
     * @param array{id: int, orderNumber: string, shop: int, currency: string, paidAt: int|null, deliveredAt: int|null, supplyAt: int|null, cancelledAt: int|null, destination: string, invoice: string, items: list<array<string, string>>} $order
     * @param array<string, string>                                                                                                                                                                                                      $item
     */
    private function entry(array $order, array $item, string $shopCountry, bool $correction, int $at, string $document, int $sign): OssEntry|null
    {
        $destination = strtoupper(trim($item['destinationCountry'] ?? ''));
        $taxCountry = strtoupper(trim($item['taxCountry'] ?? ''));

        if ('' === $destination || $destination === $shopCountry || !TaxPlace::isEuMemberState($destination) || !TaxPlace::isEuMemberState($shopCountry)) {
            return null;
        }

        $source = $item['priceSource'] ?? '';

        if (PriceSource::ReverseCharge->value === $source || PriceSource::NotTaxable->value === $source) {
            return null;
        }

        $service = 'product' === ($item['lineType'] ?? '') && ProductKind::Service === ProductKind::stored($item['kind'] ?? '');
        $oss = $taxCountry === $destination;

        $gross = BigDecimal::of('' !== ($item['lineTotal'] ?? '') ? $item['lineTotal'] : '0');
        $tax = BigDecimal::of('' !== ($item['taxAmount'] ?? '') ? $item['taxAmount'] : '0');

        return new OssEntry(
            $order['id'],
            $order['orderNumber'],
            $order['shop'],
            $document,
            $correction,
            $at,
            (int) $order['supplyAt'],
            $order['paidAt'],
            $order['deliveredAt'],
            $destination,
            $taxCountry,
            (string) BigDecimal::of('' !== ($item['taxRate'] ?? '') ? $item['taxRate'] : '0')->toScale(4, RoundingMode::HalfUp),
            (string) $gross->minus($tax)->multipliedBy($sign)->toScale(4, RoundingMode::HalfUp),
            (string) $tax->multipliedBy($sign)->toScale(4, RoundingMode::HalfUp),
            $order['currency'],
            $item['productName'] ?? '',
            !$service,
            $oss,
        );
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array<int, array{id: int, orderNumber: string, shop: int, currency: string, paidAt: int|null, deliveredAt: int|null, supplyAt: int|null, cancelledAt: int|null, destination: string, invoice: string, items: list<array<string, string>>}>
     */
    private function orders(array $orderIds): array
    {
        $orders = [];

        foreach (array_chunk($orderIds, self::CHUNK) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT o.id, o.orderNumber, o.shop, o.currency, o.paymentSettledAt, o.cancelledAt,
                        (SELECT MIN(CASE WHEN d.shippedAt > 0 THEN d.shippedAt ELSE d.reportedAt END) FROM tl_consho_delivery d
                          WHERE d.pid = o.id AND d.reportedAt IS NOT NULL) AS deliveredAt,
                        (SELECT MIN(i.documentNumber) FROM tl_consho_document i WHERE i.pid = o.id AND i.type = ?) AS invoice
                   FROM tl_consho_order o WHERE o.id IN (?)',
                [DocumentType::Invoice->value, $chunk],
                [ParameterType::STRING, ArrayParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $paidAt = null === $row['paymentSettledAt'] ? null : (int) $row['paymentSettledAt'];
                $deliveredAt = null === $row['deliveredAt'] ? null : (int) $row['deliveredAt'];
                $known = array_filter([$paidAt, $deliveredAt], static fn (int|null $at): bool => null !== $at && $at > 0);

                $orders[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'orderNumber' => (string) $row['orderNumber'],
                    'shop' => (int) $row['shop'],
                    'currency' => (string) $row['currency'],
                    'paidAt' => $paidAt,
                    'deliveredAt' => $deliveredAt,
                    'supplyAt' => [] === $known ? null : min($known),
                    'cancelledAt' => null === $row['cancelledAt'] ? null : (int) $row['cancelledAt'],
                    'destination' => '',
                    'invoice' => (string) ($row['invoice'] ?? ''),
                    'items' => [],
                ];
            }

            $items = $this->connection->fetchAllAssociative(
                'SELECT pid, lineType, kind, taxCountry, destinationCountry, taxRate, taxAmount, lineTotal, priceSource, productName
                   FROM tl_consho_order_item WHERE pid IN (?) ORDER BY pid, id',
                [$chunk],
                [ArrayParameterType::INTEGER],
            );

            foreach ($items as $item) {
                $orderId = (int) $item['pid'];

                if (!isset($orders[$orderId])) {
                    continue;
                }

                $item = array_map(static fn ($value): string => (string) $value, $item);
                $orders[$orderId]['items'][] = $item;

                if ('' === $orders[$orderId]['destination'] && '' !== $item['destinationCountry']) {
                    $orders[$orderId]['destination'] = strtoupper($item['destinationCountry']);
                }
            }
        }

        return $orders;
    }
}
