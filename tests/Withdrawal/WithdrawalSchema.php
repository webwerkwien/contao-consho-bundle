<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Digital\DigitalSchema;

/**
 * The withdrawal tables for SQLite tests, built from
 * contao/dca/tl_consho_withdrawal(_item).php: a test schema that drifts
 * from the production one stops seeing what it tests.
 *
 * Since D-3 the balance reads accepted withdrawals, so every test that builds
 * an OrderBalanceReader needs these tables — seven files, and seven copies
 * would be seven chances to drift.
 */
final class WithdrawalSchema
{
    public static function create(Connection $connection): void
    {
        // What a withdrawal reads alongside its own tables (F-4).
        DigitalSchema::create($connection);

        // Both from their DCA, keys included (pid,number unique) — the item
        // since Block G, when the hand-written copy turned out to have no
        // restock columns.
        DcaSchema::create($connection, 'tl_consho_withdrawal');
        DcaSchema::create($connection, 'tl_consho_withdrawal_item');
    }
}
