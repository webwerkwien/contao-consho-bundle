<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener;

use Contao\CoreBundle\Controller\SitemapController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;
use Webwerkwien\ContaoConshoBundle\EventListener\SitemapCacheLifetimeListener;

class SitemapCacheLifetimeListenerTest extends TestCase
{
    private const NOW = 1_790_000_000;

    private const MONTH = 2592000;

    public function testTheSitemapLivesUntilTheNextProductStarts(): void
    {
        // Wie Contao sie ausliefert: 30 Tage im gemeinsamen Cache.
        $response = $this->respond(SitemapController::class.'::__invoke', self::NOW + 600);

        self::assertSame(600, $response->getMaxAge());
    }

    public function testALaterChangeLeavesContaosLifetime(): void
    {
        // Nur verkürzen, nie verlängern.
        self::assertSame(self::MONTH, $this->respond(SitemapController::class, self::NOW + 2 * self::MONTH)->getMaxAge());
    }

    public function testNothingScheduledLeavesContaosLifetime(): void
    {
        self::assertSame(self::MONTH, $this->respond(SitemapController::class, null)->getMaxAge());
    }

    public function testOtherPagesAreNotTouched(): void
    {
        self::assertSame(self::MONTH, $this->respond('App\Controller\PageController::__invoke', self::NOW + 600)->getMaxAge());
    }

    public function testAPrivateResponseStaysPrivate(): void
    {
        // Angemeldet: MakeResponsePrivateListener hat s-maxage schon entfernt.
        $response = $this->respond(SitemapController::class, self::NOW + 600, shared: false);

        self::assertNull($response->headers->getCacheControlDirective('s-maxage'));
    }

    private function respond(string $controller, int|null $nextChange, bool $shared = true): Response
    {
        $schedule = $this->createMock(DbalCatalogSchedule::class);
        $schedule->method('nextProductChange')->willReturn($nextChange);

        $request = Request::create('/sitemap.xml');
        $request->attributes->set('_controller', $controller);

        $response = new Response();

        if ($shared) {
            $response->setSharedMaxAge(self::MONTH);
        }

        (new SitemapCacheLifetimeListener($schedule, static fn (): int => self::NOW))(
            new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response),
        );

        return $response;
    }
}
