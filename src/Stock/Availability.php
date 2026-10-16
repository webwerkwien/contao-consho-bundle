<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * How much of an article can be sold, and what the visitor is told about it
 * (Block G, spec 5.2) — the one place for basket, checkout, purchase click,
 * product page, tile, variant choice, JSON-LD and the finding.
 *
 * The lesson of VisibilityCriteria: a rule copied into eight places is a rule
 * that one of them forgets when it changes.
 *
 * ── The rules ────────────────────────────────────────────────────────────
 *
 *  - A key pool (an active row with source `keys`, G10): the free codes less
 *    what is ordered and not yet delivered (pendingKeys()). Never sold on
 *    order — a code that does not exist cannot be handed out.
 *  - No stock kept (stockTracked off at the main product), or not a physical
 *    good: unlimited. Files and services are never short (G10).
 *  - Kept: the article's row in tl_consho_stock. A tracked article WITHOUT a
 *    row counts as zero — StockRows creates rows on every save, so a missing
 *    one is a fault, and a fault must not sell goods that may not exist.
 *  - The state follows StockState::of(): available, "few" at or below
 *    stockWarning, unavailable, or backorder when the main product sells
 *    without stock (G6, G7).
 *
 * The kind follows DbalArticleShippingData: the main product's type, else the
 * article's own, no type at all is physical.
 */
