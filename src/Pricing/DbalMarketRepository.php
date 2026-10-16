<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\Connection;

/**
 * The markets, as objects (C-1c, spec 4.0).
 *
 * What a market is stands in MarketCriteria; this class runs it and orders the
 * result. Before C-1c every caller derived the pair from tl_consho_shop on its
 * own. Since then there is one definition — with two deliberate exceptions,
 * both named in MarketCriteria: ListPriceBoundaryCron shares the definition
 * but not this class, because it needs the SQL inside a larger statement, and
 * DbalCatalogCompletenessIssueSource asks a different question (the currencies
 * of ONE shop, which a market cannot answer — it carries no shop id).
 */
final class DbalMarketRepository implements MarketRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findMarkets(): array
    {
        // The ORDER BY is this class's own: MarketCriteria leaves it off, and
        // the cron does not need it.
        return array_map(
            static fn (array $row): Market => new Market((string) $row['currency'], (string) $row['country']),
            $this->connection->fetchAllAssociative(MarketCriteria::sql().' ORDER BY currency, country'),
        );
    }
}
