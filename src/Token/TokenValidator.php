<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Token;

/**
 * Validates an order token presented in a link.
 *
 * The lookup is scoped to the purpose: a download token must not open the
 * withdrawal screen, however genuine it is. That scoping is part of the query
 * rather than a check afterwards — a comparison that happens after the row is
 * found is a comparison somebody can forget to write.
 */
final class TokenValidator
{
    public function __construct(private readonly TokenRepositoryInterface $repository)
    {
    }

    /**
     * @return int|null the order id, or null if the token is not usable
     */
    public function validate(string $plaintext, TokenPurpose $purpose): int|null
    {
        return $this->resolve($plaintext, $purpose)?->orderId;
    }

    /**
     * Records the FIRST time a token was followed (C-10). For the mail's
     * token that is evidence of DELIVERY — a mailbox scanner may have been
     * the one who followed it, so it proves nothing about the owner; that is
     * `emailConfirmedAt`. Later calls change nothing (markUsed()).
     */
    public function recordUse(string $plaintext): void
    {
        if (preg_match('/^[0-9a-f]{64}\z/', $plaintext)) {
            $this->repository->markUsed(hash('sha256', $plaintext));
        }
    }

    /**
     * Like validate(), and says whether the token came by mail (C-10).
     */
    public function resolve(string $plaintext, TokenPurpose $purpose): ValidToken|null
    {
        /*
         * Reject anything that cannot be one of our tokens BEFORE touching the
         * database.
         *
         * Not a micro-optimisation: this endpoint is reachable by anyone
         * holding a link, and one that runs a query for every input — including
         * a megabyte of rubbish — is a convenient tool for taking the site
         * down.
         */
        // \z, not $: PCRE's $ also matches just before a trailing newline, so
        // 64 hex characters followed by \n would pass this check and reach the
        // query it exists to prevent.
        if (!preg_match('/^[0-9a-f]{64}\z/', $plaintext)) {
            return null;
        }

        $row = $this->repository->findByHash(hash('sha256', $plaintext), $purpose);

        if (null === $row) {
            return null;
        }

        // null means "does not expire" and must not be read as a timestamp.
        // Since D-6 only a row from before can carry it (the migration gives
        // those an expiry, too).
        if (null !== $row['expiresAt'] && $row['expiresAt'] < time()) {
            return null;
        }

        return new ValidToken($row['pid'], $row['confirmsEmail'] ?? false);
    }
}
