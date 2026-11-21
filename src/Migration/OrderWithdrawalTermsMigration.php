<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Adds the customer type, withdrawal period and delivery allowance frozen on
 * every order (tl_consho_order.customerType, withdrawalDays,
 * withdrawalGraceDays) and fills them for the orders placed before, from
 * their shop as it stands at the update.
 *
 * Those orders were placed under exactly these terms, as long as nobody has
 * changed the shop since. Without them, they would keep reading the shop, and
 * a shop switched to businesses only later would close their withdrawal
 * window.
 *
 * The shop's customer type may not exist yet in this pass (the schema update
 * runs after the migrations): every shop is then a consumer shop, the type's
 * default.
 *
 * The one-off criterion is the order column itself, as in
 * DeliveryNotifiedMigration: it runs exactly while the column is missing, and
 * the schema update that follows finds nothing left to do. A fresh
 * installation never runs it.
 */
final class OrderWithdrawalTermsMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        return $schema->tablesExist(['tl_consho_order', 'tl_consho_shop'])
            && !\array_key_exists('customertype', array_change_key_case($schema->listTableColumns('tl_consho_order')));
    }

    public function run(): MigrationResult
    {
        $shopColumns = array_change_key_case($this->connection->createSchemaManager()->listTableColumns('tl_consho_shop'));
        $type = \array_key_exists('customertype', $shopColumns) ? "COALESCE(NULLIF(s.customerType, ''), 'consumer')" : "'consumer'";

        // The definitions of the DCA fields: nullable, NULL meaning "not frozen".
        $this->connection->executeStatement('ALTER TABLE tl_consho_order ADD customerType VARCHAR(16) DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE tl_consho_order ADD withdrawalDays SMALLINT UNSIGNED DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE tl_consho_order ADD withdrawalGraceDays SMALLINT UNSIGNED DEFAULT NULL');

        $count = $this->connection->executeStatement(\sprintf(
            'UPDATE tl_consho_order SET
                customerType = (SELECT %s FROM tl_consho_shop s WHERE s.id = tl_consho_order.shop),
                withdrawalDays = (SELECT s.withdrawalDays FROM tl_consho_shop s WHERE s.id = tl_consho_order.shop),
                withdrawalGraceDays = (SELECT s.withdrawalGraceDays FROM tl_consho_shop s WHERE s.id = tl_consho_order.shop)
            WHERE EXISTS (SELECT 1 FROM tl_consho_shop s WHERE s.id = tl_consho_order.shop)',
            $type,
        ));

        return $this->createResult(true, \sprintf('Added the frozen withdrawal terms and filled %d order(s) from their shop.', $count));
    }
}
