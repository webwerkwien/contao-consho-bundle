<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressLookup;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectResolverInterface;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Sends a superseded product address to the one the product has today.
 *
 * ── 🔴 Why this hangs on the RESPONSE, not on the exception ───────────────
 *
 * Because on a Contao site a 404 is usually not an exception at all. An
 * error_404 page is a routable page, and its route is a catch-all:
 *
 *     tl_page.19.error_404    ANY   https   /{_url_fragment}
 *
 * Any unmatched path therefore MATCHES that route, ErrorPageController renders
 * it, and nothing is thrown. Measured on the test server on 2026-09-11: the first
 * version of this class listened on kernel.exception, was correctly registered
 * at priority 64 — debug:event-dispatcher listed it — and was never invoked
 * once.
 *
 * Looking at the response catches both shapes: the routed error page, and the
 * exception-based 404 on an installation without an error page. Both end as a
 * response with status 404.
 *
 * The cost is one indexed query per 404, and the error page is rendered before
 * it is replaced. Both are paid only on a request that already failed.
 *
 * ── 301, not 302 ──────────────────────────────────────────────────────────
 *
 * The old address is permanently replaced. A 302 would let search engines keep
 * the old one, which is the exact thing the table exists to prevent.
 *
 * ── When it deliberately does nothing ─────────────────────────────────────
 *
 * The product is gone or no longer visible → the 404 stands. A redirect onto a
 * 404 page is worse than a direct 404, for the visitor and for the crawler.
 *
 * The site has no product page → nothing to redirect to.
 *
 * The address it would send to is the one that was asked for → the 404 stands.
 * That can only happen when a stale row survives a pattern change back to an
 * earlier shape, and without the guard it would loop forever.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: 64)]
class ProductRedirectListener
{
    public function __construct(
        private readonly RedirectResolverInterface $resolver,
        private readonly ProductAddressLookup $lookup,
        private readonly ProductUrlGenerator $urls,
        private readonly ShopLanguages $languages,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || Response::HTTP_NOT_FOUND !== $event->getResponse()->getStatusCode()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        $target = $this->resolver->find($path, $request->getHost());

        if (null === $target) {
            return;
        }

        /*
         * 🔴 In the startpoint's language, not the default one. Found 2026-09-14:
         * asked without a language, a product lacking its French translation
         * counted as visible, and an old French address was sent to a French
         * product address that answers 404 — the redirect onto a dead end this
         * class exists to avoid.
         */
        if (!$this->lookup->isVisible($target->productId, $target->shopId, time(), $target->language, $this->languages->fallbackOf($target->shopId))) {
            return;
        }

        try {
            $url = $this->urls->generate($target->productId, $target->rootPageId);
        } catch (ProductPageMissing) {
            return;
        }

        if ($this->isTheSameAddress($url, $request->getSchemeAndHttpHost().$path)) {
            return;
        }

        $event->setResponse(new RedirectResponse($url, Response::HTTP_MOVED_PERMANENTLY));
    }

    /**
     * Compared as whole addresses rather than paths, because a redirect across
     * domains is legitimate and must not be mistaken for a loop.
     */
    private function isTheSameAddress(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }
}
