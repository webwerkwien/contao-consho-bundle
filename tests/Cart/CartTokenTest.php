<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Cart\CartLifetime;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;

class CartTokenTest extends TestCase
{
    public function testJedesTokenIstAndersUndUrlSicher(): void
    {
        $first = CartToken::generate();
        $second = CartToken::generate();

        self::assertNotSame($first, $second);
        self::assertSame(43, \strlen($first));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $first);
    }

    public function testDerHashIstSha256(): void
    {
        self::assertSame(hash('sha256', 'abc'), CartToken::hash('abc'));
    }

    public function testDasCookieIstLaxHttpOnlyUndSecureAufHttps(): void
    {
        $cookie = CartToken::cookie('token', true, 1_000_000);

        self::assertSame(CartToken::NAME, $cookie->getName());
        self::assertSame('token', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame(1_000_000 + CartLifetime::COOKIE, $cookie->getExpiresTime());
    }

    public function testOhneHttpsIstDasCookieNichtSecure(): void
    {
        // A test installation without a certificate would otherwise get no cookie at all.
        self::assertFalse(CartToken::cookie('token', false, 1_000_000)->isSecure());
    }

    public function testDasLoeschCookieIstLeerUndAbgelaufen(): void
    {
        $cookie = CartToken::clearCookie(true);

        self::assertSame(CartToken::NAME, $cookie->getName());
        self::assertNull($cookie->getValue());
        self::assertSame(1, $cookie->getExpiresTime());
    }

    public function testDerTokenWirdAusDemRequestGelesen(): void
    {
        $token = CartToken::generate();

        $request = new Request();
        $request->cookies->set(CartToken::NAME, $token);

        self::assertSame($token, CartToken::fromRequest($request));
        self::assertNull(CartToken::fromRequest(new Request()));
    }

    public function testEinUnsinnigerCookieWertGiltAlsKeiner(): void
    {
        // Otherwise foreign input ends up unchecked in a query.
        $request = new Request();
        $request->cookies->set(CartToken::NAME, 'nicht base64url!');

        self::assertNull(CartToken::fromRequest($request));
    }

    public function testEinAngehaengterZeilenumbruchGiltNichtAlsToken(): void
    {
        // PCRE lets a trailing line break through at $. It does not arrive via a
        // real cookie header line, but it does via a request built in code —
        // a subrequest, a test, a sloppy proxy.
        $request = new Request();
        $request->cookies->set(CartToken::NAME, CartToken::generate()."\n");

        self::assertNull(CartToken::fromRequest($request));
    }

    public function testEinFremdesZeichenInRichtigerLaengeGiltNichtAlsToken(): void
    {
        // Standard Base64 instead of base64url: right length, wrong alphabet.
        $request = new Request();
        $request->cookies->set(CartToken::NAME, str_repeat('a', 42).'+');

        self::assertNull(CartToken::fromRequest($request));
    }

    public function testDasLoeschCookieTraegtDieselbenSchutzmerkmale(): void
    {
        // A deletion cookie that the browser does not match to the one it set,
        // because of differing attributes, deletes nothing.
        $cookie = CartToken::clearCookie(true);

        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertFalse(CartToken::clearCookie(false)->isSecure());
    }

    public function testEinWertMitFalscherLaengeGiltAlsKeiner(): void
    {
        // A cookie that does not come from generate() is not one of ours.
        // The length check costs nothing and throws away everything that just
        // happens to consist of allowed characters.
        $request = new Request();
        $request->cookies->set(CartToken::NAME, 'abc');

        self::assertNull(CartToken::fromRequest($request));

        $request->cookies->set(CartToken::NAME, str_repeat('a', 44));

        self::assertNull(CartToken::fromRequest($request));
    }
}
