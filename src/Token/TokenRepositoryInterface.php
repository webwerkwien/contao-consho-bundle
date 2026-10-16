<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Token;

interface TokenRepositoryInterface
{
    /**
     * @param int|null $expiresAt null means the token does not expire — since D-6 nothing issues
     *                            such a token; the value stays readable for rows from before
     */
    public function store(int $orderId, TokenPurpose $purpose, string $tokenHash, int $createdAt, int|null $expiresAt, bool $confirmsEmail = false): void;

    /**
     * @return array{pid: int, expiresAt: int|null, usedAt: int|null, confirmsEmail?: bool}|null
     */
    public function findByHash(string $tokenHash, TokenPurpose $purpose): array|null;

    public function markUsed(string $tokenHash): void;
}
