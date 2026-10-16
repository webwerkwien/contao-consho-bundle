<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FrontendUser;
use Contao\PageModel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Customer\MyOrdersFlow;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * "Meine Bestellungen" (D-4, K3) — the orders of the signed-in member in the
 * shop of the page, each a way into the ORDER PAGE, not a second view of the
 * order (spec 8: „Kein zweiter Weg zur Bestellung").
 *
 * This class finds the page, the shop, its order page and the member; what
 * happens with the request is MyOrdersFlow's, where it is tested.
 *
 * ⚠️ The page belongs protected (members only), and Contao then answers a
 * signed-out visitor before this element runs. The confirmation mail and the
 * notice therefore say "sign in first".
 */
#[AsContentElement(type: 'consho_my_orders', category: 'consho')]
class MyOrdersController extends AbstractContentElementController
{
    public function __construct(
        private readonly ShopResolver $shops,
        private readonly ShopPageUrls $pageUrls,
        private readonly Security $security,
        private readonly MyOrdersFlow $flow,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $page = $request->attributes->get('pageModel');
        $shop = $page instanceof PageModel ? $this->shops->resolveForPage($page) : null;

        if (!$page instanceof PageModel || null === $shop) {
            return new Response('');
        }

        $user = $this->security->getUser();
        $root = $this->shops->rootOf($page);

        $result = $this->flow->handle(
            $request,
            (int) $shop->id,
            null === $root ? null : $this->pageUrls->orderOfRoot($root),
            $user instanceof FrontendUser ? (int) $user->id : 0,
            $user instanceof FrontendUser ? (string) $user->email : '',
            (string) ($page->language ?: 'de'),
            $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo(),
            time(),
        );

        if (isset($result['redirect'])) {
            throw new RedirectResponseException($result['redirect'], Response::HTTP_SEE_OTHER);
        }

        if (null === $result['view']) {
            return new Response('');
        }

        foreach ($result['view'] as $name => $value) {
            $template->set($name, $value);
        }

        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
