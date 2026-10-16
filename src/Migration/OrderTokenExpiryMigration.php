<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;

/**
 * Gives the tokens issued before D-6 an expiry (T3).
 *
 * Until D-6 the order page's tokens did not expire. The new lifetimes
 * (TokenPurpose::defaultLifetime()) would otherwise apply only to new links,
 * and every old one stayed a lasting credential — in mailboxes and browser
 * histories. Counted from `createdAt`, as if the rule had always applied:
 * most redirect tokens are therefore expired at once, and the daily
 * OrderTokenCleanupCron removes them.
 *
 * ⚠️ `confirmsEmail = 0` is not always a redirect (Fable review D-6, S1).
 * Before C-10 the mail and the redirect SHARED one token, flagged 0 — the
 * database cannot tell them apart any more. Those old mail links get the
 * redirect's day and die with the migration. Accepted: there was no shop in
 * production before C-10, and whoever holds such a link asks for a new one
 * on the order page (T4).
 *
 * Also deletes the rows of the purpose `withdrawal`, which D-2 removed from
 * the enum (decision E2): nothing resolves them, and nothing would ever
 * clean them up.
 *
 * The criterion is the rows themselves — once none is left, the migration
 * has nothing to do.
 */
final class OrderTokenExpiryMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        if (!$schema->tablesExist(['tl_consho_order_token'])) {
            return false;
        }

        return false !== $this->connection->fetchOne("SELECT 1 FROM tl_consho_order_token WHERE expiresAt IS NULL OR purpose = 'withdrawal'");
    }

    public function run(): MigrationResult
    {
        $withdrawal = $this->connection->executeStatement("DELETE FROM tl_consho_order_token WHERE purpose = 'withdrawal'");

        $mailed = $this->connection->executeStatement(
            'UPDATE tl_consho_order_token SET expiresAt = createdAt + :lifetime WHERE expiresAt IS NULL AND confirmsEmail = 1',
            ['lifetime' => TokenPurpose::MAILED_LIFETIME],
            ['lifetime' => ParameterType::INTEGER],
        );

        // Everything else without an expiry is a view token of a redirect
        // (or a shared one from before C-10, see above); a download always
        // had one.
        $browser = $this->connection->executeStatement(
            'UPDATE tl_consho_order_token SET expiresAt = createdAt + :lifetime WHERE expiresAt IS NULL',
            ['lifetime' => TokenPurpose::BROWSER_LIFETIME],
            ['lifetime' => ParameterType::INTEGER],
        );

        return $this->createResult(true, \sprintf(
            'Order tokens: %d mailed and %d redirect token(s) given an expiry, %d withdrawal token(s) removed.',
            $mailed,
            $browser,
            $withdrawal,
        ));
    }
}
