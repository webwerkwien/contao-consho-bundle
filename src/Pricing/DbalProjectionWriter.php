<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;

final class DbalProjectionWriter implements ProjectionWriterInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ProjectionState $state,
    ) {
    }

    public function write(int $productId, string $currency, string $country, string $net, string $gross, int $subscriptionRule = 0, bool $alsoSubscription = false, string|null $compareNet = null, string|null $compareGross = null): void
    {
        // The UNIQUE key on (pid, currency, country) is what makes the upsert
        // safe: a rebuild running twice writes the same row twice, not two rows.
        $this->connection->executeStatement(
            'INSERT INTO tl_consho_product_list_price (pid, currency, country, net, gross, subscriptionRule, alsoSubscription, compareNet, compareGross, tstamp)
             VALUES (:pid, :currency, :country, :net, :gross, :rule, :also, :compareNet, :compareGross, :ts)
             ON DUPLICATE KEY UPDATE net = :net, gross = :gross, subscriptionRule = :rule, alsoSubscription = :also, compareNet = :compareNet, compareGross = :compareGross, tstamp = :ts',
            [
                'pid' => $productId,
                'currency' => $currency,
                'country' => $country,
                'net' => $net,
                'gross' => $gross,
                'rule' => $subscriptionRule,
                'also' => $alsoSubscription ? 1 : 0,
                'compareNet' => $compareNet,
                'compareGross' => $compareGross,
                'ts' => time(),
            ],
        );
    }

    public function readAll(int $productId): array
    {
        return array_map(
            static fn (array $row): array => [
                'currency' => (string) $row['currency'],
                'country' => (string) $row['country'],
                'net' => (string) $row['net'],
                'gross' => (string) $row['gross'],
                'subscriptionRule' => (int) $row['subscriptionRule'],
                'alsoSubscription' => (bool) $row['alsoSubscription'],
                'compareNet' => null === $row['compareNet'] ? null : (string) $row['compareNet'],
                'compareGross' => null === $row['compareGross'] ? null : (string) $row['compareGross'],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT currency, country, net, gross, subscriptionRule, alsoSubscription, compareNet, compareGross FROM tl_consho_product_list_price WHERE pid = :pid ORDER BY currency, country',
                ['pid' => $productId],
            ),
        );
    }

    public function delete(int $productId, string $currency, string $country): void
    {
        $this->connection->executeStatement(
            'DELETE FROM tl_consho_product_list_price WHERE pid = :pid AND currency = :currency AND country = :country',
            ['pid' => $productId, 'currency' => $currency, 'country' => $country],
        );
    }

    public function findProductIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT pid FROM tl_consho_product_list_price',
        ));

        return $ids;
    }

    public function marketsFingerprint(): int|null
    {
        return $this->state->get(ProjectionState::LIST_PRICE_MARKETS);
    }

    public function storeMarketsFingerprint(int $fingerprint): void
    {
        $this->state->set(ProjectionState::LIST_PRICE_MARKETS, $fingerprint);
    }
}
