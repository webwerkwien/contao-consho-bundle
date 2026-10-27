<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\Cart;
use Webwerkwien\ContaoConshoBundle\Cart\CartAgeLimit;
use Webwerkwien\ContaoConshoBundle\Cart\CartItemRow;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitle;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitles;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Cart\CartView;
use Webwerkwien\ContaoConshoBundle\Cart\CodeEntry;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscount;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CodeStatus;
use Webwerkwien\ContaoConshoBundle\Cart\PurchaseCheck;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherCode;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;
use Webwerkwien\ContaoConshoBundle\Tax\NotTaxableArticles;
use Webwerkwien\ContaoConshoBundle\Voucher\CoverablePots;

/**
 * The basket page (4.2): lines, quantities, remove, on to the checkout.
 *
 * ── An ordinary Contao form, WITH a request token ─────────────────────────
 *
 * Unlike the add route this is an ordinary POST with Contao's own CSRF check:
 * the basket page is private anyway (10), so the token printed into it belongs
 * to the visitor reading it. The exception in C11 buys the CATALOGUE page its
 * shared cache and is needed nowhere else. Nothing has to be coded for the
 * check itself — RequestTokenListener runs on the main request and refuses a
 * POST without a valid REQUEST_TOKEN before this element is ever rendered.
 *
 * ── One form PER LINE, not one form around the table ──────────────────────
 *
 * A browser submits EVERY field of the form the button belongs to. One form
 * around the whole table would therefore send the `item` of every line at
 * once, and `$request->request->get('item')` would take the last one — the
 * visitor changes a line they never touched. Putting the line id on the button
 * instead (`value="update:42"`) fixes only half of it: the `quantity` fields
 * of all the other lines still travel along, and the one that arrives is again
 * the last. Each line therefore carries its own <form>, inside a <td>, which
 * is where flow content is allowed — a <form> as a child of <table> or <tr> is
 * invalid HTML and browsers move it out of the table.
 *
 * With that, `item` and `quantity` are unambiguous per submit and this class
 * needs no encoding of its own to take apart.
 *
 * ── The answer to a POST ──────────────────────────────────────────────────
 *
 * Always a 303 to the basket page: a reload does not repeat the change, and
 * the browser's back button does not offer to. It is thrown rather than
 * returned, because a content element renders as a sub-request whose Response
 * becomes a string inside the page — a RedirectResponse returned from here
 * would be rendered as an empty element and nothing would move.
 * RedirectResponseException (303 by default) bubbles out of the sub-request
 * and ResponseExceptionListener turns it into the real answer.
 *
 * The address comes from ContentUrlGenerator, not from $request->getUri():
 * getAbsoluteUrl() is deprecated since Contao 5.3, and an address this
 * installation produced itself keeps anything the visitor sent out of the
 * Location header — the same rule the add route follows.
 */
#[AsContentElement(type: 'consho_cart', category: 'consho')]
class CartController extends AbstractContentElementController
{
    public function __construct(
        private readonly CartResolver $carts,
        private readonly DbalCartRepository $repository,
        private readonly CartPricer $pricer,
        private readonly CartTitles $titles,
        private readonly PurchaseCheck $check,
        private readonly ShopResolver $shops,
        private readonly ShopLanguages $languages,
        private readonly MoneyFormatter $money,
        private readonly ContentUrlGenerator $urls,
        private readonly PriceAudienceResolver $audiences,
        private readonly CartAgeLimit $ageLimits,
        private readonly ShopPageUrls $pages,
        // Block Wertgutscheine (WG5): required — a brake that can be missing
        // is missing in silence.
        private readonly CodeEntry $codes,
        // Abo-2: the conditions on a subscription line. Optional for tests
        // built by hand; the container wires it.
        private readonly SubscriptionLineTerms|null $lineTerms = null,
        // Abo-7b: the numbers of renewed subscriptions. Optional likewise.
        private readonly SubscriptionRenewal|null $renewals = null,
        /*
         * The kind of the article (DL1): "zzgl.
         * Versandkosten" only where something is shipped. Optional for the
         * tests built by hand — without it the sentence stays as it was.
         */
        private readonly ArticleShippingDataInterface|null $articles = null,
        /*
         * Block G (G5): what is left of each article. Optional like the ones
         * above — the line then says nothing; the checkout and the purchase
         * click check regardless.
         */
        private readonly Availability|null $availability = null,
        // Block H (H-4): cart rules and the code typed here (W1).
        private readonly CartDiscounts|null $discounts = null,
        // Block Wertgutscheine (WG-5, E3): what the typed vouchers hold.
        // Optional like the ones above; the click books under a lock regardless.
        private readonly VoucherWallet|null $vouchers = null,
        // WG-7: no "inkl. USt." over a basket of vouchers only.
        private readonly NotTaxableArticles|null $notTaxable = null,
        /*
         * E4: the basket asks the
         * checkout's readiness too. Optional like the ones above — without
         * it the link stays, and the checkout refuses on its own.
         */
        private readonly CheckoutReadiness|null $readiness = null,
        // EZ-1: whether a single-purpose voucher fits anything in the basket.
        private readonly CoverablePots|null $pots = null,
    ) {
    }

