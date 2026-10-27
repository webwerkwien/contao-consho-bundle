<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Setup;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Webwerkwien\ContaoConshoBundle\Setup\CacheProbe;

class CacheProbeTest extends TestCase
{
    public function testDieWerteEinerEchtenInstallation(): void
    {
        // Header values as measured on a real installation.
        self::assertSame(
            ['result' => CacheProbe::CACHED, 'detail' => 'Age 6368 s'],
            CacheProbe::classify(['cache-control' => ['public, s-maxage=86400'], 'age' => ['6368']]),
        );
        self::assertSame(
            ['result' => CacheProbe::PRIVATE, 'detail' => 'must-revalidate, no-cache, no-store, private'],
            CacheProbe::classify(['cache-control' => ['must-revalidate, no-cache, no-store, private'], 'age' => ['0']]),
        );
    }

    public function testProxyTreffer(): void
    {
        self::assertSame('Cloudflare HIT', CacheProbe::classify(['cf-cache-status' => ['HIT']])['detail']);
        self::assertSame('X-Cache: HIT from proxy', CacheProbe::classify(['x-cache' => ['HIT from proxy']])['detail']);
        self::assertSame('Varnish', CacheProbe::classify(['x-varnish' => ['32770 3']])['detail']);
        self::assertSame(CacheProbe::PUBLIC, CacheProbe::classify(['x-varnish' => ['32770'], 'cache-control' => ['public']])['result'], 'Varnish ohne Treffer nennt nur eine Nummer');
        self::assertSame(CacheProbe::PUBLIC, CacheProbe::classify(['cf-cache-status' => ['DYNAMIC']])['result']);
    }

    public function testNoCacheIstFuerDenGastNichtGecacht(): void
    {
        self::assertSame(CacheProbe::PRIVATE, CacheProbe::classify(['cache-control' => ['no-cache']])['result']);
    }

    public function testEineWeiterleitungIstKeineMessung(): void
    {
        // I: after exhausted redirects the headers describe the redirect, not
        // the page.
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 301, 'response_headers' => ['Cache-Control: public, max-age=3600']])]);

        self::assertSame(['result' => CacheProbe::FAILED, 'detail' => 'HTTP 301'], (new CacheProbe($client, static function (): void {}))->measure('https://shop.example/'));
    }

    public function testErlaubtAberOhneTrefferIstNurWeich(): void
    {
        self::assertSame(['result' => CacheProbe::PUBLIC, 'detail' => 'public, s-maxage=3600'], CacheProbe::classify(['cache-control' => ['public, s-maxage=3600'], 'age' => ['0']]));
    }

    public function testErstDerZweiteAbrufZeigtDenEigenenEintrag(): void
    {
        $paused = 0;
        $client = new MockHttpClient([
            new MockResponse('', ['response_headers' => ['Cache-Control: public, s-maxage=3600']]),
            new MockResponse('', ['response_headers' => ['Cache-Control: public, s-maxage=3600', 'Age: 1']]),
        ]);

        $result = (new CacheProbe($client, static function () use (&$paused): void { ++$paused; }))->measure('https://shop.example/');

        self::assertSame(['result' => CacheProbe::CACHED, 'detail' => 'Age 1 s'], $result);
        self::assertSame(1, $paused, 'eine Sekunde dazwischen, sonst ist der eigene Eintrag 0 s alt');
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testEineEindeutigeErsteAntwortBrauchtKeinenZweitenAbruf(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['response_headers' => ['Cache-Control: private']])]);

        self::assertSame(CacheProbe::PRIVATE, (new CacheProbe($client, static function (): void {}))->measure('https://shop.example/')['result']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testNichtErreichbar(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 401])]);
        self::assertSame(['result' => CacheProbe::FAILED, 'detail' => 'HTTP 401'], (new CacheProbe($client, static function (): void {}))->measure('https://shop.example/'));

        $client = new MockHttpClient([new MockResponse('', ['error' => 'Could not resolve host'])]);
        $result = (new CacheProbe($client, static function (): void {}))->measure('https://shop.example/');
        self::assertSame(CacheProbe::FAILED, $result['result']);
        self::assertStringContainsString('Could not resolve host', $result['detail']);
    }
}
