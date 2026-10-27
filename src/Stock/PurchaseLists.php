<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * "Einkauf" and "Einkaufslisten" (Block G, G-7, E1–E4).
 *
 * ── The proposal ─────────────────────────────────────────────────────────
 *
 * Every article kept in stock by hand (no key pools, E4) at or below its
 * Meldebestand — `stockWarning`, the same field that tells the customer
 * "nur noch wenige" (L2: "das selbe Feld, mit unterschiedlichen Auswirkungen").
 * Proposed is what brings it to its Sollbestand, less what is already on
 * order (E1), rounded up to whole packs (E2). Without a Sollbestand: one pack.
 *
 * ── Ordered ──────────────────────────────────────────────────────────────
 *
 * What open and partly received lines still wait for. A line marked
 * "nicht lieferbar" waits for nothing any more (E3) — its article comes back
 * in the next proposal.
 *
 * ── Received ─────────────────────────────────────────────────────────────
 *
 * Booked into stock as a movement "receipt" with the list's id, never more
 * than the line still waits for.
 */
class PurchaseLists
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StockBooking $booking,
    ) {
    }

    /**
     * Pieces on order per article (E1).
     *
     * @param list<int> $articles
     *
     * @return array<int, BigDecimal>
     */
    public function ordered(array $articles): array
    {
        if ([] === $articles) {
            return [];
        }

        $ordered = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT article, quantity, received FROM tl_consho_purchase_item WHERE article IN (?) AND state IN ('open', 'partial')",
            [$articles],
            [ArrayParameterType::INTEGER],
        ) as $row) {
            $rest = BigDecimal::of(StockBooking::scaled($row['quantity']))->minus(BigDecimal::of(StockBooking::scaled($row['received'])));
            $article = (int) $row['article'];
            $ordered[$article] = ($ordered[$article] ?? BigDecimal::zero())->plus($rest->isNegative() ? BigDecimal::zero() : $rest);
        }

        return $ordered;
    }

    /**
     * Every article that can go on a purchase list — kept in stock by hand —
     * with what the form shows. `proposed` is null where the article is not
     * at or below its Meldebestand (it can still be added by hand).
     *
     * @return list<array{article: int, title: string, sku: string, supplier: string, supplierSku: string, packUnit: int, stock: string, ordered: string, warning: string, target: string, proposed: string|null}>
     */
    public function candidates(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.article, s.quantity, a.title AS articleTitle, a.pid, a.sku, a.supplierSku,
                    m.title AS mainTitle, m.supplier, m.packUnit, m.stockWarning, m.stockTarget
               FROM tl_consho_stock s
               JOIN tl_consho_product a ON a.id = s.article
               JOIN tl_consho_product m ON m.id = s.main
              WHERE s.active = 1 AND s.source = 'manual'
              ORDER BY m.supplier, m.title, a.sorting, a.id",
        );

        $ordered = $this->ordered(array_map(static fn (array $row): int => (int) $row['article'], $rows));
        $lines = [];

        foreach ($rows as $row) {
            $article = (int) $row['article'];
            $stock = BigDecimal::of(StockBooking::scaled($row['quantity']));
            $onOrder = $ordered[$article] ?? BigDecimal::zero();
            $warning = BigDecimal::of(StockBooking::scaled($row['stockWarning']));
            $target = BigDecimal::of(StockBooking::scaled($row['stockTarget']));
            $pack = max(1, (int) $row['packUnit']);

            $lines[] = [
                'article' => $article,
                'title' => self::title((string) $row['mainTitle'], (int) $row['pid'] > 0 ? (string) $row['articleTitle'] : ''),
                'sku' => (string) $row['sku'],
                'supplier' => (string) $row['supplier'],
                'supplierSku' => (string) $row['supplierSku'],
                'packUnit' => $pack,
                'stock' => (string) $stock->toScale(4),
                'ordered' => (string) $onOrder->toScale(4),
                'warning' => (string) $warning->toScale(4),
                'target' => (string) $target->toScale(4),
                'proposed' => self::proposal($stock, $onOrder, $warning, $target, $pack),
            ];
        }

        return $lines;
    }

    /**
     * Saves the list as it is handed out (L6) — lines with nothing to order
     * are left out.
     *
     * @param array<int, string> $quantities article id => pieces
     *
     * @return int the list's id, 0 when nothing was on it
     */
    public function create(array $quantities, int $user, int $now): int
    {
        $candidates = [];

        foreach ($this->candidates() as $line) {
            $candidates[$line['article']] = $line;
        }

        $lines = [];

        foreach ($quantities as $article => $quantity) {
            $amount = BigDecimal::of(StockBooking::scaled($quantity));

            if (isset($candidates[$article]) && $amount->isPositive()) {
                $lines[] = [$candidates[$article], $amount];
            }
        }

        if ([] === $lines) {
            return 0;
        }

        return $this->connection->transactional(function () use ($lines, $user, $now): int {
            $suppliers = array_values(array_unique(array_filter(array_map(static fn (array $line): string => $line[0]['supplier'], $lines))));

            $this->connection->insert('tl_consho_purchase_list', [
                'tstamp' => $now,
                'createdAt' => $now,
                'user' => $user,
                'suppliers' => mb_substr(implode(', ', $suppliers), 0, 255),
                'lineCount' => \count($lines),
                'state' => 'open',
            ]);

            $listId = (int) $this->connection->lastInsertId();
            $this->connection->update('tl_consho_purchase_list', ['number' => \sprintf('EK-%04d', $listId)], ['id' => $listId]);

            foreach ($lines as [$line, $amount]) {
                $this->connection->insert('tl_consho_purchase_item', [
                    'pid' => $listId,
                    'tstamp' => $now,
                    'article' => $line['article'],
                    'title' => mb_substr($line['title'], 0, 255),
                    'sku' => $line['sku'],
                    'supplier' => $line['supplier'],
                    'supplierSku' => $line['supplierSku'],
                    'packUnit' => $line['packUnit'],
                    'quantity' => (string) $amount->toScale(4),
                    'received' => '0.0000',
                    'state' => 'open',
                ]);
            }

            return $listId;
        });
    }

    /**
     * The lines of a list, by supplier — for the output and the receipt.
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $listId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_consho_purchase_item WHERE pid = ? ORDER BY supplier, id',
            [$listId],
        );
    }

    /**
     * Records what came (E3) and books it into stock.
     *
     * @param array<int, string> $received    item id => pieces that came now
     * @param array<int, bool>   $unavailable item id => the supplier cannot deliver the rest
     *
     * @return string|null why it was refused, or null
     */
    public function receive(int $listId, array $received, array $unavailable, int $user, int $now): string|null
    {
        return $this->connection->transactional(function () use ($listId, $received, $unavailable, $user, $now): string|null {
            $items = $this->connection->fetchAllAssociativeIndexed(
                'SELECT id, article, quantity, received, state FROM tl_consho_purchase_item WHERE pid = ? FOR UPDATE',
                [$listId],
            );

            foreach ($received as $itemId => $value) {
                if (!isset($items[$itemId])) {
                    return 'invalidItem';
                }

                try {
                    $amount = BigDecimal::of('' === trim($value) ? '0' : str_replace(',', '.', trim($value)));
                } catch (MathException) {
                    return 'invalidQuantity';
                }

                $item = $items[$itemId];
                $rest = BigDecimal::of(StockBooking::scaled($item['quantity']))->minus(BigDecimal::of(StockBooking::scaled($item['received'])));

                if ($amount->isNegative() || $amount->isGreaterThan($rest) || (\in_array($item['state'], ['received', 'unavailable'], true) && !$amount->isZero())) {
                    return 'invalidQuantity';
                }

                // "Rest nicht lieferbar" with the whole rest entered as come
                // contradicts itself (G-7): the prefilled field
                // would have booked goods that never came.
                if (($unavailable[$itemId] ?? false) && $rest->isPositive() && $amount->isEqualTo($rest)) {
                    return 'unavailableWithRest';
                }

                // A line whose article keeps no stock row cannot be booked
                // (G-7) — said, not silently marked as received.
                if ($amount->isPositive() && false === $this->connection->fetchOne('SELECT id FROM tl_consho_stock WHERE article = ?', [(int) $item['article']])) {
                    return 'noStock';
                }
            }

            foreach ($items as $itemId => $item) {
                $amount = BigDecimal::of(StockBooking::scaled('' === trim($received[$itemId] ?? '') ? '0' : str_replace(',', '.', trim($received[$itemId]))));

                if (\in_array($item['state'], ['received', 'unavailable'], true)) {
                    continue;
                }

                $total = BigDecimal::of(StockBooking::scaled($item['received']))->plus($amount);

                if ($amount->isPositive()) {
                    $stockId = $this->connection->fetchOne('SELECT id FROM tl_consho_stock WHERE article = ?', [(int) $item['article']]);

                    if (false !== $stockId) {
                        $this->booking->book((int) $stockId, $amount, StockReason::Receipt, $now, $user, purchaseList: $listId);
                    }
                }

                $state = match (true) {
                    $total->isGreaterThanOrEqualTo(BigDecimal::of(StockBooking::scaled($item['quantity']))) => 'received',
                    $unavailable[$itemId] ?? false => 'unavailable',
                    $total->isPositive() => 'partial',
                    default => 'open',
                };

                $this->connection->update('tl_consho_purchase_item', ['received' => (string) $total->toScale(4), 'state' => $state, 'tstamp' => $now], ['id' => $itemId]);
            }

            $states = array_map(strval(...), $this->connection->fetchFirstColumn('SELECT state FROM tl_consho_purchase_item WHERE pid = ?', [$listId]));
            $listState = match (true) {
                [] === array_diff($states, ['received', 'unavailable']) => 'done',
                [] !== array_intersect($states, ['received', 'unavailable', 'partial']) => 'partial',
                default => 'open',
            };

            $this->connection->update('tl_consho_purchase_list', ['state' => $listState, 'tstamp' => $now], ['id' => $listId]);

            return null;
        });
    }

    /**
     * "Produkt › Variante", the way the back end tells two variants apart.
     */
    public static function title(string $main, string $variant): string
    {
        $main = html_entity_decode($main, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $variant = html_entity_decode($variant, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return '' === $variant ? $main : $main.' › '.$variant;
    }

    private static function proposal(BigDecimal $stock, BigDecimal $ordered, BigDecimal $warning, BigDecimal $target, int $pack): string|null
    {
        // Only at or below the Meldebestand, counting what is on order.
        if ($stock->plus($ordered)->isGreaterThan($warning)) {
            return null;
        }

        $need = $target->isPositive() ? $target->minus($stock)->minus($ordered) : BigDecimal::of($pack);

        if (!$need->isPositive()) {
            return null;
        }

        // Whole packs (E2).
        $packs = $need->dividedBy($pack, 0, RoundingMode::Up);

        return (string) $packs->multipliedBy($pack)->toScale(4);
    }
}