    /**
     * The one action this submit asked for, or null.
     *
     * Read through getString() rather than get(): InputBag::get() throws a
     * BadRequestException for an array, and so does getString() — but getString()
     * says what it returns, which keeps the casts below honest for a reader and
     * for static analysis.
     *
     * @internal public for the test only
     */
    public static function actionOf(Request $request): CartAction|null
    {
        $name = $request->request->getString('consho_cart_action');
        $itemId = (int) $request->request->getString('item');

        if (!\in_array($name, ['update', 'remove'], true) || $itemId < 1) {
            return null;
        }

        return new CartAction($name, $itemId);
    }

    /**
     * The discount code form (block H, H-4, W1): `code` with the typed
     * code, `removeCode` takes it away. Its own forms, with no line.
     *
     * @internal public for the test only
     *
     * @return string|null the code to keep ('' to take it away), null when the post is no code action
     */
    public static function codeActionOf(Request $request): string|null
    {
        return match ($request->request->getString('consho_cart_action')) {
            'code' => trim($request->request->getString('discount_code')),
            'removeCode' => '',
            default => null,
        };
    }

    /**
     * The voucher form (block Wertgutscheine, E3): `voucher` with the typed
     * code, `removeVoucher` with the code to take away.
     *
     * @internal public for the test only
     *
     * @return array{0: 'add'|'remove', 1: string}|null
     */
    public static function voucherActionOf(Request $request): array|null
    {
        return match ($request->request->getString('consho_cart_action')) {
            'voucher' => ['add', trim($request->request->getString('voucher_code'))],
            'removeVoucher' => ['remove', trim($request->request->getString('voucher'))],
            default => null,
        };
    }

