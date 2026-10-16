<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\DriverManager;

/**
 * An in-memory SQLite connection that drops " FOR UPDATE" — SQLite does not
 * know row locks and refuses the clause.
 *
 * ⚠️ What a test on it proves is therefore NOT the lock. Code that relies on
 * a lock to be correct must also be correct without one, by a condition in
 * the statement itself (StockBooking::take()), and that is what such a test
 * can show.
 */
final class SqliteWithoutRowLocks
{
    /**
     * The statements that asked for a row lock, in order — for a test that
     * has to show WHICH reads lock (Block G, review B2).
     *
     * @var list<string>
     */
    public static array $locked = [];

    /**
     * Every rollback throws, as on a connection that went away — for a test
     * that has to show the CAUSE survives a failing rollback (spec F, K-A).
     * Reset by the test that sets it.
     */
    public static bool $rollbackFails = false;

    public static function connection(): Connection
    {
        $configuration = (new Configuration())->setMiddlewares([new class implements Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function prepare(string $sql): DriverStatement
                            {
                                return parent::prepare(SqliteWithoutRowLocks::strip($sql));
                            }

                            public function query(string $sql): DriverResult
                            {
                                return parent::query(SqliteWithoutRowLocks::strip($sql));
                            }

                            public function exec(string $sql): int|string
                            {
                                // DBAL 4 rolls a nested level back to its savepoint through exec().
                                if (SqliteWithoutRowLocks::$rollbackFails && str_starts_with($sql, 'ROLLBACK TO SAVEPOINT')) {
                                    throw new \RuntimeException('rollback failed (test)');
                                }

                                return parent::exec(SqliteWithoutRowLocks::strip($sql));
                            }

                            public function rollBack(): void
                            {
                                if (SqliteWithoutRowLocks::$rollbackFails) {
                                    throw new \RuntimeException('rollback failed (test)');
                                }

                                parent::rollBack();
                            }
                        };
                    }
                };
            }
        }]);

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
    }

    /**
     * The locking statements since the last call, and forget them.
     *
     * @return list<string>
     */
    public static function takeLocked(): array
    {
        $locked = self::$locked;
        self::$locked = [];

        return $locked;
    }

    public static function strip(string $sql): string
    {
        if (str_contains($sql, ' FOR UPDATE')) {
            self::$locked[] = $sql;
        }

        return str_replace(' FOR UPDATE', '', $sql);
    }
}
