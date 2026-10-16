<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Cart\PurchaseCheck;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * "Add to basket" (C11, 7.1).
 *
 * ── Why this is not a normal Contao form ─────────────────────────────────
 *
 * The button sits on a catalogue page that is cached and SHARED between
 * visitors. A Contao request token in that form would be one visitor's token
 * served to everybody else, and the next POST would be refused with "Invalid
 * CSRF token". So the route takes _token_check => false and answers the same
 * question differently:
 *
 *   1. The basket cookie is SameSite=Lax, so a cross-site POST does not carry
 *      it and cannot reach anybody's basket.
 *   2. The Origin header must match this host exactly. Browsers send it on
 *      every POST; a missing one is refused rather than trusted.
 *
 * Both together, not either: Lax alone would still allow a top-level POST from
 * a form on another site to CREATE a basket, and the Origin check alone would
 * not protect an existing one.
 *
 * Switching off a framework's security check is a decision here, not an
 * oversight — that is what this block is for. Anybody who removes the Origin
 * check has to put the request token back, and cannot, as long as the page is
 * shared-cached.
 *
 * ── What the Origin check depends on ────────────────────────────────────
 *
 * originMatches() compares against Request::getSchemeAndHttpHost(), and that
 * value is not simply the Host header — Symfony rewrites it from
 * X-Forwarded-Host/X-Forwarded-Proto whenever the request is recognised as
 * coming through a trusted proxy (framework.trusted_proxies /
 * setTrustedProxies()). The check is therefore only as good as that setting,
 * in both directions:
 *
 *   - Too wide (missing, "*", or an IP range that reaches further than the
 *     real load balancer) and an attacker talking to this app directly can
 *     set X-Forwarded-Host to whatever they like. getSchemeAndHttpHost()
 *     then simply echoes it back, the comparison against Origin becomes
 *     self-referential, and the check passes for anyone.
 *   - Not set at all while a real proxy sits in front, and
 *     getSchemeAndHttpHost() falls back to the Host header the proxy itself
 *     sent — its internal name, not the public one a browser's Origin is
 *     built from. Nothing ever matches, and the route refuses every visitor.
 *
 * Neither failure logs or throws; the route just quietly does the wrong
 * thing. Whoever changes this installation's proxy or load-balancer setup
 * has to re-verify this check by hand.
 *
 * ── Nothing is echoed ────────────────────────────────────────────────────
 *
 * The answer is always a 303 to a page; the result is a success notice on the
 * basket row or, for a refusal, an enum case in the address (C-3c) — never a
 * message in a session (C9). Nothing the visitor sent comes back in this
 * response: not in the body, which stays empty, and not in the Location, which
 * is always an address this installation produced itself or a referer that
 * passed sameOriginOrNull(), plus a parameter whose value is one of this
 * bundle's own enum cases. The buy button looks that value up as an
 * enumeration and renders nothing for anything else, so the query string is
 * not a way into the page either.
 *
 * ── A refusal leaves nothing behind ──────────────────────────────────────
 *
 * No row, no cookie. The route is open to the net; a bot posting nonsense
 * would otherwise fill tl_consho_cart with empty baskets and collect cookies
 * on the way out.
 *
 * That used to cost a gap: somebody with no basket yet got no feedback at all,
 * because there was no row the reason could be written on — and C-3b made that
 * the common case rather than the first-visit one, since a basket is now found
 * within its shop. **C-3c closed it** by taking the reason off the row
 * entirely: it rides in the address and the buy button renders it, so a
 * refusal explains itself without anything being stored. Creating a row to
 * hold it, which this paragraph rules out, is no longer even tempting.
 *
 * What remains is a different question: what put a buy button for something
 * unavailable in front of that visitor is a cached page that outlived the
 * article — a cache tag question, and one for later.
 *
 * ── Why #[AsController] ──────────────────────────────────────────────────
 *
 * The bundle's services are private. Without the tag that this attribute
 * autoconfigures, RegisterControllerArgumentLocatorsPass never makes this one
 * public, the controller resolver cannot fetch it from the container and falls
 * back to `new AddToCartController()` — which dies on the constructor. The
 * route would exist and still never work.
 */
