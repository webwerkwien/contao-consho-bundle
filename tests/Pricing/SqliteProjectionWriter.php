<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Pricing\ProjectionWriterInterface;

/**
 * DbalProjectionWriter speaks MySQL (ON DUPLICATE KEY). The same contract in
 * SQLite form, for tests that let the real projector calculate against SQLite.
 */
final class SqliteProjectionWriter implements ProjectionWriterInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function write(int $productId, string $currency, string $country, string $net, string $gross, int $subscriptionRule = 0, bool $alsoSubscription = false, string|null $compareNet = null, string|null $compareGross = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO tl_consho_product_list_price (pid, currency, country, net, gross, subscriptionRule, alsoSubscription, compareNet, compareGross, tstamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (pid, currency, country) DO UPDATE SET net = excluded.net, gross = excluded.gross, subscriptionRule = excluded.subscriptionRule, alsoSubscription = excluded.alsoSubscription, compareNet = excluded.compareNet, compareGross = excluded.compareGross, tstamp = excluded.tstamp',
            [$productId, $currency, $country, $net, $gross, $subscriptionRule, $alsoSubscription ? 1 : 0, $compareNet, $compareGross, time()],
        );
    }

    public function readAll(int $productId): array
    {
        return array_map(
            static fn (array $row): array => ['currency' => (string) $row['currency'], 'country' => (string) $row['country'], 'net' => (string) $row['net'], 'gross' => (string) $row['gross'], 'subscriptionRule' => (int) $row['subscriptionRule'], 'alsoSubscription' => (bool) $row['alsoSubscription'], 'compareNet' => null === $row['compareNet'] ? null : (string) $row['compareNet'], 'compareGross' => null === $row['compareGross'] ? null : (string) $row['compareGross']],
            $this->connection->fetchAllAssociative('SELECT currency, country, net, gross, subscriptionRule, alsoSubscription, compareNet, compareGross FROM tl_consho_product_list_price WHERE pid = ? ORDER BY currency, country', [$productId]),
        );
    }

    public function delete(int $productId, string $currency, string $country): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_product_list_price WHERE pid = ? AND currency = ? AND country = ?', [$productId, $currency, $country]);
    }

    public function findProductIds(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT DISTINCT pid FROM tl_consho_product_list_price'));
    }

    public function marketsFingerprint(): int|null
    {
        return (new ProjectionState($this->connection))->get(ProjectionState::LIST_PRICE_MARKETS);
    }

    public function storeMarketsFingerprint(int $fingerprint): void
    {
        (new ProjectionState($this->connection))->set(ProjectionState::LIST_PRICE_MARKETS, $fingerprint);
    }
}
