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
use Webwerkwien\ContaoConshoBundle\Customer\MySubscriptionsFlow;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalContext;

/**
 * "Meine Abos" (Abo-7, P60) — the subscriptions of the signed-in member in
 * the shop of the page, each with a way to the order page of its purchase and
 * to the confirmation page of a cancellation.
 *
 * This class finds the page, the shop, its order page and the member; what
 * happens with the request is MySubscriptionsFlow's, where it is tested.
 *
 * ⚠️ The page belongs protected (members only), like "Meine Bestellungen".
 * It does not replace "Verträge hier kündigen": § 312k BGB wants that button
 * without a login.
 */
#[AsContentElement(type: 'consho_my_subscriptions', category: 'consho')]
class MySubscriptionsController extends AbstractContentElementController
{
    public function __construct(
        private readonly ShopResolver $shops,
        private readonly ShopPageUrls $pageUrls,
        private readonly Security $security,
        private readonly MySubscriptionsFlow $flow,
        private readonly PriceAudienceResolver $audiences,
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

        /*
         * "Lizenz verlängern" (Abo-7b, P67) is priced the way the basket will
         * price it: the start point's currency, the shop's country, the
         * visitor's audience. No currency or no basket page: no link.
         */
        $currency = $this->shops->currencyOfPage($page);
        $cartUrl = null === $root ? null : $this->pageUrls->ofRoot($root);
        $audience = $this->audiences->forShop((int) $shop->id);
        $renewal = '' === $currency || null === $cartUrl
            ? null
            : new RenewalContext($currency, (string) $shop->country, $audience->groups, $audience->showsNet, $cartUrl);

        $result = $this->flow->handle(
            $request,
            (int) $shop->id,
            null === $root ? null : $this->pageUrls->orderOfRoot($root),
            $user instanceof FrontendUser ? (int) $user->id : 0,
            (string) ($page->language ?: 'de'),
            $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo(),
            time(),
            $user instanceof FrontendUser ? trim($user->firstname.' '.$user->lastname) : '',
            $renewal,
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
