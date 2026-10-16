<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCostsView;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * "Versandkosten" (E-6, V5): the shop's shipping methods with countries,
 * prices and limits, read from the data (ShippingCostsView). The page that
 * carries it is chosen at the start point (`consho_shippingCostsPage`).
 */
#[AsContentElement(type: 'consho_shipping_costs', category: 'consho')]
class ShippingCostsController extends AbstractContentElementController
{
    public function __construct(
        private readonly ShopResolver $shops,
        private readonly ShopLanguages $languages,
        private readonly ShippingCostsView $view,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $page = $request->attributes->get('pageModel');
        $shop = $page instanceof PageModel ? $this->shops->resolveForPage($page) : null;

        if (!$page instanceof PageModel || null === $shop) {
            return new Response('');
        }

        $shopId = (int) $shop->id;
        $language = (string) ($page->language ?: 'de');

        foreach ($this->view->of(
            $shopId,
            $this->shops->currencyOfPage($page),
            (string) $shop->country,
            $language,
            $this->languages->fallbackOf($shopId),
            time(),
        ) as $name => $value) {
            $template->set($name, $value);
        }

        return $template->getResponse();
    }
}