class Availability
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function for(int $articleId): ArticleAvailability
    {
        return $this->of([$articleId])[$articleId] ?? ArticleAvailability::unlimited();
    }

    /**
     * @param list<int> $articleIds
     * @param int       $exceptOrder an order whose own lines are not counted as
     *                               pending — the one being placed (StockAllocation)
     *
     * @return array<int, ArticleAvailability> by article id; an unknown id is missing
     */
    public function of(array $articleIds, int $exceptOrder = 0): array
    {
        if ([] === $articleIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.id, m.stockTracked, m.stockBackorder, m.stockWarning,
                    COALESCE(tm.kind, ta.kind, 'physical') AS kind,
                    s.id AS stockId, s.source, s.quantity, s.availableFrom
               FROM tl_consho_product a
               JOIN tl_consho_product m ON m.id = CASE WHEN a.pid > 0 THEN a.pid ELSE a.id END
               LEFT JOIN tl_consho_product_type tm ON tm.id = m.productType AND m.productType > 0
               LEFT JOIN tl_consho_product_type ta ON ta.id = a.productType AND a.productType > 0
               LEFT JOIN tl_consho_stock s ON s.article = a.id AND s.active = 1
              WHERE a.id IN (?)",
            [array_values(array_unique($articleIds))],
            [ArrayParameterType::INTEGER],
        );

        $keyArticles = array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($rows, static fn (array $row): bool => 'keys' === $row['source']),
        ));
        $keysLeft = [] === $keyArticles ? [] : $this->keysLeft($keyArticles, $exceptOrder);

        $result = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $result[$id] = 'keys' === $row['source']
                ? new ArticleAvailability(
                    StockState::of($keysLeft[$id], BigDecimal::of(StockBooking::scaled($row['stockWarning'])), false),
                    (string) $keysLeft[$id]->toScale(4),
                )
                : self::fromRow($row);
        }

        return $result;
    }

    /**
     * The articles a basket asks more of than can be sold (G5). Summed per
     * article: a one-time line and a subscription line of the same article
     * draw on one stock. Callers leave out "Lizenz verlängern" lines — they
     * take no code (Abo-7b).
     *
     * @param iterable<array{int, string}> $lines article id and quantity
     *
     * @return list<int> article ids
     */
    public function short(iterable $lines): array
    {
        $wanted = [];

        foreach ($lines as [$article, $quantity]) {
            $wanted[$article] = ($wanted[$article] ?? BigDecimal::zero())->plus(BigDecimal::of(StockBooking::scaled($quantity)));
        }

        $availability = $this->of(array_keys($wanted));
        $short = [];

        foreach ($wanted as $article => $quantity) {
            if (!($availability[$article] ?? ArticleAvailability::unlimited())->covers((string) $quantity)) {
                $short[] = $article;
            }
        }

        return $short;
    }

    /**
     * Free codes less the quantity ordered and not yet handed out (G10).
     *
     * Pending is what DigitalReleaseCron would still release: a product line
     * of an order neither cancelled nor anonymized, less its reported
     * deliveries and its accepted withdrawals — a line that ended before its
     * release takes no code. "Lizenz verlängern" lines (renews > 0) take none
     * either: the keys move on with the licence.
     *
     * Each line counts at least zero (review G, S2): a line delivered AND
     * withdrawn would otherwise count minus one and hide an open line.
     *
     * ── Plain reads, in this order (review G, B2 and its second review) ───
     *
     * At the purchase click this runs under the lock of the stock row and in
     * READ COMMITTED (OrderPlacer), so a purchase that waited sees the one
     * before it. Locking reads were tried and are wrong here: they lock the
     * other buyer's uncommitted lines — a deadlock with every concurrent
     * purchase — and without an index on `product` every line of the table.
     *
     * The open lines are read BEFORE the free codes. A release committing in
     * between takes one off each; read in this order, it can only be counted
     * twice as taken — a refusal too many, never a code promised twice.
     *
     * @param list<int> $articles
     *
     * @return array<int, BigDecimal>
     */
    private function keysLeft(array $articles, int $exceptOrder): array
    {
        $lines = $this->connection->fetchAllAssociative(
            "SELECT i.product, CASE WHEN o.cancelledAt IS NULL AND o.anonymizedAt IS NULL AND i.pid <> ? THEN
                        i.quantity
                        - COALESCE((SELECT SUM(di.quantity) FROM tl_consho_delivery_item di
                                      JOIN tl_consho_delivery d ON d.id = di.pid
                                     WHERE di.orderItem = i.id AND d.reportedAt IS NOT NULL), 0)
                        - COALESCE((SELECT SUM(wi.acceptedQuantity) FROM tl_consho_withdrawal_item wi
                                      JOIN tl_consho_withdrawal w ON w.id = wi.pid
                                     WHERE wi.orderItem = i.id AND w.state = 'accepted'), 0)
                    ELSE 0 END AS open
               FROM tl_consho_order_item i
               JOIN tl_consho_order o ON o.id = i.pid
              WHERE i.product IN (?) AND i.lineType = 'product' AND i.renews = 0",
            [$exceptOrder, $articles],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        $free = $this->connection->fetchAllKeyValue(
            'SELECT pid, COUNT(*) FROM tl_consho_key WHERE pid IN (?) AND entitlement = 0 GROUP BY pid',
            [$articles],
            [ArrayParameterType::INTEGER],
        );

        $left = [];

        foreach ($articles as $article) {
            $left[$article] = BigDecimal::of((string) ($free[$article] ?? 0));
        }

        foreach ($lines as $line) {
            $open = BigDecimal::of(StockBooking::scaled($line['open']));

            if ($open->isPositive()) {
                $left[(int) $line['product']] = $left[(int) $line['product']]->minus($open);
            }
        }

        return $left;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): ArticleAvailability
    {
        if (!(bool) $row['stockTracked'] || !ProductKind::stored((string) $row['kind'])->isPhysical()) {
            return ArticleAvailability::unlimited();
        }

        $quantity = BigDecimal::of(null === $row['stockId'] ? '0' : StockBooking::scaled($row['quantity']));
        $warning = BigDecimal::of(StockBooking::scaled($row['stockWarning']));
        $backorder = (bool) $row['stockBackorder'];
        $from = null === $row['availableFrom'] || (int) $row['availableFrom'] < 1 ? null : (int) $row['availableFrom'];

        return new ArticleAvailability(StockState::of($quantity, $warning, $backorder), (string) $quantity->toScale(4), $backorder, $from);
    }
}
