<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tax\NotTaxableArticles;

/**
 * The mini basket (C10): how much is in the basket, anywhere on the page — a
 * layout section included.
 *
 * ── Why an ESI fragment, and why a prebuilt response ──────────────────────
 *
 * This element stands on EVERY page, catalogue pages among them, and those are
 * cached for everybody at once. What it shows belongs to one visitor. Both can
 * only be true if the element is fetched separately from the page it sits on,
 * which is what `renderer: 'esi'` asks for (without it, `forward` applies and
 * Contao renders the element inline).
 *
 * That alone is not enough. `AbstractFragmentController::render()` sets the
 * header `Contao-Merge-Cache-Control` unless it is handed a response of its own
 * ("Pass a prebuilt Response if you want to have full control - no headers will
 * be set then", AbstractFragmentController.php:236-241). With that header,
 * `SubrequestCacheSubscriber` merges this fragment's cache control into the
 * page's — and `private, no-store` would take EVERY page carrying a mini basket
 * out of the shared cache the moment ESI is not in play (no reverse proxy, a
 * development setup, a proxy that does not do ESI). The page would then be
 * uncacheable because of an element that was introduced to keep it cacheable.
 *
 * So the response is built here and handed to the template. It keeps its own
 * headers to itself, and the page keeps its own lifetime.
 *
 * ⚠️ The basket element (`CartController`) deliberately does the opposite: it
 * calls `$template->getResponse()` WITHOUT an argument, so that its
 * `private, no-store` does travel up and the whole basket page becomes private.
 * That is right there and would be wrong here.
 *
 * The measurement in C-12 (13.2) confirms or corrects the ESI half of this;
 * until then the specification calls it an expectation, not a fact. The header
 * half is not an expectation — it is in the code above.
 *
 * ── What the badge counts ────────────────────────────────────────────────
 *
 * Lines, not pieces. `CartView::quantity` is the sum of the quantities and
 * therefore a decimal: a shop selling by the kilo would put "2.5" in a badge.
 * It also counts only what could be priced, so a basket holding nothing but
 * articles that lost their price would read 0 — while those are exactly the
 * lines the visitor has to go and remove. `CartView::count()` counts them, and
 * is a whole number by construction.
 */
#[AsContentElement(type: 'consho_mini_cart', category: 'consho', renderer: 'esi')]
class MiniCartController extends AbstractContentElementController
{
    public function __construct(
        private readonly CartResolver $carts,
        private readonly DbalCartRepository $repository,
        private readonly CartPricer $pricer,
        private readonly ShopResolver $shops,
        private readonly ShopLanguages $languages,
        private readonly MoneyFormatter $money,
        private readonly ShopPageUrls $cartPage,
        private readonly PriceAudienceResolver $audiences,
        /*
         * The kind of the article (DL1): "zzgl.
         * Versandkosten" only where something is shipped. Optional for the
         * tests built by hand — without it the sentence stays as it was.
         */
        private readonly ArticleShippingDataInterface|null $articles = null,
        // Block Wertgutscheine (WG-7): the tax sentence.
        private readonly NotTaxableArticles|null $notTaxable = null,
    ) {
    }

    /**
     * A response of our own — see the class comment. Never cached anywhere: it
     * shows one visitor's basket.
     *
     * @internal public for the test only
     */
    public static function freshResponse(): Response
    {
        $response = new Response();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        // The page model of this request. In an ESI sub-request FragmentHandler
        // passes the page id along and PageAccessListener turns it back into a
        // model with loadDetails() called, so this is the same object the main
        // request had.
        $page = $request->attributes->get('pageModel');
        $shop = $page instanceof PageModel ? $this->shops->resolveForPage($page) : null;

        // Read before the guard for the reason given in CartController: an
        // empty currency would reach MoneyFormatter and print "0,00 XXX" — on
        // every page carrying this element, because the mini basket renders
        // even when it is empty.
        $currency = $page instanceof PageModel ? $this->shops->currencyOfPage($page) : '';

        if (!$page instanceof PageModel || null === $shop || '' === $currency) {
            // No shop on this site, or no currency on this start point:
            // nothing to show, and still no merge header.
            return self::freshResponse();
        }

        $language = (string) $page->language;

        // An empty page language happens where the page was not loaded through
        // loadDetails(); the shop's fallback is the language the amount is then
        // formatted in. Same assignment as in CartController.
        if ('' === $language) {
            $language = $this->languages->fallbackOf((int) $shop->id);
        }

        $cart = $this->carts->resolve($request, (int) $shop->id);
        $items = null === $cart ? [] : $this->repository->itemsOf($cart->id);
        // Currency from the start point (read above), home country from the
        // shop — the same split as in CartController (spec 4.0).
        // Same audience question as the basket page and the product page
        // (C-4). The mini basket is an ESI fragment and therefore never in the
        // shared cache, so a personal amount is safe here — see the class
        // comment on freshResponse().
        $audience = $this->audiences->forShop((int) $shop->id);

        $view = $this->pricer->price($items, $currency, (string) $shop->country, (string) $shop->country, time(), $audience->groups);

        $template->set('line_count', $view->count());
        $template->set('total', $this->money->format($audience->showsNet ? $view->net : $view->gross, $view->currency, $language));
        $template->set('showsNet', $audience->showsNet);
        $template->set('shipped', $this->anyShipped(array_map(static fn ($item): int => $item->articleId, $items)));
        $template->set('untaxed', $this->notTaxable?->all(array_map(static fn ($item): int => $item->articleId, $items)) ?? false);
        $template->set('cart_page', $this->cartPage->ofPage($page));

        return $template->getResponse(self::freshResponse());
    }

    /**
     * Whether any of these articles is shipped (DL): the
     * basket says "zzgl. Versandkosten" only then. Without the reader, or
     * with an empty basket, as it always did.
     *
     * @param list<int> $articleIds
     */
    private function anyShipped(array $articleIds): bool
    {
        if (null === $this->articles || [] === $articleIds) {
            return true;
        }

        foreach ($this->articles->of($articleIds) as $data) {
            if (ProductKind::stored($data['kind'])->isPhysical()) {
                return true;
            }
        }

        return false;
    }
}
