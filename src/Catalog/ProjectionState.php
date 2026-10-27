<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * Durable markers of the projections — one integer per name.
 *
 * Not in the Symfony cache: every deploy empties var/cache/prod, and a lost
 * marker means a full rebuild. Not in tl_cron_job either: Contao's
 * purgeOldRecords() removes rows it does not know. See
 * Block B, section 5.7.
 */
final class ProjectionState
{
    /**
     * Every start/stop up to this moment has been applied to
     * tl_consho_product_visibility.
     */
    public const VISIBILITY_BOUNDARIES = 'visibility_boundaries';

    /**
     * Followed by a shop id: the fingerprint of the languages, default language
     * and currency that shop's rows were last built with (VisibilityProjector).
     */
    public const VISIBILITY_SHOP_PREFIX = 'visibility_shop_';

    /**
     * Every begin and end of a tax rate up to this moment has been applied to
     * tl_consho_product_list_price (C25, ListPriceBoundaryCron).
     */
    public const LIST_PRICE_TAX_BOUNDARIES = 'list_price_tax_boundaries';

    /**
     * The fingerprint of the shops' markets (currency and home country) the
     * list price projection was last fully rebuilt for (C25,
     * ListPriceProjector::syncMarkets()).
     */
    public const LIST_PRICE_MARKETS = 'list_price_markets';

    /**
     * When tl_consho_sold_out was first built (block Verfügbarkeit,
     * SoldOutMarksMigration) — a marker, not the table: a shop with nothing
     * sold out keeps it empty.
     */
    public const SOLD_OUT_BUILT = 'sold_out_built';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * null when the marker was never set — which is not the same as 0.
     */
    public function get(string $name): int|null
    {
        $value = $this->connection->fetchOne('SELECT value FROM tl_consho_projection_state WHERE name = ?', [$name]);

        return false === $value ? null : (int) $value;
    }

    /**
     * A portable upsert: UPDATE, and INSERT only when no row carries the name.
     *
     * Neither ON DUPLICATE KEY nor INSERT OR REPLACE — each belongs to one
     * dialect. And not simply "INSERT when the UPDATE touched no row": MariaDB
     * counts CHANGED rows, so writing the same value twice within one second
     * reports 0 and the INSERT would run into the unique name.
     */
    public function set(string $name, int $value): void
    {
        $data = ['value' => $value, 'tstamp' => time()];

        if (0 < (int) $this->connection->update('tl_consho_projection_state', $data, ['name' => $name])) {
            return;
        }

        if (false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_projection_state WHERE name = ?', [$name])) {
            return;
        }

        try {
            $this->connection->insert('tl_consho_projection_state', [...$data, 'name' => $name]);
        } catch (UniqueConstraintViolationException) {
            // Another process inserted the name in between; ours is the newer value.
            $this->connection->update('tl_consho_projection_state', $data, ['name' => $name]);
        }
    }

    public function delete(string $name): void
    {
        $this->connection->delete('tl_consho_projection_state', ['name' => $name]);
    }

    /**
     * All markers whose name starts with the prefix.
     *
     * SUBSTR, not LIKE: `_` is a wildcard in LIKE, and the prefixes here are
     * full of underscores. SQLite and MariaDB both know SUBSTR(string, 1, n).
     *
     * @return array<string, int> name => value, sorted by name
     */
    public function withPrefix(string $prefix): array
    {
        $markers = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT name, value FROM tl_consho_projection_state WHERE SUBSTR(name, 1, ?) = ? ORDER BY name',
            [\strlen($prefix), $prefix],
            [ParameterType::INTEGER, ParameterType::STRING],
        ) as $row) {
            $markers[(string) $row['name']] = (int) $row['value'];
        }

        return $markers;
    }
}
