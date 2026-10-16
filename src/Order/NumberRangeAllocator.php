<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;

/**
 * Hands out the next number of a range.
 *
 * 🔴 This is a concurrency problem, not a counting problem.
 *
 * The row is locked with FOR UPDATE inside a transaction. Never
 * SELECT MAX(…) + 1 — that looks just as correct, passes every single-threaded
 * test, and hands two simultaneous orders the same number the first busy
 * evening. In document numbers a duplicate is a tax problem, not a blemish.
 */
final class NumberRangeAllocator implements NumberAllocatorInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param string $type order|invoice|deliveryNote|creditNote|cancellation
     *
     * @throws NumberRangeNotConfigured
     */
    public function next(int $shopId, string $type): string
    {
        $this->connection->beginTransaction();

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, prefix, padding, current FROM tl_consho_number_range
                 WHERE pid = :pid AND type = :type
                 FOR UPDATE',
                ['pid' => $shopId, 'type' => $type],
            );

            if (false === $row) {
                $this->connection->rollBack();

                throw NumberRangeNotConfigured::for($shopId, $type);
            }

            $next = ((int) $row['current']) + 1;

            $this->connection->executeStatement(
                'UPDATE tl_consho_number_range SET current = :next, tstamp = :ts WHERE id = :id',
                ['next' => $next, 'ts' => time(), 'id' => (int) $row['id']],
            );

            $this->connection->commit();
        } catch (NumberRangeNotConfigured $e) {
            // Already rolled back above; rolling back twice would throw.
            throw $e;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        // Padding is a minimum width: a shop that outgrows it keeps counting.
        return ((string) $row['prefix']).str_pad((string) $next, (int) $row['padding'], '0', STR_PAD_LEFT);
    }
}