#[AsController]
class AddToCartController
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly CartResolver $carts,
        private readonly DbalCartRepository $repository,
        private readonly PurchaseCheck $check,
        private readonly ShopResolver $shops,
        private readonly ShopLanguages $languages,
        private readonly ContentUrlGenerator $urls,
        private readonly ShopPageUrls $cartPage,
        private readonly PriceAudienceResolver $audiences,
        private readonly Availability $availability,
    ) {
    }

    #[Route(
        path: '/_consho/cart/add',
        name: 'consho_cart_add',
        defaults: ['_scope' => 'frontend', '_token_check' => false],
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if (!self::originMatches($request)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $this->framework->initialize();

        $pages = $this->framework->getAdapter(PageModel::class);

        /*
         * Read as a string and cast, rather than through getInt(): InputBag
         * throws a BadRequestException for anything FILTER_VALIDATE_INT
         * refuses, and this route is posted to by whoever feels like it. A
         * refusal belongs in the notice, not in the error log — "article=x"
         * becomes 0, which no product has, and the visitor gets the same
         * "not available" as for a deleted one.
         */
        $page = $pages->findById((int) $request->request->getString('page'));

        if (!$page instanceof PageModel) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        /*
         * loadDetails() first, and this is not optional: `rootId` is no column
         * of tl_page but a property PageModel fills in while walking up the
         * tree. A page straight out of findById() has never walked it, so
         * `rootId` is empty — and "the root of this page" collapses to the
         * page itself, which carries no `consho_shop`. Every page a visitor
         * actually buys from is a sub page, so without this the route answered
         * 400 to every single request (found on c5 on 2026-09-20, with a green
         * suite behind it — the page double handed out `rootId` unasked).
         *
         * The front end index calls loadDetails() before it hands a page to a
         * content element; that is why ProductDetailController and the two
         * basket elements may read `rootId` straight off. This route gets its
         * page from a POST field and has to walk the tree itself.
         */
        $page->loadDetails();

        /*
         * The root page is needed twice over: for the language below and for
         * the basket page further down, so it is read once here — through
         * ShopResolver, which holds the one rule for "the root of a page"
         * (ShopPageUrls asks it the same question).
         */
        $root = $this->shops->rootOf($page);

        if (!$root instanceof PageModel) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        /*
         * $root is already loaded above (needed for the language below and the
         * basket page further down), so the shop is read off it directly
         * instead of going through ShopResolver::resolveForPage() — that would
         * resolve the same root page a second time for every non-root page.
         */
        $shop = $this->shops->resolveById($this->shops->shopIdOfPage($root));

        if (!$shop instanceof ShopModel) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $shopId = (int) $shop->id;
        $articleId = (int) $request->request->getString('article');
        $now = time();

        $cart = $this->carts->resolve($request, $shopId);

        /*
         * No guard against a basket from another shop any more, and none is
         * needed: resolve() is asked for THIS shop and answers with a basket
         * of this shop or with none (C-3b). What the guard prevented — an
         * article silently moved into another shop's basket, a refusal
         * written onto a basket that never offered the article — cannot
         * arise once the question carries the shop. CartNotice::OtherShop
         * went with it; the case it named no longer exists, because the
         * visitor simply gets the basket of the shop they are standing in.
         */

        /*
         * Language and fallback language belong to the check: without them
         * only four of the five visibility rules apply, and an article this
         * page does not even show would go into a basket from it.
         *
         * $root->language, not $page->language, and that stays true now that
         * loadDetails() runs above: loadDetails() overwrites a sub page's
         * `language` with its start point's, so both would answer the same —
         * but only the root page's own column says so without depending on a
         * call made somewhere else in this method. Reading it off the root is
         * the same rule ProductUrlGenerator follows, and the fallback is the
         * shop's.
         */
        /*
         * The plan (Abo-2, P10). No field at all is the one-time sale: every
         * form without a plan choice stays valid. A field is read as DIGITS
         * ONLY — Abo-1 (bf4dda4) showed what a cast does: "Monatsabo" became
         * 0, the one-time sale, and the wrong contract went through without a
         * word. -1 is offered by nothing and ends as "not offered".
         *
         * Who is asking decides what is offered (P11): the visitor's groups —
         * since variant A (2026-10-08) alone; "sameTerm" is no longer held
         * back from consumers by the core, the rule's groups do it.
         */
        $planInput = $request->request->has('subscriptionRule') ? $request->request->getString('subscriptionRule') : '0';
        $subscriptionRule = 1 === preg_match('/^\d{1,9}$/', $planInput) ? (int) $planInput : -1;
        $audience = $this->audiences->forShop($shopId);

        $verdict = $this->check->check(
            $articleId,
            $shopId,
            $request->request->getString('quantity', '1'),
            $now,
            (string) $root->language,
            $this->languages->fallbackOf($shopId),
            $subscriptionRule,
            $audience->groups,
        );

        /*
         * ->allowed is the question; the second half is what lets the compiler
         * carry the answer down to addItem(). PurchaseVerdict nulls the
         * quantity on every refusal, so the null check only ever repeats what
         * ->allowed already said — and repeating it is cheaper than a cast,
         * which would keep quiet the day the two stop agreeing.
         */
        if (!$verdict->allowed || null === $verdict->quantity) {
            return $this->refuse($request, $page, $root, $shop, $cart?->id, $verdict->notice());
        }

        /*
         * Block G (G5, G7): sold out is refused here, more than is left is
         * not. The basket holds nothing back; it shows how many are left and
         * the checkout waits until the quantity fits. Here, in the add route
         * and not in PurchaseCheck: the basket page asks PurchaseCheck too,
         * and lowering the quantity of a sold-out line must stay possible.
         */
        if (!$this->availability->for($articleId)->state->purchasable()) {
            return $this->refuse($request, $page, $root, $shop, $cart?->id, CartNotice::OutOfStock);
        }

        $memberId = $this->carts->memberId();
        $existing = CartToken::fromRequest($request);
        $issue = null;

        if (null === $cart) {
            if ($memberId > 0) {
                // A member's basket follows the account, not the browser: no
                // hash, and the guest cookie is dropped at login
                // (CartTakeoverListener).
                $cartId = $this->repository->create(null, $memberId, $shopId, $now);
            } else {
                // Generated only here, where a token is actually needed —
                // random_bytes() has no reason to run for a logged-in member,
                // who never gets a token issued at all.
                $token = $existing ?? CartToken::generate();
                $cartId = $this->repository->create(CartToken::hash($token), $memberId, $shopId, $now);

                // The cookie goes out only where the request brought none: a
                // guest who already has one keeps it.
                $issue = self::needsCookie($existing) ? $token : null;
            }
        } else {
            $cartId = $cart->id;
        }

        /*
         * addItem() answers false when the line would grow past what the
         * column can hold — nothing was written then, not even updatedAt.
         * Swallowing that would tell the visitor "added" and show the old
         * quantity. It is a refusal like any other and takes the same road:
         * back to the page, with the reason in the address (C-3c).
         */
        if (!$this->repository->addItem($cartId, $articleId, $verdict->quantity, $now, $subscriptionRule)) {
            // With $issue, unlike refuse(): the basket was created moments ago
            // and is real. Withholding its cookie now would orphan a row the
            // visitor can never reach again.
            return $this->redirect($request, $page, $root, $shop, $issue, refusal: CartNotice::InvalidQuantity);
        }

        /*
         * No success notice in "back to the page" mode (decided 2026-09-20).
         * The answer there is the number in the mini basket going up — a theme
         * can make it wiggle. And it settles a second thing: a notice has no
         * expiry, so one written here would sit in the row until the basket
         * page is opened, which in this mode may be days later. "Was added to
         * your basket" about an add the visitor has long forgotten is worse
         * than nothing.
         *
         * In "to the basket" mode the redirect goes straight to the page that
         * renders it, so the question of an expiry never arises.
         */
        if ('page' !== (string) $shop->afterAddToCart) {
            $this->repository->setNotice($cartId, CartNotice::Added);
        }

        return $this->redirect($request, $page, $root, $shop, $issue);
    }

    /**
     * Exactly this scheme, host and port — nothing else. A sub domain is a
     * different origin, and so is the same host over http.
     *
     * A missing header is refused rather than trusted. Browsers send Origin on
     * every POST, so the only requests without one are not browsers; treating
     * "absent" as "same site" is the hole every such check is written to close.
     */
    public static function originMatches(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if (null === $origin || '' === $origin) {
            return false;
        }

        return $origin === $request->getSchemeAndHttpHost();
    }

    /**
     * An absolute address on this very origin, or null.
     *
     * The trailing slash is part of the prefix on purpose: without it
     * "https://shop.example.evil.test/" would start with "https://shop.example"
     * and pass. An open redirect stays an open redirect even when it only ever
     * carries a visitor away from a shop.
     */
    public static function sameOriginOrNull(Request $request, string $url): string|null
    {
        if ('' === $url || !str_starts_with($url, $request->getSchemeAndHttpHost().'/')) {
            return null;
        }

        return $url;
    }

    /**
     * Whether the ANSWER has to carry a Set-Cookie — a question about what the
     * request brought, not about what happened to the basket. A guest whose
     * basket the cleanup cron has taken gets a new one under the hash of the
     * token they still hold; re-issuing it would send them the value their
     * browser already has.
     *
     * Private: the tests that used to call it asserted null === null. What it
     * promises is visible in the response headers, and that is where the three
     * cases are measured now.
     */
    private static function needsCookie(string|null $token): bool
    {
        return null === $token;
    }

    /**
     * A refusal says so where the visitor clicked, not on a basket row (C-3c).
     *
     * The basket only enters the picture once something worked. Writing the
     * reason onto a row meant the visitor had to be sent to the basket page to
     * read it — and left anyone without a basket in that shop with no feedback
     * at all, which C-3b made the common case rather than the first-visit one.
     *
     * The reason travels in the address instead. A cookie or the session would
     * take the whole page out of the shared cache; the address costs one cache
     * entry for this one call, which is the call AFTER a failed click, never
     * the normal path.
     */
    private function refuse(Request $request, PageModel $page, PageModel $root, ShopModel $shop, int|null $cartId, CartNotice $notice): RedirectResponse
    {
        return $this->redirect($request, $page, $root, $shop, null, refusal: $notice);
    }

    private function redirect(Request $request, PageModel $page, PageModel $root, ShopModel $shop, string|null $issue, CartNotice|null $refusal = null): RedirectResponse
    {
        $target = self::withNotice($this->targetUrl($request, $page, $root, $shop, null !== $refusal), $refusal);

        $response = new RedirectResponse($target, Response::HTTP_SEE_OTHER);

        if (null !== $issue) {
            $response->headers->setCookie(CartToken::cookie($issue, $request->isSecure(), time()));
        }

        return $response;
    }

    /**
     * Puts exactly one reason on the address — or takes the one there off.
     *
     * ⚠️ The target of a "back to the page" redirect is the REFERER, and after
     * a refusal that referer already carries consho_notice. Appending blindly
     * broke in both directions (found in the C-3c review):
     *
     *   - a refusal corrected and re-clicked succeeded, and the visitor was
     *     sent back to ...?consho_notice=invalid_quantity — the mini basket
     *     counted up while the old refusal stood next to it. A WRONG message
     *     is worse than the silence C-3c set out to end.
     *   - three refusals in a row produced consho_notice three times over. PHP
     *     reads the last one, so the page was right, but the address grew with
     *     every click and each length is its own entry in the shared cache —
     *     which is exactly the cost this design promised not to have.
     *
     * The fragment is split off first: "page.html#top" would otherwise become
     * "page.html#top?consho_notice=…", where the parameter sits INSIDE the
     * fragment and never reaches the server.
     */
    private static function withNotice(string $url, CartNotice|null $notice): string
    {
        [$withoutFragment, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $query] = array_pad(explode('?', (string) $withoutFragment, 2), 2, '');

        parse_str((string) $query, $params);
        unset($params[CartNotice::PARAM]);

        if (null !== $notice) {
            $params[CartNotice::PARAM] = $notice->value;
        }

        $rebuilt = http_build_query($params);

        return $path
            .('' === $rebuilt ? '' : '?'.$rebuilt)
            .(null === $fragment ? '' : '#'.$fragment);
    }

    /**
     * "Back to the page" or "to the basket", as the shop is set (C12) — except
     * for a refusal, which goes back to where the click happened (C-3c),
     * because that is where the reason is rendered.
     *
     * Which page that is comes from ShopPageUrls, the one place that reads
     * consho_cartPage — the mini basket links to the same page, and two
     * readings of one field drift apart.
     */
    private function targetUrl(Request $request, PageModel $page, PageModel $root, ShopModel $shop, bool $refused): string
    {
        $cartPage = $this->cartPage->ofRoot($root);

        /*
         * A refusal goes BACK to where the click happened, whatever
         * afterAddToCart says (C-3c, reversing the 2026-09-20 decision). The
         * reason now rides in the address and is rendered by the buy button,
         * so the page the visitor came from is the one place that can show it.
         *
         * The referer is what carries the product: a product page's address is
         * product-specific, and $page alone would only give the page without
         * one. Where there is no same-origin referer, that page is still the
         * better guess than the basket — the basket no longer renders this
         * reason at all.
         */
        if ($refused) {
            return self::sameOriginOrNull($request, $request->headers->get('referer', ''))
                ?? $this->addressOf($page)
                ?? $request->getSchemeAndHttpHost().'/';
        }

        if ('page' === (string) $shop->afterAddToCart) {
            $referer = self::sameOriginOrNull($request, $request->headers->get('referer', ''));

            if (null !== $referer) {
                return $referer;
            }

            // No usable referer: the basket page, not the product page
            // (specification 7.1 step 4, "nur auf dieselbe Herkunft, sonst
            // Warenkorbseite"). The product page would be the nicer guess —
            // the form names it, so it is known — but "back to the page" is a
            // promise about where the visitor CAME FROM, and that is exactly
            // what is missing here. Sending them to the basket at least shows
            // them what happened; sending them to a page they may never have
            // seen does not.
        }

        // No basket page configured, or one without a route: back to where the
        // visitor was. The finding in C-6 nags about it in the back end; a dead
        // link would not help the visitor in the meantime.
        return $cartPage ?? $this->addressOf($page) ?? $request->getSchemeAndHttpHost().'/';
    }

    /**
     * ContentUrlGenerator throws for a page without a route — an unpublished
     * basket page, or one in a site that has no root. The route still has to
     * answer, so the caller falls through to the next best address.
     */
    private function addressOf(PageModel $page): string|null
    {
        try {
            return $this->urls->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
