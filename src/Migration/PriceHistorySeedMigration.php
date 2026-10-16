<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Starts the price history (block H, H-2) with the prices standing today.
 *
 * PriceHistory is written whenever ListPriceProjector refreshes an article —
 * on a save, at a validity boundary, on a rebuild. Until then a shop that
 * existed before block H would have no history at all, and a promotion
 * started next week would find nothing to compare with. Seeding from the
 * projection gives every article the one entry that is certain: this price,
 * asked from now on.
 *
 * Marked as no promotion, because the projection does not say. A promotion
 * running at the deploy is caught at the next refresh as a change and then
 * compares with this very price — which is not higher, so nothing is struck
 * through. Too little rather than a wrong strike-through.
 *
 * Runs while the history is empty and the projection is not.
 */
final class PriceHistorySeedMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        if (!$schema->tablesExist(['tl_consho_price_history', 'tl_consho_product_list_price'])) {
            return false;
        }

        return 0 === (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_price_history')
            && 0 < (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_list_price');
    }

    public function run(): MigrationResult
    {
        $now = time();

        $count = $this->connection->executeStatement(
            'INSERT INTO tl_consho_price_history (tstamp, article, currency, country, subscriptionRule, net, gross, promotion, validFrom)
             SELECT ?, pid, currency, country, subscriptionRule, net, gross, 0, ? FROM tl_consho_product_list_price',
            [$now, $now],
        );

        return $this->createResult(true, \sprintf('Price history started for %d articles and markets.', $count));
    }
}
