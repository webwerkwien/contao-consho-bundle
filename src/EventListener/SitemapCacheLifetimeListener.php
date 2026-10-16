<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener;

use Contao\CoreBundle\Controller\SitemapController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;

/**
 * The sitemap lives no longer than until the next product starts or stops
 * (plan B-8).
 *
 * Contao's SitemapController caches /sitemap.xml for thirty days
 * (setSharedMaxAge(2592000)). A product published "from Monday" enters the
 * sitemap on Monday — nobody saves anything then, so no cache tag is
 * invalidated, and the sitemap would miss it for up to a month. Since B-7b the
 * catalogue pages shorten their own lifetime the same way.
 *
 * Price boundaries are not asked here: ListPriceBoundaryCron switches the
 * projection and invalidates the sitemaps itself.
 *
 * Only ever shortens. A response without s-maxage — private because somebody
 * is logged in (MakeResponsePrivateListener) — is left alone.
 */
#[AsEventListener(KernelEvents::RESPONSE)]
final class SitemapCacheLifetimeListener
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly DbalCatalogSchedule $schedule,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getRequest()->attributes->get('_controller');

        // "…\SitemapController" or "…\SitemapController::__invoke", depending on how the route was compiled.
        if (!\is_string($controller) || !str_starts_with($controller, SitemapController::class)) {
            return;
        }

        $response = $event->getResponse();
        $sharedMaxAge = $response->headers->getCacheControlDirective('s-maxage');

        if (!is_numeric($sharedMaxAge)) {
            return;
        }

        $now = null !== $this->clock ? ($this->clock)() : time();
        $next = $this->schedule->nextProductChange($now);

        if (null === $next) {
            return;
        }

        $response->setSharedMaxAge(max(1, min((int) $sharedMaxAge, $next - $now)));
    }
}
