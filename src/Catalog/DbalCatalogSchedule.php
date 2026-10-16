<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * When a catalogue page changes by itself (plan B-7b, E2).
 *
 * A product's `start`/`stop` and a price's `validFrom`/`validTo` change what a
 * page shows without anybody saving, so no cache tag is invalidated. The page
 * lives no longer than until the next such moment — the same way Contao sets
 * s-maxage for a content element's start and stop, and the listing already
 * does for its pins.
 *
 * Only the moments still ahead count; a past one has already changed the page.
 *
 * ── Why listings ask about every product ──────────────────────────────────
 *
 * A product scheduled to start is invisible now, so no listing query finds it
 * — yet it will appear in a listing then. Which listing depends on filters,
 * shops and languages. Every published product with a moment ahead is always
 * right, and a shorter cache lifetime is the only price of being coarse.
 *
 * List price boundaries are not asked here: ListPriceBoundaryCron switches the
 * projection and invalidates the listings itself.
 */
class DbalCatalogSchedule
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The next start or stop of any published product or variant.
     */
    public function nextProductChange(int $now): int|null
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT start, stop FROM tl_consho_product WHERE published = 1 AND (start <> '' OR stop <> '')",
        );

        return self::earliest($rows, ['start', 'stop'], $now);
    }

    /**
     * The next moment the page of this main product changes: a start or stop
     * of the product or one of its variants, or a price of them beginning or
     * ending — scale and customer group prices included, the page shows them.
     */
    public function nextDetailChange(int $mainProductId, int $now): int|null
    {
        $products = $this->connection->fetchAllAssociative(
            'SELECT id, start, stop, published FROM tl_consho_product WHERE id = :main OR pid = :main',
            ['main' => $mainProductId],
        );

        if ([] === $products) {
            return null;
        }

        $prices = $this->connection->fetchAllAssociative(
            // tstamp > 0: see DbalPriceRepository::findCandidates() — a price
            // mask opened and never saved schedules nothing.
            'SELECT validFrom, validTo FROM tl_consho_price
             WHERE pid IN (:ids) AND tstamp > 0 AND (validFrom IS NOT NULL OR validTo IS NOT NULL)',
            ['ids' => array_map(static fn (array $row): int => (int) $row['id'], $products)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $published = array_filter($products, static fn (array $row): bool => 1 === (int) $row['published']);

        $candidates = array_filter([
            self::earliest($published, ['start', 'stop'], $now),
            self::earliest($prices, ['validFrom', 'validTo'], $now),
        ], static fn (int|null $moment): bool => null !== $moment);

        return [] === $candidates ? null : min($candidates);
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param list<string>                $columns
     */
    private static function earliest(array $rows, array $columns, int $now): int|null
    {
        $next = null;

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $moment = $row[$column] ?? null;

                // '' (start/stop) and NULL (validity) both mean "not set".
                if (null === $moment || '' === (string) $moment || (int) $moment <= $now) {
                    continue;
                }

                $next = null === $next ? (int) $moment : min($next, (int) $moment);
            }
        }

        return $next;
    }
}
