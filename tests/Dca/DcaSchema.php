<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\Connection;

/**
 * A table for SQLite, BUILT FROM its DCA rather than copied from it (Abo-3).
 *
 * A hand-written copy is how the test schema of the order came to call a
 * column `orderId` that production calls `order` — no test could ever meet
 * the real name. Read from contao/dca/<table>.php, a column the DCA
 * renames is renamed here too, and a column the code writes that the DCA
 * lacks fails the test.
 *
 * Every column is quoted: `order` and `groups` are reserved words.
 */
final class DcaSchema
{
    public static function create(Connection $connection, string $table): void
    {
        self::build($connection, $table, self::dca($table));
    }

    /**
     * The same from a DCA file outside this bundle — Consho Connect builds its
     * own tables with it.
     */
    public static function createFromFile(Connection $connection, string $table, string $file): void
    {
        $previous = $GLOBALS['TL_DCA'] ?? null;

        require $file;
        $dca = $GLOBALS['TL_DCA'][$table];

        if (null === $previous) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $previous;
        }

        self::build($connection, $table, $dca);
    }

    /**
     * A core table (tl_page, tl_article, tl_content) from Contao's own DCA,
     * Consho's additions included — for code that writes core rows, such as
     * the setup page creating shop pages (block I). Their `sql` is mostly a
     * MySQL string; translated to SQLite below.
     */
    public static function createCore(Connection $connection, string $table): void
    {
        // The core files declare classes (class tl_page extends Backend), so
        // they can be required once per process — the DCA is kept here.
        static $loaded = [];

        if (isset($loaded[$table])) {
            self::build($connection, $table, $loaded[$table]);

            return;
        }

        $previous = $GLOBALS['TL_DCA'] ?? null;

        // tl_article calls $this->loadDataContainer('tl_page') at the top.
        $loader = new class {
            public function loadDataContainer(string $table): void
            {
            }
        };

        (function (string $file): void {
            require $file;
        })->call($loader, __DIR__.'/../../vendor/contao/core-bundle/contao/dca/'.$table.'.php');

        if (is_file($own = __DIR__.'/../../contao/dca/'.$table.'.php')) {
            (function (string $file): void {
                require $file;
            })->call($loader, $own);
        }

        $dca = $GLOBALS['TL_DCA'][$table];

        if (null === $previous) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $previous;
        }

        $loaded[$table] = $dca;
        self::build($connection, $table, $dca);
    }

    /**
     * @param array{config: array{sql?: array{keys?: array<string, string>}}, fields: array<string, array<string, mixed>>} $dca
     */
    private static function build(Connection $connection, string $table, array $dca): void
    {
        $columns = [];

        foreach ($dca['fields'] as $name => $field) {
            if (!isset($field['sql'])) {
                continue;
            }

            $columns[] = '"'.$name.'" '.(\is_array($field['sql']) ? self::column($field['sql']) : self::stringColumn((string) $field['sql']));
        }

        $connection->executeStatement(\sprintf('CREATE TABLE %s (%s)', $table, implode(', ', $columns)));

        foreach ($dca['config']['sql']['keys'] ?? [] as $key => $kind) {
            if ('unique' === $kind || 'index' === $kind) {
                // "idx_" first: SQLite shares one namespace between tables and
                // indexes, and tl_consho_payment's key `attempt` would be named
                // tl_consho_payment_attempt — the name of another table (Abo-5).
                $connection->executeStatement(\sprintf(
                    'CREATE %s INDEX idx_%s_%s ON %s (%s)',
                    'unique' === $kind ? 'UNIQUE' : '',
                    $table,
                    str_replace(',', '_', $key),
                    $table,
                    implode(', ', array_map(static fn (string $column): string => '"'.trim($column).'"', explode(',', $key))),
                ));
            }
        }
    }

    /**
     * @return array{config: array{sql?: array{keys?: array<string, string>}}, fields: array<string, array<string, mixed>>}
     */
    public static function dca(string $table): array
    {
        $previous = $GLOBALS['TL_DCA'] ?? null;

        require __DIR__.'/../../contao/dca/'.$table.'.php';
        $dca = $GLOBALS['TL_DCA'][$table];

        if (null === $previous) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $previous;
        }

        return $dca;
    }

    /**
     * "int(10) unsigned NOT NULL auto_increment", "varchar(255) BINARY NOT
     * NULL default ''" — SQLite takes the type name and the constraints as
     * they are once the MySQL-only words are gone.
     */
    private static function stringColumn(string $sql): string
    {
        if (false !== stripos($sql, 'auto_increment')) {
            return 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        return trim((string) preg_replace(['/\bunsigned\b/i', '/\bBINARY\b(?!\s*\()/', '/\bCOLLATE\s+\S+/i'], '', $sql));
    }

    /**
     * @param array<string, mixed> $sql
     */
    private static function column(array $sql): string
    {
        if (true === ($sql['autoincrement'] ?? false)) {
            return 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $type = match ($sql['type'] ?? 'string') {
            'integer', 'smallint', 'bigint', 'boolean' => 'INTEGER',
            'decimal' => 'NUMERIC',
            'blob', 'binary' => 'BLOB',
            default => 'TEXT',
        };

        if (false === ($sql['notnull'] ?? true)) {
            return $type.' NULL';
        }

        // NOT NULL without a default: an insert that leaves it out fails here
        // as it does on MariaDB in strict mode (Abo-3).
        if (!\array_key_exists('default', $sql)) {
            return $type.' NOT NULL';
        }

        $default = $sql['default'];

        return \sprintf('%s NOT NULL DEFAULT %s', $type, match (true) {
            \is_bool($default) => $default ? '1' : '0',
            \is_int($default) => (string) $default,
            default => "'".str_replace("'", "''", (string) $default)."'",
        });
    }
}
