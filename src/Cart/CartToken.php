<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The basket cookie (C9) and the token in it.
 *
 * The database stores the SHA-256 of the token, never the token itself: a
 * database dump is then not enough to take over somebody's basket. Hashing is
 * unsalted and unstretched on purpose — 32 random bytes have nothing to guess,
 * and the hash is looked up on every add, so bcrypt would be a per-request
 * cost for no gain.
 *
 * SameSite=Lax is half of the CSRF answer (C11): a cross-site POST does not
 * carry the cookie, so it cannot reach anybody's basket. The other half is the
 * Origin check in AddToCartController.
 */
final class CartToken
{
    public const NAME = 'consho_cart';

    public static function generate(): string
    {
        // base64url without padding: 32 bytes become 43 characters that need
        // no escaping in a cookie value.
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function fromRequest(Request $request): string|null
    {
        $token = $request->cookies->get(self::NAME);

        // Exactly what generate() produces: 43 base64url characters. The
        // length is part of the filter, not decoration — a cookie of another
        // shape was not issued here, whatever it is made of.
        //
        // \z, not $: PCRE's $ also matches just before a trailing newline, so
        // "…43 characters…\n" would pass a check that promises it cannot.
        // A raw newline does not survive a real Cookie header, but a Request
        // built in code — an internal subrequest, a test, a proxy that is less
        // strict — never went through that filter.
        if (!\is_string($token) || 1 !== preg_match('/^[A-Za-z0-9_-]{43}\z/', $token)) {
            return null;
        }

        return $token;
    }

    public static function cookie(string $token, bool $secure, int $now): Cookie
    {
        return Cookie::create(self::NAME, $token, $now + CartLifetime::COOKIE)
            ->withHttpOnly(true)
            // Only over HTTPS where the request was: an installation still on
            // http would otherwise get a cookie the browser drops, and nothing
            // would ever land in a basket.
            ->withSecure($secure)
            ->withSameSite(Cookie::SAMESITE_LAX)
        ;
    }

    public static function clearCookie(bool $secure): Cookie
    {
        return Cookie::create(self::NAME, null, 1)
            ->withHttpOnly(true)
            ->withSecure($secure)
            ->withSameSite(Cookie::SAMESITE_LAX)
        ;
    }
}
