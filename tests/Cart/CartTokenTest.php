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
        // Eine Testinstallation ohne Zertifikat bekäme sonst gar kein Cookie.
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
        // Sonst landet Fremdeingabe ungeprüft in einer Abfrage.
        $request = new Request();
        $request->cookies->set(CartToken::NAME, 'nicht base64url!');

        self::assertNull(CartToken::fromRequest($request));
    }

    public function testEinAngehaengterZeilenumbruchGiltNichtAlsToken(): void
    {
        // PCRE lässt bei $ einen abschließenden Zeilenumbruch durch. Über eine
        // echte Cookie-Kopfzeile kommt der nicht, über einen im Code gebauten
        // Request schon — ein Subrequest, ein Test, ein nachlässiger Proxy.
        $request = new Request();
        $request->cookies->set(CartToken::NAME, CartToken::generate()."\n");

        self::assertNull(CartToken::fromRequest($request));
    }

    public function testEinFremdesZeichenInRichtigerLaengeGiltNichtAlsToken(): void
    {
        // Standard-Base64 statt base64url: richtige Länge, falsches Alphabet.
        $request = new Request();
        $request->cookies->set(CartToken::NAME, str_repeat('a', 42).'+');

        self::assertNull(CartToken::fromRequest($request));
    }

    public function testDasLoeschCookieTraegtDieselbenSchutzmerkmale(): void
    {
        // Ein Löschcookie, das der Browser wegen abweichender Merkmale nicht
        // dem gesetzten zuordnet, löscht nichts.
        $cookie = CartToken::clearCookie(true);

        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertFalse(CartToken::clearCookie(false)->isSecure());
    }

    public function testEinWertMitFalscherLaengeGiltAlsKeiner(): void
    {
        // Ein Cookie, das nicht von generate() stammt, ist keines von uns.
        // Die Längenprüfung kostet nichts und wirft alles weg, was nur
        // zufällig aus erlaubten Zeichen besteht.
        $request = new Request();
        $request->cookies->set(CartToken::NAME, 'abc');

        self::assertNull(CartToken::fromRequest($request));

        $request->cookies->set(CartToken::NAME, str_repeat('a', 44));

        self::assertNull(CartToken::fromRequest($request));
    }
}
