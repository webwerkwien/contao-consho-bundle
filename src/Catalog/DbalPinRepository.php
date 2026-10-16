<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The pins of a listing: `consho_product_pin` rows nested in it (B-7).
 *
 * Each pin is a real tl_content row, which is the whole point: `sorting` is the
 * drag and drop, `invisible`, `start` and `stop` switch it, tl_version and
 * tl_undo record it — all without Consho code.
 *
 * Read here rather than through the ContentElementReferences Contao hands the
 * controller: those carry only what is published NOW, and the listing also needs
 * the next moment a pin appears or disappears, for its cache lifetime.
 *
 * Published means what ContentModel::findPublishedByPidAndTable() means, so a
 * pin behaves like any other nested element, preview included.
 */
final class DbalPinRepository
{
    public const TYPE = 'consho_product_pin';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The pinned products, in the dragged order.
     *
     * ⚠️ `$listId` is the ORIGINAL element: behind an alias element the model
     * carries the alias's id and the original in origId — the core asks for
     * nested elements with `origId ?: id` as well.
     *
     * @return list<int>
     */
    public function productIdsOf(int $listId, int $now, bool $preview = false): array
    {
        $published = $preview ? '' : " AND c.invisible = 0 AND (c.start = '' OR c.start <= :now) AND (c.stop = '' OR c.stop > :now)";

        $ids = $this->connection->fetchFirstColumn(
            "SELECT c.conshoProduct FROM tl_content c
             WHERE c.ptable = 'tl_content' AND c.pid = :list AND c.type = :type AND c.conshoProduct > 0".$published.'
             ORDER BY c.sorting, c.id',
            ['list' => $listId, 'type' => self::TYPE, 'now' => (string) $now],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * When the next pin of this listing starts or stops, or null.
     *
     * The listing's response lives no longer than that — otherwise "from
     * 1 to 24 December the gift set stands on top" would start whenever the
     * page cache happened to expire.
     */
    public function nextChange(int $listId, int $now): int|null
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.start, c.stop FROM tl_content c
             WHERE c.ptable = 'tl_content' AND c.pid = :list AND c.type = :type AND c.invisible = 0",
            ['list' => $listId, 'type' => self::TYPE],
        );

        $next = null;

        foreach ($rows as $row) {
            foreach ([$row['start'], $row['stop']] as $moment) {
                if ('' === (string) $moment || (int) $moment <= $now) {
                    continue;
                }

                $next = null === $next ? (int) $moment : min($next, (int) $moment);
            }
        }

        return $next;
    }

    /**
     * "Title (SKU)" of a main product, or null when there is none by that id.
     */
    public function labelOf(int $productId): string|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT title, sku FROM tl_consho_product WHERE id = ? AND pid = 0',
            [$productId],
        );

        return false === $row ? null : self::label($row);
    }

    /**
     * Every main product, for the choice at the pin. Variants are not pinnable:
     * a listing shows main products only.
     *
     * @return array<int, string>
     */
    public function mainProductOptions(): array
    {
        $options = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, sku FROM tl_consho_product WHERE pid = 0 ORDER BY title, id') as $row) {
            $options[(int) $row['id']] = self::label($row);
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function label(array $row): string
    {
        $sku = (string) ($row['sku'] ?? '');

        return '' === $sku ? (string) $row['title'] : \sprintf('%s (%s)', $row['title'], $sku);
    }
}
