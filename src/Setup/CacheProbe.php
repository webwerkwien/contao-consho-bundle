<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Setup;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * "Jetzt messen" (block I, I4b): whether a page reaches a guest from a cache.
 *
 * Two requests without cookies, like a guest; the second one is read. The
 * first may be the one that fills the cache.
 *
 * ── Three answers, two of them hard ───────────────────────────────────────
 *
 * - cached:  `Age` > 0 or a HIT from a proxy — proof.
 * - private: `private` or `no-store` — proof that nothing shared keeps it.
 * - public:  cacheable, but no trace of a hit. Weak: a proxy may strip its
 *   headers. So it is never reported as "no cache".
 *
 * And `failed` when the server cannot reach itself (password protection,
 * maintenance mode, a host without hairpin routing): the reason is said,
 * not guessed.
 *
 * Measured: a start page answered
 * `public, s-maxage=86400` with `Age: 6368`, the cancellation page — set to
 * the same 24 hours by inheritance — `private, no-store`. The setting alone
 * would have claimed both were cached.
 */
final class CacheProbe
{
    public const CACHED = 'cached';

    public const PRIVATE = 'private';

    public const PUBLIC = 'public';

    public const FAILED = 'failed';

    public const TIMEOUT = 5.0;

    /**
     * @param (\Closure(): void)|null $pause between the two requests
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly \Closure|null $pause = null,
    ) {
    }

    /**
     * @return array{result: string, detail: string}
     */
    public function measure(string $url): array
    {
        try {
            $first = $this->read($url);

            if (!\is_array($first)) {
                return ['result' => self::FAILED, 'detail' => $first];
            }

            // Already decided by the first answer: a page someone else filled,
            // or one no shared cache may keep.
            if (self::PUBLIC !== $first['result']) {
                return $first;
            }

            /*
             * A second later, not at once: Contao's own cache reports `Age`
             * in whole seconds, and an entry stored by the first request is
             * 0 seconds old in the same second — a hit that looks like none.
             */
            null !== $this->pause ? ($this->pause)() : usleep(1_100_000);

            $second = $this->read($url);
        } catch (ExceptionInterface $e) {
            return ['result' => self::FAILED, 'detail' => $e->getMessage()];
        }

        return \is_array($second) ? $second : ['result' => self::FAILED, 'detail' => $second];
    }

    /**
     * @return array{result: string, detail: string}|string the classification, or the reason it failed
     */
    private function read(string $url): array|string
    {
        $response = $this->fetch($url);
        $status = $response->getStatusCode();

        // A 3xx here means the redirects ran out (I): its headers
        // describe the redirect, not the page.
        if ($status >= 300) {
            return 'HTTP '.$status;
        }

        return self::classify($response->getHeaders(false));
    }

    /**
     * @param array<string, list<string>> $headers lower-case names, as the HTTP client delivers them
     *
     * @return array{result: string, detail: string}
     */
    public static function classify(array $headers): array
    {
        $header = static fn (string $name): string => implode(', ', $headers[$name] ?? []);

        $evidence = [];

        if ((int) $header('age') > 0) {
            $evidence[] = 'Age '.(int) $header('age').' s';
        }

        if ('HIT' === strtoupper(trim($header('cf-cache-status')))) {
            $evidence[] = 'Cloudflare HIT';
        }

        if (str_contains(strtoupper($header('x-cache')), 'HIT')) {
            $evidence[] = 'X-Cache: '.$header('x-cache');
        }

        // Varnish names the request and, on a hit, the one that filled it.
        if (str_contains(trim($header('x-varnish')), ' ')) {
            $evidence[] = 'Varnish';
        }

        if ([] !== $evidence) {
            return ['result' => self::CACHED, 'detail' => implode(', ', $evidence)];
        }

        $control = strtolower($header('cache-control'));

        // `no-cache` lets a cache keep the page but never hand it out unasked —
        // for a guest that is the same as not cached (I).
        if (str_contains($control, 'private') || str_contains($control, 'no-store') || str_contains($control, 'no-cache')) {
            return ['result' => self::PRIVATE, 'detail' => $header('cache-control')];
        }

        return ['result' => self::PUBLIC, 'detail' => $header('cache-control')];
    }

    private function fetch(string $url): ResponseInterface
    {
        return $this->client->request('GET', $url, [
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'max_redirects' => 3,
            'headers' => ['User-Agent' => 'Consho cache probe'],
        ]);
    }
}
