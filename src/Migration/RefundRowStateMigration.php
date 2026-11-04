<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * A withdrawal paid back without a provider booked a refund row with no
 * payout state and no payment (Geldflüsse D1, D2). Since then it books
 * `manual`, tied to the payments it pays back. This brings the old rows in
 * line: `manual`, and tied to the payment where the order has exactly one —
 * with several, which one went back is not known, and Consho does not book
 * what it only supposes.
 *
 * Payments count as RefundPayout::byHand() takes them: positive, not a
 * voucher's, not a row that itself pays something back.
 *
 * Runs while such a row exists; nothing writes one any more, so after its
 * run it never runs again.
 */
final class RefundRowStateMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        if (!$schema->tablesExist(['tl_consho_payment'])) {
            return false;
        }

        $columns = array_change_key_case($schema->listTableColumns('tl_consho_payment'));

        if (!isset($columns['payoutstate'], $columns['refundof'])) {
            return false;
        }

        return false !== $this->connection->fetchOne("SELECT 1 FROM tl_consho_payment WHERE method = 'refund' AND payoutState = '' AND refundOf = 0 LIMIT 1");
    }

    public function run(): MigrationResult
    {
        // A row tied to its payment already is left as it is.
        $rows = $this->connection->fetchAllAssociative("SELECT id, pid FROM tl_consho_payment WHERE method = 'refund' AND payoutState = '' AND refundOf = 0");
        $tied = 0;

        foreach ($rows as $row) {
            $payments = $this->connection->fetchFirstColumn(
                "SELECT id FROM tl_consho_payment
                  WHERE pid = ? AND amount > 0 AND refundOf = 0 AND reference NOT LIKE 'voucher:%'",
                [(int) $row['pid']],
            );
            $refundOf = 1 === \count($payments) ? (int) $payments[0] : 0;
            $tied += $refundOf > 0 ? 1 : 0;

            $this->connection->update('tl_consho_payment', ['payoutState' => 'manual', 'refundOf' => $refundOf], ['id' => (int) $row['id']]);
        }

        return $this->createResult(true, \sprintf('Marked %d refund row(s) as paid back by hand, %d of them tied to the order\'s only payment.', \count($rows), $tied));
    }
}