    /**
     * Whether this typed quantity means "take the line away".
     *
     * @internal public for the test only
     */
    public static function removesLine(string $quantity): bool
    {
        return 1 === preg_match('/^0(\.0+)?$/', trim($quantity));
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        // As in ProductDetailController: this request's own PageModel, the one
        // the front end index resolved and called loadDetails() on — so
        // $page->language carries the start point's language.
        $page = $request->attributes->get('pageModel');
        $shop = $page instanceof PageModel ? $this->shops->resolveForPage($page) : null;

        /*
         * Read before the guard, because the guard turns on it: a start point
         * without a currency has nothing to price in, and it is not a
         * theoretical state — it is the window between a deploy and
         * contao:migrate, the same one ShopResolver's empty-string answer is
         * about.
         *
         * Measured, not assumed: Currencies::getFractionDigits('') falls back
         * to DEFAULT rather than throwing, and ICU prints an unknown code as
         * its placeholder, so the total below would read "0,00 XXX" in German
         * and "¤0.00" in English — on an empty basket too. Refusing to render
         * is the same answer the catalogue elements give by finding no price,
         * and the same shape as the empty-language guard further down.
         */
        $currency = $page instanceof PageModel ? $this->shops->currencyOfPage($page) : '';

        if (!$page instanceof PageModel || null === $shop || '' === $currency) {
            // No shop on this site, or no currency on this start point: the
            // element has nothing to show.
            return new Response('');
        }

        $shopId = (int) $shop->id;
        $fallback = $this->languages->fallbackOf($shopId);
        $language = (string) $page->language;

        /*
         * Never an empty language beside a set fallback: VisibilityCriteria
         * reads that pair as "this article needs a translation" and would
         * refuse a perfectly ordinary product on a quantity change. The main
         * language is passed as language === fallback, which is exactly what
         * this assignment produces where the page has no language of its own.
         */
        if ('' === $language) {
            $language = $fallback;
        }

        $cart = $this->carts->resolve($request, $shopId);
        $codeAction = $request->isMethod('POST') && null !== $this->discounts ? self::codeActionOf($request) : null;

        if (null !== $codeAction) {
            if (null !== $cart) {
                $this->codes->submit($cart->id, $codeAction, (string) $request->getClientIp(), time());
            }

            throw new RedirectResponseException($this->addressOf($page, $request));
        }

        $voucherAction = $request->isMethod('POST') ? self::voucherActionOf($request) : null;

        if (null !== $voucherAction) {
            if (null !== $cart) {
                'add' === $voucherAction[0]
                    ? $this->codes->addVoucher($cart->id, $shopId, $voucherAction[1], (string) $request->getClientIp(), time())
                    : $this->codes->removeVoucher($cart->id, $voucherAction[1], time());
            }

            throw new RedirectResponseException($this->addressOf($page, $request));
        }

        $action = $request->isMethod('POST') ? self::actionOf($request) : null;

        if (null !== $action) {
            // Only a POST that addresses THIS element redirects. Another form
            // on the same page (a contact form in a second element) posts to
            // the same address and must not be answered with a 303 from here.
            if (null !== $cart) {
                $this->apply($action, $request, $cart, $language, $fallback);
            }

            throw new RedirectResponseException($this->addressOf($page, $request));
        }

        $items = null === $cart ? [] : $this->repository->itemsOf($cart->id);
        // The currency from the start point (read above), the home country
        // from the shop (spec 4.0): the same basket is priced in pounds below
        // /en/ and in euro below /de/, while the tax stays that of the shop's
        // seat.
        /*
         * Who this basket is priced for (C-4). Same question as on the product
         * page, same answer — a basket that priced a trade customer like a
         * guest would contradict the page they added from.
         */
        $audience = $this->audiences->forShop((int) $shop->id);

        $now = time();
        $view = $this->pricer->price($items, $currency, (string) $shop->country, (string) $shop->country, $now, $audience->groups);

        $titles = $this->titles->of(
            array_map(static fn (CartItemRow $item): int => $item->articleId, $items),
            $language,
            $fallback,
        );

        $lines = $this->linesOf($view, $titles, $language, $audience->showsNet, (string) $shop->country, $audience->groups, $now);
        $unpriced = $this->unpricedOf($items, $view, $titles, \count($lines));

        $template->set('lines', $lines);
        $template->set('unpriced', $unpriced);
        $goods = $audience->showsNet ? $view->net : $view->gross;
        $template->set('total', $this->money->format($goods, $view->currency, $language));
        $discount = $this->discountOf($template, $cart, $view, $shopId, $audience->groups, $audience->showsNet, $goods, $language, $now);
        $this->vouchersOf($template, $cart, $view, $shopId, $language, $now, $discount, (string) $shop->country);
        $template->set('showsNet', $audience->showsNet);
        $template->set('shipped', $this->anyShipped(array_map(static fn ($item): int => $item->articleId, $items)));
        $template->set('untaxed', $this->notTaxable?->all(array_map(static fn ($item): int => $item->articleId, $items)) ?? false);

        // NOT [] === $lines: isEmpty() is false while only unpriced lines are
        // left, and those have to be shown and removable.
        /*
         * The age notice (C19, C-4). Over the STORED lines, not the priced
         * ones: an unpriced line still lies in the basket in front of the
         * customer. It blocks nothing — 6, 12 and 14 are notices by
         * definition, and 16 and 18 are stopped at the checkout, where there
         * is an identity to check against (C-5).
         */
        $template->set('minimum_age', $this->ageLimits->of(
            array_map(static fn (CartItemRow $item): int => $item->articleId, $items),
        ));

        /*
         * The way to the checkout (4.2). Empty when the start point has no
         * checkout page — then the finding CheckoutPageMissing says so in the
         * back end, and the cart page simply shows no button instead of
         * pointing at an address that does not exist.
         */
        $checkoutUrl = $view->isEmpty() ? null : $this->pages->checkoutOfPage($page);

        /*
         * A blocked checkout is not linked (C-12, E4): behind the link the
         * visitor stood on an empty page. The same sentence as the
         * checkout instead, and as there the findings stay the operator's.
         * Asked only where there is a link to withhold.
         */
        $blocked = null !== $checkoutUrl && null !== $this->readiness && !$this->readiness->isReady($shopId);

        $template->set('checkout_url', $blocked ? null : $checkoutUrl);
        $template->set('checkout_blocked', $blocked);

        /*
         * P12 (A10, F9): a subscription needs an account, and the checkout
         * will ask for one whatever the shop says about guests. Said here
         * first, over the STORED lines like the age notice — an unpriced
         * subscription line still asks for it once it is priced again.
         */
        $template->set('account_required', 0 === $this->carts->memberId() && [] !== array_filter(
            $items,
            static fn (CartItemRow $item): bool => $item->subscriptionRule > 0,
        ));

        $template->set('is_empty', $view->isEmpty());
        $template->set('notice', null === $cart ? null : $this->repository->takeNotice($cart->id));

        /*
         * Private, and not because of a cookie: this page shows one visitor's
         * basket. A shared cache must never hold it, even for a second. The
         * element is not an ESI fragment (that is the mini basket, C10), so
         * this travels up to the page through SubrequestCacheSubscriber — which
         * is the point here: the whole basket page must stay private.
         */
        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * The priced lines, ready for the template.
     *
     * ── Why every line carries a mark of its own ──────────────────────────
     *
     * `position` is the line's place in the basket, counted over BOTH lists in
     * the order they are rendered, and `sku` is the article's own item number.
     * The template builds the accessible name of the quantity field and of
     * both buttons from the title AND one of the two — without that, two
     * variants of one product read alike ("Menge: Wanderstock" twice), because
     * CartTitles hands out the MAIN product's title by design. Two lines with
     * no title at all fall onto the same replacement text and would be just as
     * alike.
     *
     * The item number first, because it is the shop's own answer to "which
     * article is this" and a variant normally has one. The position where none
     * is maintained (an optional field): it needs no data, and it is something
     * the visitor can check against what is on the screen — unlike the line id,
     * which is a database number that means nothing to them.
     *
     * Marked on EVERY line, not only where two happen to collide: a name that
     * changed depending on the other lines would change when an unrelated line
     * is removed, and it could not be tested one line at a time.
     *
     * @param array<int, CartTitle> $titles
     *
     * `terms` carries a subscription line's conditions (Abo-2, A10), built
     * from the very amount the line shows — null on a one-time sale.
     *
     * @param list<int> $groups
     *
     * @return list<array{itemId: int, articleId: int, title: string, sku: string, position: int, quantity: string, lineGross: string, terms: string|null}>
     */
    private function linesOf(CartView $view, array $titles, string $language, bool $showsNet, string $country, array $groups, int $now): array
    {
        $lines = [];
        $renewed = $this->renewals?->numbersOf(array_map(static fn (CartLine $line): int => $line->renews, $view->lines)) ?? [];

        // Block G (G5): the basket holds nothing back, it says what is left.
        // Summed per article, like the checkout: two plans of one article
        // draw on one stock.
        // "Lizenz verlängern" lines take no code (Abo-7b).
        $pairs = array_map(static fn (CartLine $line): array => [$line->articleId, $line->quantity], array_values(array_filter($view->lines, static fn (CartLine $line): bool => 0 === $line->renews)));
        $stock = $this->availability?->of(array_values(array_unique(array_column($pairs, 0)))) ?? [];
        $short = array_flip($this->availability?->short($pairs) ?? []);

        foreach ($view->lines as $line) {
            $name = $titles[$line->articleId] ?? null;
            $shown = $showsNet ? $line->lineNet : $line->lineGross;

            $lines[] = [
                'itemId' => $line->itemId,
                'articleId' => $line->articleId,
                'title' => $name->title ?? '',
                'sku' => $name->sku ?? '',
                'position' => \count($lines) + 1,
                'quantity' => self::plain($line->quantity),
                // The name stays `lineGross` although it may carry the net
                // amount: it is what a line SHOWS, and renaming it would
                // break every template a shop has already overridden. The
                // sentence beside it says which of the two it is.
                'lineGross' => $this->money->format($shown, $view->currency, $language),
                'terms' => $this->lineTerms?->of($line->subscriptionRule, $shown, $view->currency, $language),
                // Abo-7b: the renewed subscription's number; '' otherwise.
                'renews' => $line->renews > 0 ? ($renewed[$line->renews] ?? '#'.$line->renews) : '',
                // Block G: the state, and how many are left where the line
                // asks for more (the only place the figure is shown, G7).
                'stock' => isset($stock[$line->articleId]) && null !== $stock[$line->articleId]->available ? [
                    'state' => $stock[$line->articleId]->state->value,
                    'short' => isset($short[$line->articleId]),
                    'left' => self::plain(max('0', $stock[$line->articleId]->available)),
                ] : null,
            ];
        }

        return $lines;
    }

    /**
     * A line without a price is still a line: the visitor has to see WHICH
     * article is blocking the checkout, or they cannot decide what to remove.
     * The stored lines are walked again for their quantity, matched by LINE id
     * (CartView::$unpricedItems) — since Abo-2 one article can stand in two
     * lines, one per plan, and an article id would mark both (P9). Their order
     * is the stored order, which is the order the priced lines are in too.
     *
     * $offset is the number of priced lines, so that the positions run through
     * the table the visitor sees rather than starting at 1 a second time.
     *
     * @param list<CartItemRow>     $items
     * @param array<int, CartTitle> $titles
     *
     * @return list<array{itemId: int, articleId: int, title: string, sku: string, position: int, quantity: string}>
     */
    private function unpricedOf(array $items, CartView $view, array $titles, int $offset): array
    {
        $isUnpriced = array_fill_keys($view->unpricedItems, true);
        $unpriced = [];

        foreach ($items as $item) {
            if (!isset($isUnpriced[$item->id])) {
                continue;
            }

            $name = $titles[$item->articleId] ?? null;

            $unpriced[] = [
                'itemId' => $item->id,
                'articleId' => $item->articleId,
                'title' => $name->title ?? '',
                'sku' => $name->sku ?? '',
                'position' => $offset + \count($unpriced) + 1,
                'quantity' => self::plain($item->quantity),
            ];
        }

        return $unpriced;
    }

    /**
     * A quantity as a person writes it down: "3", not the column's "3.0000".
     *
     * Done here rather than in the template, and NOT through
     * MoneyFormatter::quantity(): the value goes into an input field, and that
     * formatter is locale-aware — it would hand a German visitor "2,5", which
     * comes back as a quantity BigDecimal refuses. Stripping zeros is
     * arithmetic, not formatting, and leaves the decimal point alone.
     */
    private static function plain(string $quantity): string
    {
        try {
            return (string) BigDecimal::of($quantity)->strippedOfTrailingZeros();
        } catch (MathException) {
            // Cannot happen for a decimal column, and if it ever does the raw
            // value is more use in the field than an exception on the page.
            return $quantity;
        }
    }

    private function apply(CartAction $action, Request $request, Cart $cart, string $language, string $fallback): void
    {
        $now = time();

        if ('remove' === $action->name) {
            $this->repository->removeItem($cart->id, $action->itemId, $now);

            return;
        }

        $quantity = $request->request->getString('quantity');

        if (self::removesLine($quantity)) {
            $this->repository->removeItem($cart->id, $action->itemId, $now);

            return;
        }

        // Also answers "is this line in THIS basket": the id pair is in the
        // WHERE, so a guessed number reads 0 and nothing happens.
        $articleId = $this->repository->articleOf($cart->id, $action->itemId);

        if (0 === $articleId) {
            return;
        }

        // A renewal keeps the old subscription's quantity (Abo-7b; A11: no
        // change of licences). The page shows no field for it;
        // a hand-made POST changes nothing either. Removing stays possible.
        if ($this->repository->renewsOf($cart->id, $action->itemId) > 0) {
            return;
        }

        /*
         * The same check as when adding: minimum quantity and step can have
         * changed while the basket sat open for a week, and the article can
         * have become invisible since. Language and fallback travel too — five
         * visibility rules, not four.
         *
         * The shop comes off the basket itself, which CartResolver has already
         * read; asking the database for tl_consho_cart.shop again would be the
         * same number a second time.
         */
        $verdict = $this->check->check($articleId, $cart->shopId, $quantity, $now, $language, $fallback);

        // ->allowed is the question; the null check only repeats what it said
        // (PurchaseVerdict nulls the quantity on every refusal) and is what
        // lets the compiler carry the string down to setQuantity().
        if ($verdict->allowed && null !== $verdict->quantity) {
            $this->repository->setQuantity($cart->id, $action->itemId, $verdict->quantity, $now);

            return;
        }

        $this->repository->setNotice($cart->id, $verdict->notice());
    }

    /**
     * ContentUrlGenerator throws for a page without a route — one in a site
     * that has no root, or a preview of an unpublished page. The 303 still has
     * to name somewhere, so it falls back to the path this request came in on,
     * WITHOUT its query string: nothing the visitor sent is echoed back in a
     * Location header.
     */
    private function addressOf(PageModel $page, Request $request): string
    {
        try {
            return $this->urls->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (ExceptionInterface) {
            return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo();
        }
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
    /**
     * The vouchers typed here (block Wertgutscheine, E3): each with what it
     * holds, or why it pays nothing. Nothing is applied yet — the shipping is
     * only known in the checkout, and that is where they are set against the
     * total.
     *
     * Offered only where something besides vouchers lies in the basket: a
     * voucher does not pay for vouchers (WG8).
     */
    private function vouchersOf(FragmentTemplate $template, Cart|null $cart, CartView $view, int $shopId, string $language, int $now, CartDiscount|null $discount, string $country): void
    {
        // EZ-1, F2: what a single-purpose voucher may pay is after the cart
        // rule's discount, as the checkout counts it. Free shipping takes
        // nothing off the articles.
        $shares = null === $discount || $discount->freeShipping || null === $this->pots ? [] : $this->pots->discountShares($discount->shares, $country, $now);

        $payable = [] !== array_filter($view->lines, static fn (CartLine $line): bool => !$line->kind->isVoucher());
        $codes = null === $cart || null === $this->vouchers ? [] : $this->repository->vouchersOf($cart->id);

        $template->set('voucher_enabled', null !== $this->vouchers && !$view->isEmpty());
        $template->set('voucher_payable', $payable);
        $template->set('vouchers', array_map(fn ($voucher): array => [
            'code' => $voucher->masked(),
            'value' => VoucherCode::format($voucher->code),
            'status' => $voucher->status->value,
            'balance' => $this->money->format($voucher->balance, $view->currency, $language),
        ], null === $this->vouchers ? [] : $this->vouchers->apply($codes, $shopId, $view->currency, '0', 2, $now, $this->pots?->of($view->lines, $shares, $now, false, $country) ?? [])));
    }

    /**
     * The code typed here and the discount that applies (block H, H-4): the
     * goods total, the amount off and what is left, and what became of the
     * code — said, so a code that does nothing never looks as if it worked.
     *
     * Free shipping is a line of its own here: the shipping is only known in
     * the checkout, so it saves nothing in this total yet.
     *
     * @param list<int> $groups
     */
    private function discountOf(FragmentTemplate $template, Cart|null $cart, CartView $view, int $shopId, array $groups, bool $showsNet, string $goods, string $language, int $now): CartDiscount|null
    {
        $code = null === $cart || null === $this->discounts ? '' : $this->repository->discountCodeOf($cart->id);

        $template->set('discount_enabled', null !== $this->discounts);
        $template->set('discount_code', $code);
        $template->set('discount', null);
        $template->set('code_status', CodeStatus::None->value);
        $template->set('code_missing', '');

        if (null === $this->discounts || $view->isEmpty()) {
            return null;
        }

        $result = $this->discounts->evaluate($view, $shopId, $code, $groups, $showsNet, $this->carts->memberId(), '', $now);
        $template->set('code_status', $result->codeStatus->value);
        $template->set('code_missing', '' === $result->minimumOrder ? '' : $this->money->format($result->minimumOrder, $view->currency, $language));

        if (null === $result->discount) {
            return null;
        }

        $off = $result->discount->displayed();
        $template->set('discount', [
            'title' => $result->discount->title,
            'code' => $result->discount->code,
            'freeShipping' => $result->discount->freeShipping,
            'amount' => $this->money->format((string) BigDecimal::of($off)->negated(), $view->currency, $language),
        ]);
        $template->set('subtotal', $this->money->format($goods, $view->currency, $language));
        $template->set('total', $this->money->format((string) BigDecimal::of($goods)->minus($off), $view->currency, $language));

        return $result->discount;
    }

}
