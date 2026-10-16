<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Token;

use Doctrine\DBAL\Connection;

final class DbalTokenRepository implements TokenRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function store(int $orderId, TokenPurpose $purpose, string $tokenHash, int $createdAt, int|null $expiresAt, bool $confirmsEmail = false): void
    {
        $this->connection->insert('tl_consho_order_token', [
            'pid' => $orderId,
            'purpose' => $purpose->value,
            'tokenHash' => $tokenHash,
            'createdAt' => $createdAt,
            'expiresAt' => $expiresAt,
            'tstamp' => $createdAt,
            'confirmsEmail' => $confirmsEmail ? 1 : 0,
        ]);
    }

    public function findByHash(string $tokenHash, TokenPurpose $purpose): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pid, expiresAt, usedAt, confirmsEmail FROM tl_consho_order_token WHERE tokenHash = :hash AND purpose = :purpose',
            ['hash' => $tokenHash, 'purpose' => $purpose->value],
        );

        if (false === $row) {
            return null;
        }

        return [
            'pid' => (int) $row['pid'],

            // Casting NULL to int would turn "does not expire" into 1970.
            'expiresAt' => null === $row['expiresAt'] ? null : (int) $row['expiresAt'],
            'usedAt' => null === $row['usedAt'] ? null : (int) $row['usedAt'],
            'confirmsEmail' => (bool) $row['confirmsEmail'],
        ];
    }

    /**
     * Records the FIRST use, and only the first.
     *
     * Not a lock: a customer may open their order link again, and § 13a FAGG
     * expects the withdrawal button to stay reachable. What this keeps is the
     * moment a link was first followed — evidence for a dispute about whether
     * a withdrawal was declared in time.
     *
     * ⚠️ `usedAt IS NULL`, not `usedAt = 0`. The column is NULL-able, and in
     * SQL a comparison against NULL is never true — with `= 0` this statement
     * would match nothing and every use would overwrite the first timestamp.
     */
    public function markUsed(string $tokenHash): void
    {
        $this->connection->executeStatement(
            'UPDATE tl_consho_order_token SET usedAt = :now WHERE tokenHash = :hash AND usedAt IS NULL',
            ['now' => time(), 'hash' => $tokenHash],
        );
    }
}
