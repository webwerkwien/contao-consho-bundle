<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Token;

/**
 * Issues order tokens.
 *
 * The plaintext is returned exactly once — it goes into the link and is never
 * stored. What the database keeps is a SHA-256 hash, so a dump grants nobody
 * access to somebody else's order.
 *
 * ── Why SHA-256 and not password_hash ────────────────────────────────────
 *
 * A password hash is deliberately slow because it protects a secret a HUMAN
 * chose, and humans choose from a small space. These tokens are 256 bits from
 * random_bytes: there is nothing to guess, so slowness buys nothing.
 *
 * The decisive point is different, though. password_hash salts every value, so
 * the same input yields a different hash each time — and a stored salted hash
 * cannot be looked up. Validation would have to read every token in the table
 * and compare them one by one. SHA-256 is deterministic, so the lookup goes
 * straight to a unique index.
 */
final class TokenGenerator
{
    public function __construct(private readonly TokenRepositoryInterface $repository)
    {
    }

    /**
     * @param int|null $expiresAt     an explicit expiry, or null to use the default of the purpose
     * @param bool     $confirmsEmail true only for a token that goes out BY MAIL — acting through
     *                                it proves the mailbox (C-10)
     *
     * @return string the plaintext token — available here and nowhere else again
     */
    public function issue(int $orderId, TokenPurpose $purpose, int|null $expiresAt = null, bool $confirmsEmail = false): string
    {
        // 32 bytes, hex-encoded to 64 characters — exactly the width of the
        // tokenHash column, and safe to put in a URL without escaping.
        $plaintext = bin2hex(random_bytes(32));
        $now = time();

        // A mailed token lives longer than one in a redirect (D-6, T1/T2) —
        // and "goes out by mail" is exactly what $confirmsEmail says.
        $expiresAt ??= $now + $purpose->defaultLifetime($confirmsEmail);

        $this->repository->store($orderId, $purpose, hash('sha256', $plaintext), $now, $expiresAt, $confirmsEmail);

        return $plaintext;
    }
}
