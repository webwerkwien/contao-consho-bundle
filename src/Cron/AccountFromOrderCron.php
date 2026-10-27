<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Deletes accounts created from a guest order that were never opened (K1,
 * K1-6): no password within the 24 hours Contao's token is valid.
 *
 * Such an account never had a password, a login or a group — nobody used it.
 * It carries the name and address of the order, though, and has no reason to
 * stay. Hourly, so an account may outlive its token by up to an hour.
 */
#[AsCronJob('hourly')]
final class AccountFromOrderCron
{
    public const PENDING_FOR = 86400;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(): void
    {
        $this->run(time());
    }

    public function run(int $now): int
    {
        // The unconfirmed password tokens go with the account, as Contao's
        // own "Lost password" removes them once a password is set; Contao
        // would keep them for years otherwise (OptIn::purgeTokens()).
        //
        // Selected inside the transaction, and the DELETE repeats the
        // condition: a password set between the two must keep its account.
        return $this->connection->transactional(function (Connection $connection) use ($now): int {
            $pending = "consho_accountPending > 0 AND disable = 1 AND password = ''";
            $ids = array_map('intval', $connection->fetchFirstColumn(
                "SELECT id FROM tl_member WHERE $pending AND consho_accountPending <= ?",
                [$now - self::PENDING_FOR],
            ));

            if ([] === $ids) {
                return 0;
            }

            $tokens = $connection->fetchFirstColumn(
                "SELECT r.pid FROM tl_opt_in_related r JOIN tl_opt_in o ON o.id = r.pid WHERE r.relTable = 'tl_member' AND r.relId IN (?) AND o.confirmedOn = 0",
                [$ids],
                [ArrayParameterType::INTEGER],
            );

            if ([] !== $tokens) {
                $connection->executeStatement('DELETE FROM tl_opt_in_related WHERE pid IN (?)', [$tokens], [ArrayParameterType::INTEGER]);
                $connection->executeStatement('DELETE FROM tl_opt_in WHERE id IN (?)', [$tokens], [ArrayParameterType::INTEGER]);
            }

            return (int) $connection->executeStatement("DELETE FROM tl_member WHERE id IN (?) AND $pending", [$ids], [ArrayParameterType::INTEGER]);
        });
    }
}
