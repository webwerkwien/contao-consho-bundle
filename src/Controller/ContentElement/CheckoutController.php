<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Brick\Math\BigDecimal;
use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\AddressNormalizer;
use Webwerkwien\ContaoConshoBundle\Address\PhoneNormalizer;
use Webwerkwien\ContaoConshoBundle\Address\VatIdValidatorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\Cart;
use Webwerkwien\ContaoConshoBundle\Cart\CartAgeLimit;
use Webwerkwien\ContaoConshoBundle\Cart\CartItemRow;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitles;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityLabelImages;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartRuleExhaustedAtPurchase;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CodeStatus;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Checkout\BuyButtonLabel;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutBlock;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutCalculator;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutFingerprint;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutProblem;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalLegalTextSource;
use Webwerkwien\ContaoConshoBundle\Checkout\LegalText;
use Webwerkwien\ContaoConshoBundle\Checkout\MemberCheckoutDefaults;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodOption;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Checkout\PurchaseProblem;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Order\BasketGoneAtPurchase;
use Webwerkwien\ContaoConshoBundle\Order\LegalTextGoneAtPurchase;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeNotConfigured;
use Webwerkwien\ContaoConshoBundle\Order\PaymentMethodGoneAtPurchase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStarter;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStartOutcome;
use Webwerkwien\ContaoConshoBundle\Order\ProductGoneFromCatalogue;
use Webwerkwien\ContaoConshoBundle\Order\StockShortAtPurchase;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationInterface;
use Webwerkwien\ContaoConshoBundle\Order\PurchaseHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Order\TotalDisagreesWithCheckout;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceNotFound;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCountryCoverage;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewalRefused;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRuleGoneAtPurchase;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionWithoutAccount;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherChangedAtPurchase;
use Webwerkwien\ContaoConshoBundle\Tax\NotTaxableArticles;

/**
 * The checkout (C13): four fixed building blocks over one to four steps, the
 * step in the address.
 *
 * ── Why the step is in the address and not in the session ─────────────────
 *
 * The same reason CheckoutData lives on the basket row (C9): a session makes
 * every further page private for this visitor. `?consho_step=` costs nothing,
 * survives a reload, can be linked, and the back button does what it looks
 * like it does. It is also the only way the step could work without
 * JavaScript, which 15.5 requires.
 *
 * ── Every submit is POST then redirect ────────────────────────────────────
 *
 * A step saves and answers 303, never renders the next step directly. Reload
 * after an address then re-renders, it does not re-submit; and the address bar
 * matches what is on the screen, which it would not after a render-on-POST.
 *
 * ── The only side effect in the whole checkout ────────────────────────────
 *
 * The VIES call, and it happens HERE, in the address submit, never in the
 * calculator. A calculation runs on every page view and has to be repeatable;
 * a request to the EU is neither free nor reliable enough for that.
 */
#[AsContentElement(type: 'consho_checkout', category: 'consho')]
class CheckoutController extends AbstractContentElementController
{
    /**
     * The address fields a form may set. Not `country` alone: everything here
     * is read through this list, so a field nobody listed cannot be smuggled
     * in by name.
     */
    private const ADDRESS_FIELDS = [
        'company', 'salutation', 'firstname', 'lastname',
        'street', 'postal', 'city', 'state', 'country', 'phone', 'email',
    ];

    /**
     * The delivery address asks only for what the order keeps of it
     * (OrderFactory::frozenAddresses). It used to repeat every billing field,
     * and the salutation, e-mail and phone typed there went nowhere — found at
     * the acceptance of C-10 (Michael, 2026-09-25: „ja", out with them). The
     * contact details of the order are the billing address's. A recipient's
     * phone for the carrier is block E, with the carrier that needs it.
     */
    private const SHIPPING_FIELDS = [
        'company', 'firstname', 'lastname',
        'street', 'postal', 'city', 'state', 'country',
    ];

    public function __construct(
        private readonly CartResolver $carts,
        private readonly DbalCartRepository $repository,
        private readonly CheckoutCalculator $calculator,
        private readonly ShopResolver $shops,
        private readonly ShopLanguages $languages,
        private readonly PriceAudienceResolver $audiences,
        private readonly CartAgeLimit $ageLimits,
        private readonly MoneyFormatter $money,
        private readonly AddressNormalizer $addresses,
        private readonly CountryProvider $countries,
        private readonly VatIdValidatorInterface $vatIds,
        private readonly CheckoutReadiness $readiness,
        private readonly DbalLegalTextSource $legalTexts,
        private readonly CheckoutFingerprint $fingerprints,
        private readonly ShopPageUrls $pageUrls,
        private readonly CartTitles $titles,
        private readonly PurchaseHandlerInterface $orders,
        private readonly MemberCheckoutDefaults $memberDefaults,
        private readonly OrderConfirmationInterface $confirmations,
        private readonly PaymentStarter $payments,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // Optional for the tests that build the controller by hand (E-5).
        private readonly ShippingCountryCoverage|null $shippingCountries = null,
        private readonly PhoneNormalizer $phones = new PhoneNormalizer(),
        // Optional for the tests that build the controller by hand (F-4).
        private readonly DigitalRelease|null $releases = null,
        // The conditions of a subscription line on the review (Abo-2, A10).
        private readonly SubscriptionLineTerms|null $lineTerms = null,
        // "Lizenz verlängern" (Abo-7b, P70): asked before the button.
        private readonly SubscriptionRenewal|null $renewals = null,
        // Acceptance WG-7: the total of an order of vouchers only carries no
        // "inkl. USt." label.
        private readonly NotTaxableArticles|null $notTaxable = null,
        // Block GARAN: the label before the button. Optional like the above.
        private readonly DurabilityLabelImages|null $durability = null,
    ) {
    }

    /**
     * Which step this request is on, 1-based and clamped into range.
     *
     * @internal public for the test only
     */
    public static function stepOf(Request $request, int $count): int
    {
        $step = (int) $request->query->getString('consho_step', '1');

        return max(1, min($count, $step));
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $page = $request->attributes->get('pageModel');
        $shop = $page instanceof PageModel ? $this->shops->resolveForPage($page) : null;
        $currency = $page instanceof PageModel ? $this->shops->currencyOfPage($page) : '';

        if (!$page instanceof PageModel || null === $shop || '' === $currency) {
            // No shop on this site, or no currency on this start point — the
            // same guard, and the same reasons, as the basket element.
            return new Response('');
        }

        $shopId = (int) $shop->id;
        $fallback = $this->languages->fallbackOf($shopId);
        $language = (string) $page->language;

        if ('' === $language) {
            $language = $fallback;
        }

        /*
         * The readiness check (11.2), BEFORE anything else happens — not at
         * the buy click. Refusing an order after the visitor typed an address,
         * chose a method and read the legal texts is the expensive way to say
         * the same thing.
         *
         * The blockers themselves are not shown: they are the operator's
         * business, and naming a missing payment method to a customer helps
         * nobody. The back end has had them as warnings all along.
         */
        $blockers = $this->readiness->blockers($shopId);

        if ([] !== $blockers) {
            $template->set('blockers', array_map(static fn (ConfigurationFinding $f): string => $f->value, $blockers));

            $response = $template->getResponse();
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            return $response;
        }

        $cart = $this->carts->resolve($request, $shopId);
        $steps = CheckoutBlock::steps((int) ($model->conshoSteps ?? 3));

        /*
         * C14: „Ist die Gastbestellung am Shop aus, bleibt nur das Anmelden."
         * Das ist eine SPERRE, kein Hinweis — der Review vom 2026-09-22 fand
         * sie als blosses Template-Flag, während das Adressformular daneben
         * weiter Eingaben annahm. Das Anmelde-Element ist Contaos eigenes und
         * steht auf derselben Seite (Michael, 2026-09-22).
         */
        // CartResolver beantwortet „wer ist das" ohnehin schon, und zwar mit
        // derselben Regel wie der Warenkorb — eine zweite Quelle dafür wäre
        // eine Stelle mehr, an der die Antwort auseinanderlaufen kann.
        /*
         * P12 (Abo-2; A10, F9): a subscription needs an account, whatever the
         * shop says about guests — it runs on, and "Meine Abos" is where it is
         * changed and cancelled. The same lock as C14, over the stored lines:
         * an unpriced subscription line still is one.
         */
        $hasSubscription = null !== $cart && [] !== array_filter(
            $this->repository->itemsOf($cart->id),
            static fn (CartItemRow $item): bool => $item->subscriptionRule > 0,
        );
        $guestsWelcome = ((bool) $shop->guestCheckout && !$hasSubscription) || $this->carts->memberId() > 0;

        if (!$guestsWelcome) {
            $template->set('login_required', true);
            // Why, in the visitor's words (acceptance on c5, Abo-2): "this shop
            // requires an account" is untrue in a shop that welcomes guests —
            // there it is the subscription that asks for one.
            $template->set('login_reason', (bool) $shop->guestCheckout ? 'subscription' : 'shop');

            $response = $template->getResponse();
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            return $response;
        }

        /*
         * "Lizenz verlängern" (Abo-7b, P70): a renewal line that has stopped
         * being one — renewed in another basket meanwhile, an invoice fallen
         * overdue, a resting licence. Said before the address form, like the
         * account; the purchase refuses it again under its transaction
         * (SubscriptionRecorder). The customer removes the line on the basket
         * page; the sentence says so.
         */
        $refused = null === $cart || null === $this->renewals ? '' : $this->refusedRenewal($cart->id, $this->carts->memberId(), $shopId);

        if ('' !== $refused) {
            $template->set('renewal_refused', $refused);

            $response = $template->getResponse();
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            return $response;
        }

        /*
         * The buy click (7.3, step 1). Its own branch, because it is the only
         * submit that does not save anything: it checks, and then either
         * orders (C-7) or sends the visitor back with a reason.
         */
        /*
         * 🔴 **Der SPÄTE Doppelklick** (F5 des Reviews vom 2026-09-23 war der
         * Versuch, F2 ist dies).
         *
         * OrderPlacer fängt die zweite Anfrage, die noch VOR dem Commit an der
         * Zeilensperre hing: sie findet `converted` und bekommt dieselbe
         * Bestellung. Wer erst DANACH ankommt — zurück im Browser, Formular
         * noch einmal abgeschickt —, kommt gar nicht so weit: markConverted()
         * setzt `tokenHash` auf NULL und den Zustand auf `converted`, und
         * CartResolver sucht nur nach `open`. Die Bedingung unten ist dann
         * falsch, und die Kasse rendert mit leerem Warenkorb.
         *
         * Bestellt ist nichts doppelt, aber „Ihr Warenkorb ist leer" ist die
         * falsche Auskunft für jemanden, der gerade gekauft hat. Der Hinweis
         * reist in der Adresse mit, wie die Absagen auch: POST → Weiterleitung
         * → GET, damit ein Neuladen nicht wieder hier landet.
         *
         * Auf den Warenkorb selbst greift das nicht zu. Ihn auffindbar zu
         * halten, hiesse den Hash in eine eigene Spalte zu retten und das
         * Korb-Cookie dauerhaft auf eine alte Bestellung zeigen zu lassen
         * (Michael, 2026-09-23).
         */
        if (
            null === $cart
            && $request->isMethod('POST')
            && CheckoutBlock::Review->value === $request->request->getString('consho_checkout_block')
        ) {
            throw new RedirectResponseException(
                (strtok($request->getUri(), '?') ?: $request->getUri()).'?consho_ordered=1',
            );
        }

        if (
            null !== $cart
            && $request->isMethod('POST')
            && CheckoutBlock::Review->value === $request->request->getString('consho_checkout_block')
        ) {
            $state = $this->stateOf($cart, $shop, $currency, $language, $fallback);

            $redirect = new RedirectResponseException(
                $this->purchase($request, $page, $cart, $shop, $state, $language, $fallback, \count($steps)),
            );

            /*
             * The basket cookie goes once nothing hangs on it any more (C-12,
             * Michael 2026-09-26: "ja, gleich umsetzen").
             *
             * Measured at the acceptance: every page with a shared cache sends
             * `Vary: Cookie` (Contao's AbstractController::setCacheHeaders),
             * so a visitor carrying consho_cart is served every page freshly —
             * for the cookie's 30 days, and after the purchase too, since
             * nothing cleared it. The people who buy got the shop without its
             * cache.
             *
             * Only when no OPEN basket is left on the token: since C-3b one
             * cookie holds a basket per shop, and a purchase in one must not
             * throw away the other. The bought basket lost its hash in the
             * transaction (markConverted); a purchase that was refused kept
             * it, so this finds it and the cookie stays. The next "add to
             * basket" sets a fresh one.
             */
            $token = CartToken::fromRequest($request);

            if (null !== $token && [] === $this->repository->findAllByToken($token)) {
                $redirect->getResponse()->headers->setCookie(CartToken::clearCookie($request->isSecure()));
            }

            throw $redirect;
        }

        if (null !== $cart && $request->isMethod('POST') && '' !== $request->request->getString('consho_checkout_block')) {
            $this->apply($request, $cart, (string) $shop->vatId);

            // POST/redirect/GET, and the target is decided AFTER saving — the
            // freshly calculated state may well send the visitor back rather
            // than on (C2, C13).
            throw new RedirectResponseException($this->nextAddress($request, $cart, $shop, $currency, $steps));
        }

        $state = $this->stateOf($cart, $shop, $currency, $language, $fallback);
        $step = self::stepOf($request, \count($steps));

        $blocks = $this->applicable($steps[$step - 1], $state);

        $template->set('blocks', array_map(static fn (CheckoutBlock $block): string => $block->value, $blocks));
        $template->set('step', $step);
        $template->set('step_count', \count($steps));

        /*
         * Die Namen der Schritte, aus ihren Bausteinen gebildet. Nicht aus der
         * Nummer: bei drei Schritten hiesse der dritte sonst „Versand und
         * Zahlung", obwohl dort gekauft wird (Review 2026-09-22).
         */
        $template->set('step_labels', array_map(
            /*
             * Über die GEFILTERTEN Bausteine: fällt der Versand weg (4.3),
             * hiesse ein Schritt sonst weiter „Versand und Zahlung", obwohl
             * dort nur noch gezahlt wird. In C ist das nicht erreichbar —
             * alles ist versendbar —, in Block F schon.
             */
            fn (array $blocks): string => CheckoutBlock::labelOf($this->applicable($blocks, $state)),
            $steps,
        ));
        $template->set('state', $state);
        $template->set('checkout', $state->data);
        $template->set('address_fields', self::ADDRESS_FIELDS);
        $template->set('shipping_fields', self::SHIPPING_FIELDS);
        $template->set('guest_checkout', $guestsWelcome);
        $template->set('total', $this->money->format($state->grossTotal, $state->currency, $language));
        $template->set('net_total', $this->money->format($state->netTotal, $state->currency, $language));
        // Everything not taxable, and no tax from shipping or a fee either.
        $template->set('untaxed', $state->netTotal === $state->grossTotal && ($this->notTaxable?->all(array_map(static fn ($line): int => $line->articleId, $state->cart->lines)) ?? false));
        $template->set('problems', array_map(static fn ($problem): string => $problem->value, $state->problems));
        $template->set('blocked_at', $state->blockedAt()?->value);
        $template->set('is_ready', $state->isReady());
        // Delivery: only where a shipping method goes (E-5). Billing: the
        // shop's list as before — the calculator checks the DELIVERY country,
        // and a German invoice to an Austrian parcel is an ordinary case
        // (Fable review E, S2).
        $template->set('countries', $this->countriesFor($shop, $language));
        $template->set('billing_countries', $this->countriesFor($shop, $language, false));

        /*
         * The button solution (§ 8 FAGG, § 312j BGB) and what belongs beside
         * it. The legal texts are read for every step, not only the last one:
         * they are cheap, and the step a visitor is on is not the template's
         * only decision about them.
         */
        $root = $this->shops->rootOf($page);

        $template->set('legal_texts', $this->legalLinks($root));
        $template->set('privacy_url', $this->pageUrls->privacyOfPage($page));
        $template->set('warranty_url', $this->pageUrls->warrantyOfPage($page));
        $template->set('terms_required', (bool) $shop->termsRequired);
        $template->set('digital_consent', $this->asksDigitalConsent($shop, $state));
        // Which sentences the box carries (Abo-2, P14): content, service, or both.
        $template->set('digital_consent_kinds', $this->asksDigitalConsent($shop, $state) ? $state->withdrawalKinds : []);
        /*
         * A renewal in the basket (Abo-7b; review S5, Michael 2026-10-01:
         * „Hinweis in der Kasse sollte da sein"): without the consent the
         * licence moves over only after the withdrawal period — if the old
         * subscription ends before, there is none until then. Said beside the
         * box, only where the box is asked.
         */
        $template->set('renewal_consent_hint', $this->asksDigitalConsent($shop, $state) && [] !== array_filter($state->cart->lines, static fn ($line): bool => $line->renews > 0));
        $template->set('buy_label', BuyButtonLabel::stored($shop->buyButtonLabel ?? null)->translationKey());
        $template->set('fingerprint', $this->fingerprints->of($state, $this->legalChecksums($root)));
        $template->set('review_lines', $this->reviewLines(
            $state,
            $language,
            $fallback,
            // The country the lines are taxed in, as the calculator reads it:
            // the delivery country, the shop's until one is given.
            '' !== $state->data->deliveryCountry() ? $state->data->deliveryCountry() : (string) $shop->country,
            $this->audiences->forShop((int) $shop->id)->groups,
            $this->audiences->forShop((int) $shop->id)->showsNet,
        ));
        // The lines above are net (P75) — said at each, as product page and
        // basket do (review Abo-Nachlese, L1). Not under reverse charge: no
        // VAT comes on top there.
        $template->set('review_net', $this->audiences->forShop((int) $shop->id)->showsNet && !$state->reverseCharge);
        // Review H, L2 (Michael, 2026-10-05: „wie empfohlen"): shipping,
        // payment and discount the way the goods above them read — net for
        // the net group as under reverse charge. Until then a net customer
        // read −1,60 in the basket and −1,92 on this page.
        $shownNet = $state->reverseCharge || $this->audiences->forShop((int) $shop->id)->showsNet;
        // The amount beside each option where there is one (Michael,
        // 2026-09-28: „wenn es einen gibt ja") — the cash on delivery fee
        // must not first show up on the review page.
        $template->set('option_amounts', [
            'shipping' => $this->optionAmounts($state->shippingOptions, $state, $language, $shownNet),
            'payment' => $this->optionAmounts($state->paymentOptions, $state, $language, $shownNet),
        ]);
        $template->set('review_shipping', $this->methodLine($state->needsShipping ? $state->shipping : null, $state, $language, $shownNet));
        $template->set('review_payment', $this->methodLine($state->payment, $state, $language, $shownNet));
        $template->set('review_discount', $this->discountLine($state, $language, $shownNet));
        /*
         * Block Wertgutscheine (WG-5, E3): what each voucher takes off the
         * total, and what is left to pay — below the total, as payments
         * are, not as a discount. Changed in the basket, where they were
         * typed.
         */
        $template->set('review_vouchers', array_map(fn ($voucher): array => [
            'code' => $voucher->masked(),
            'status' => $voucher->status->value,
            'balance' => $this->money->format($voucher->balance, $state->currency, $language),
            'amount' => $this->money->format((string) BigDecimal::of($voucher->applied)->negated(), $state->currency, $language),
            'after' => $this->money->format($voucher->after, $state->currency, $language),
            'pays' => BigDecimal::of($voucher->applied)->isPositive(),
        ], $state->vouchers));
        $template->set('payable', $this->money->format($state->toPay(), $state->currency, $language));
        $template->set('cart_url', $this->pageUrls->ofPage($page));
        // The typed code for the message beside it (block H, H-4).
        $template->set('discount_code', null === $cart ? '' : $this->repository->discountCodeOf($cart->id));
        $template->set('purchase_problem', PurchaseProblem::tryFrom(
            $request->query->getString('consho_purchase'),
        )?->value);

        /*
         * F2: der späte Doppelklick, weitergeleitet von oben. Kein Fall von
         * PurchaseProblem — dort steht, warum NICHT bestellt wurde, und hier
         * wurde bestellt. Das Template zeigt den Satz an der Stelle, an der
         * sonst „Ihr Warenkorb ist leer" steht.
         */
        $template->set('already_ordered', '1' === $request->query->getString('consho_ordered'));

        // As a flag rather than letting the template reach for the enum case
        // through constant(): a template that names a PHP class by string is
        // one rename away from silently rendering nothing.
        $template->set('vat_unavailable', $state->has(CheckoutProblem::VatServiceUnavailable));

        /*
         * Private, and not because of a cookie: this page shows one visitor's
         * address and basket. Same as the basket element — it is not an ESI
         * fragment, so this travels up to the page through
         * SubrequestCacheSubscriber, which is the point.
         */
        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * Die Bausteine eines Schritts, die für DIESEN Warenkorb gelten: ohne
     * Versendbares entfällt „Versand" (4.3).
     *
     * @param list<CheckoutBlock> $blocks
     *
     * @return list<CheckoutBlock>
     */
    private function applicable(array $blocks, CheckoutState $state): array
    {
        return array_values(array_filter(
            $blocks,
            static fn (CheckoutBlock $block): bool => CheckoutBlock::Shipping !== $block || $state->needsShipping,
        ));
    }

    /**
     * Saves one block's submit. Nothing here decides where to go next — that
     * is the freshly calculated state's business, one method down.
     */
    /**
     * The method, and the recipient's phone where the form asked for it (E-6,
     * V6): normalised like the billing phone, to the delivery country. A form
     * without the field leaves a stored phone alone — it is kept only on an
     * order whose carrier needs it (PurchaseHandler).
     */
    /**
     * Whether the buyer counts as a business (F-3): a net price group of the
     * shop (the same audience that shows them net prices) or a verified VAT
     * ID. Both are the shop's own evidence; a company name typed into the
     * address is not.
     *
     * Treating a consumer as a business would skip a consent they are owed;
     * the reverse only asks a business for a tick it may leave empty — and
     * then waits for a period it does not have. The first is the error to
     * avoid, so anything short of that evidence is a consumer.
     */
    private function isBusiness(object $shop, CheckoutState $state): bool
    {
        return $this->audiences->forShop((int) $shop->id)->showsNet || $state->data->hasVerifiedVatId();
    }

    /**
     * The consent box: digital lines, and a consumer (F-3, F15).
     */
    private function asksDigitalConsent(object $shop, CheckoutState $state): bool
    {
        return $state->hasDigitalLines && !$this->isBusiness($shop, $state);
    }

    private function applyShipping(Request $request, CheckoutData $data): CheckoutData
    {
        $data = $data->withShippingMethod((int) $request->request->getString('consho_shipping_method'));

        if (!$request->request->has('consho_shipping_phone')) {
            return $data;
        }

        $phone = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $request->request->getString('consho_shipping_phone'))), 0, 64);

        return $data->withShippingPhone('' === $phone ? '' : $this->phones->normalize($phone, $data->deliveryCountry()));
    }

    private function apply(Request $request, Cart $cart, string $shopVatId): void
    {
        $block = CheckoutBlock::tryFrom($request->request->getString('consho_checkout_block'));
        $data = $this->repository->checkoutDataOf($cart->id);

        $data = match ($block) {
            CheckoutBlock::Address => $this->applyAddress($request, $data, $shopVatId),
            CheckoutBlock::Shipping => $this->applyShipping($request, $data),
            CheckoutBlock::Payment => $data->withPaymentMethod((int) $request->request->getString('consho_payment_method')),
            // Review is C-6; an unknown value is a form nobody built.
            default => $data,
        };

        $this->repository->saveCheckoutData($cart->id, $data, time());
    }

    private function applyAddress(Request $request, CheckoutData $data, string $shopVatId): CheckoutData
    {
        $separate = '' !== $request->request->getString('consho_separate_shipping');

        $data = $data
            ->withBilling($this->addressOf($request, 'billing'))
            ->withShipping($separate ? $this->addressOf($request, 'shipping') : new Address(), $separate)
            // Upper case and without the spaces a letterhead puts in —
            // ViesVatIdValidator::parse() accepts both, but the stored value
            // is what withVatId() compares against to decide whether an
            // earlier verdict still belongs to it.
            ->withVatId(strtoupper((string) preg_replace('/[\s-]+/u', '', $request->request->getString('consho_vat_id'))))
        ;

        /*
         * Checked when there is an ID and no verdict for it — withVatId()
         * dropped any verdict belonging to a different number, so an unchanged
         * address costs nothing. "Check again" (C15) forces one by clearing
         * the verdict first; that is the button for an unreachable VIES.
         */
        if ('' !== $request->request->getString('consho_recheck_vat')) {
            $data = $data->withVatCheck(null);
        }

        if ('' !== $data->vatId && null === $data->vatCheck) {
            $data = $data->withVatCheck($this->vatIds->check($data->vatId, '' === $shopVatId ? null : $shopVatId));
        }

        return $data;
    }

    private function addressOf(Request $request, string $prefix): Address
    {
        $values = [];

        foreach (self::ADDRESS_FIELDS as $field) {
            $values[$field] = trim($request->request->getString($prefix.'_'.$field));
        }

        $values['country'] = strtoupper($values['country']);

        return $this->addresses->normalize(new Address(...$values));
    }

    /**
     * The "wesentliche Merkmale" § 8 Abs. 2 FAGG wants directly above the
     * button: what is being bought, how much of it, and for how much.
     *
     * Formatted here, like everywhere else in Consho — a template that formats
     * money formats it differently on the next page.
     *
     * 🔴 Both reviews of 2026-09-22 found this method. The quantity was passed
     * through raw, and `tl_consho_cart_item.quantity` is `decimal(10,4)`:
     * MySQL hands back "2.0000", so the essential details read
     * "2.0000 × Wanderstock". The basket got this right in C-3 through
     * MoneyFormatter::quantity(); this did not, while its own comment claimed
     * everything was formatted. **No test saw it** — SQLite types the column
     * as NUMERIC and returns 1 for '1.0000'.
     *
     * 🔴 And the SKU is carried, for the reason CartTitles writes down itself:
     * it hands out the MAIN product's title by design, so two variants of one
     * product read alike. C-3 solved that in the basket and this block did not
     * follow — in the one place where the law asks what is being bought.
     *
     * Net for a member of a net price group, as product page and basket show
     * them (Abo-Nachlese, P75; review Abo-2, H1: until then the review was
     * the one place a net customer read gross), marked "plus VAT" in the
     * template. Gross for everybody else. The totals right below give net and
     * — except under reverse charge — gross, which is what will be charged.
     *
     * `terms` are a subscription line's conditions (Abo-2, A10) — right above
     * the button, where § 8 FAGG wants the term. Built from the same amount
     * the line shows.
     *
     * @param list<int> $groups
     *
     * @return list<array{renews: string, title: string, sku: string, position: int, quantity: string, amount: string, terms: string|null, garan: array{years: string, info: string, src: string, width: int|null, height: int|null}|null}>
     */
    private function reviewLines(CheckoutState $state, string $language, string $fallback, string $country = '', array $groups = [], bool $showsNet = false): array
    {
        if ([] === $state->cart->lines) {
            return [];
        }

        $titles = $this->titles->of(
            array_map(static fn ($line): int => $line->articleId, $state->cart->lines),
            $language,
            $fallback,
        );

        $lines = [];
        $renewed = $this->renewals?->numbersOf(array_map(static fn ($line): int => $line->renews, $state->cart->lines)) ?? [];
        $garan = $this->durability?->of(array_map(static fn ($line): int => $line->articleId, $state->cart->lines)) ?? [];

        foreach ($state->cart->lines as $line) {
            $name = $titles[$line->articleId] ?? null;

            $lines[] = [
                // Abo-7b: which subscription a renewal line continues; ''.
                'renews' => $line->renews > 0 ? ($renewed[$line->renews] ?? '#'.$line->renews) : '',
                'title' => $name->title ?? '',
                'sku' => $name->sku ?? '',
                'position' => \count($lines) + 1,
                'quantity' => $this->money->quantity($line->quantity, $language),
                'amount' => $this->money->format($showsNet ? $line->lineNet : $line->lineGross, $state->currency, $language),
                // The same amount as beside it; under reverse charge the gross
                // IS the net (CartView::withoutTax()), so that holds too.
                // The line's own tax country (DL4, review: a service is taxed
                // in the shop's country) — the one OrderFactory freezes.
                'terms' => $this->lineTerms?->of($line->subscriptionRule, $showsNet ? $line->lineNet : $line->lineGross, $state->currency, $language),
                // Block GARAN (§ 8 Abs. 1 FAGG, Z 12a): the label before the button.
                'garan' => $garan[$line->articleId] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * The number of the first subscription a renewal line can no longer
     * renew, '' when every one still can (Abo-7b, P70).
     */
    private function refusedRenewal(int $cartId, int $memberId, int $shopId): string
    {
        $renewals = array_filter($this->repository->itemsOf($cartId), static fn (CartItemRow $item): bool => $item->renews > 0);

        foreach ($renewals as $item) {
            if (!$this->renewals?->allowsPurchase($item->renews, $memberId, $shopId, $item->articleId, time())) {
                return $this->renewals?->numbersOf([$item->renews])[$item->renews] ?? '#'.$item->renews;
            }
        }

        return '';
    }

    /**
     * A chosen shipping or payment method with WHAT IT COSTS.
     *
     * 🔴 Found in the review of 2026-09-22: the block named the method and not
     * its amount. § 8 Abs. 2 FAGG points at § 4 Abs. 1 Z 4 — the total
     * including tax **and all shipping, freight and other costs** — and
     * § 312j Abs. 2 BGB says the same through Art. 246a EGBGB. A buyer saw
     * line sums, two names and a total, with the difference between them left
     * unexplained. The CHANGELOG claimed the costs were there, which made it a
     * documented hole rather than only a hole.
     *
     * A method that costs nothing says so rather than showing "€0.00": free
     * delivery is a fact about the offer, and a zero reads like a missing
     * value. A discount carries its sign already (MethodOption::$net).
     *
     * @return array{title: string, amount: string, free: bool}|null
     */
    /**
     * Formatted amounts of the options that are available and cost or save
     * something, by option id — same net/gross rule as methodLine(). A free
     * or unavailable option gets none: no "€0.00" beside it.
     *
     * @param list<MethodOption> $options
     *
     * @return array<int, string>
     */
    private function optionAmounts(array $options, CheckoutState $state, string $language, bool $net): array
    {
        $amounts = [];

        foreach ($options as $option) {
            $amount = $net ? $option->net : $option->gross;

            if ($option->available && 0 !== BigDecimal::of($amount)->compareTo(0)) {
                $amounts[$option->id] = $this->money->format($amount, $state->currency, $language);
            }
        }

        return $amounts;
    }

    /**
     * The cart rule on the review page (block H, H-4): its name, the code,
     * the amount off — the sum of the shares the totals are built from, so it
     * is the difference the buyer sees — or "free shipping"; and, where a
     * typed code does not apply here (a guest's address already used it, W5),
     * what became of it — also a minimum the basket reached and the checkout
     * does not, once the delivery country prices the goods (review H, S2):
     * never silently gone.
     *
     * @return array{title: string, code: string, amount: string, freeShipping: bool, status: string, missing: string}|null
     */
    private function discountLine(CheckoutState $state, string $language, bool $net): array|null
    {
        if (null === $state->discount && in_array($state->codeStatus, [CodeStatus::None, CodeStatus::Applied, CodeStatus::Superseded], true)) {
            return null;
        }

        $off = BigDecimal::zero();

        foreach ($state->discountShares as $share) {
            $off = $off->plus($net ? $share->net : $share->gross);
        }

        return [
            'title' => $state->discount->title ?? '',
            'code' => $state->discount->code ?? '',
            'amount' => $this->money->format((string) $off, $state->currency, $language),
            'freeShipping' => $state->discount->freeShipping ?? false,
            'status' => $state->codeStatus->value,
            'missing' => '' === $state->codeMissing ? '' : $this->money->format($state->codeMissing, $state->currency, $language),
        ];
    }

    private function methodLine(MethodOption|null $method, CheckoutState $state, string $language, bool $net): array|null
    {
        if (null === $method) {
            return null;
        }

        // Net where the goods read net — reverse charge, or the net group
        // (review H, L2) —, gross everywhere else.
        $amount = $net ? $method->net : $method->gross;

        return [
            'title' => $method->title,
            'amount' => $this->money->format($amount, $state->currency, $language),
            'free' => 0 === BigDecimal::of($amount)->compareTo(0),
        ];
    }

    /**
     * The legal texts of this site, each with the address of the page it sits
     * on, ready for the template.
     *
     * Only the headline and the address — the text itself stays behind.
     *
     * An earlier comment here claimed the text travelled along so that C-7
     * would freeze exactly what was read; the review of 2026-09-22 pointed out
     * that it does not, and that C-7 will therefore read a second time. That
     * is the honest state of it, and it is a real gap: change the terms
     * between the page being rendered and the button being pressed, and the
     * checksum still matches, because CheckoutFingerprint covers the basket
     * and not the legal texts. Closing it belongs with the freezing itself
     * (C-7), which is where both readings meet.
     *
     * @return array<string, array{headline: string, url: string|null}>
     */
    private function legalLinks(PageModel|null $root): array
    {
        if (!$root instanceof PageModel) {
            return [];
        }

        $links = [];

        foreach ($this->legalTexts->forRoot((int) $root->id) as $key => $text) {
            $links[$key] = [
                'headline' => $text->headline,
                'url' => $this->pageUrls->ofPageId($text->pageId),
            ];
        }

        return $links;
    }

    /**
     * The checksums of the legal texts, for the fingerprint (C-7, F3).
     *
     * Only the checksums, not the texts: what goes into the fingerprint has to
     * be stable and small, and `LegalText::$checksum` is already taken over
     * the RESOLVED headline and text — what the buyer actually read, not the
     * source it was written as.
     *
     * @return array<string, string> kind => checksum
     */
    private function legalChecksums(PageModel|null $root): array
    {
        if (!$root instanceof PageModel) {
            return [];
        }

        return array_map(
            static fn (LegalText $text): string => $text->checksum,
            $this->legalTexts->forRoot((int) $root->id),
        );
    }

    /**
     * The buy click: check, then say what happened (7.3, step 1).
     *
     * Four things are checked, in the order in which they get cheaper to be
     * wrong about:
     *
     * 1. the state itself — a basket that cannot be bought from never gets to
     *    the checksum;
     * 2. the terms tick box, when the shop asks for one (C18);
     * 3. the checksum, against a FRESHLY calculated state;
     * 4. — and with C-7, the transaction.
     *
     * The request token is not checked here: Contao checks it for the whole
     * request before any of this runs (10). The basket's add route is the
     * exception, and it is exempt for a reason that does not apply to a page
     * that is private and never cached.
     *
     * ── What C-7 does NOT do here ────────────────────────────────────────
     *
     * **No new basket cookie.** It was planned and turns out to be
     * unnecessary: markConverted() sets the basket's tokenHash to NULL, so the
     * next "add to basket" with the same cookie finds nothing, creates a fresh
     * basket, and the unique key it would have collided with is free. Issuing
     * a cookie as well would log the visitor out of their own basket for no
     * reason.
     *
     * **`start()` since C-11**, at the very end (see below). C-7 left it out
     * on purpose: without an order page to act on its answer, calling it
     * would have opened a provider payment nobody could return from.
     */
    private function purchase(
        Request $request,
        PageModel $page,
        Cart $cart,
        object $shop,
        CheckoutState $state,
        string $language,
        string $fallback,
        int $stepCount,
    ): string {
        $last = \sprintf('%s?consho_step=%d', strtok($request->getUri(), '?') ?: $request->getUri(), $stepCount);

        if (!$state->isReady()) {
            // Not a purchase problem but a checkout problem: the state knows
            // which block to go back to, and nextAddress() already does that.
            return $this->nextAddress($request, $cart, $shop, $state->currency, CheckoutBlock::steps($stepCount));
        }

        if ((bool) $shop->termsRequired && '' === $request->request->getString('consho_terms')) {
            return $last.'&consho_purchase='.PurchaseProblem::TermsNotAccepted->value;
        }

        /*
         * Read HERE, not in OrderPlacer, and that is the point of F3: the
         * checksum has to be compared against the same texts the order will
         * freeze. Reading them twice inside one request costs one query and
         * closes the window between "what the buyer read" and "what gets
         * written down".
         */
        $legalChecksums = $this->legalChecksums($this->shops->rootOf($page));

        if (!$this->fingerprints->matches($state, $request->request->getString('consho_fingerprint'), $legalChecksums)) {
            return $last.'&consho_purchase='.PurchaseProblem::ChecksumMismatch->value;
        }

        $orderPage = $this->pageUrls->orderOfPage($page);

        if (null === $orderPage) {
            /*
             * Cannot normally happen — CheckoutReadiness blocks a checkout
             * whose start point has no order page, and that runs before
             * anything on this page is rendered. It is checked anyway because
             * the alternative is placing an order the customer is then shown
             * no confirmation of.
             */
            return $last.'&consho_purchase='.PurchaseProblem::NoOrderPage->value;
        }

        /*
         * The moment of the tick, kept for the order (C18). Written here and
         * not at the tick itself: a box that was ticked and the page reloaded
         * was not agreed to at the time of THIS order.
         */
        $now = time();

        if ((bool) $shop->termsRequired) {
            /*
             * 🔴 From $state->data, NOT from the cart row again.
             *
             * Until the address prefill the two were the same thing, so
             * re-reading was free. They are not any more: stateOf() fills an
             * untouched address from the member record IN MEMORY, and nothing
             * is written on a GET. Reading the row here handed back the empty
             * address the customer never saw and never had to correct — and
             * the checksum had already agreed, because it is computed from the
             * same prefilled state on both ways.
             *
             * The result was an order with no billing address whenever a shop
             * required terms and the customer left the prefilled form alone.
             * Found by the review of 2026-09-23; the acceptance on c5 could
             * not see it, because termsRequired is off on both test shops.
             *
             * $state->data is exactly what the checksum confirmed. Saving it
             * writes the prefill along with the tick, which is correct: from
             * here on the address IS the customer's, not a suggestion.
             */
            $data = $state->data->withTermsAcceptedAt($now);
            $this->repository->saveCheckoutData($cart->id, $data, $now);
            $state = $state->withData($data);
        }

        /*
         * The consent to the early start of digital content (F-3, F15) — the
         * same rule as the terms: the moment of THIS click. Voluntary (F16):
         * without it nothing is refused, the digital lines wait for the end
         * of the withdrawal period instead.
         */
        if ($this->asksDigitalConsent($shop, $state) && '' !== $request->request->getString('consho_digital_consent')) {
            $data = $state->data->withDigitalConsentAt($now);
            $this->repository->saveCheckoutData($cart->id, $data, $now);
            $state = $state->withData($data);
        }

        try {
            $placed = $this->orders->place(
                $cart->id,
                (int) $page->rootId,
                (int) $shop->id,
                (string) $shop->country,
                $language,
                $fallback,
                $this->carts->memberId(),
                $this->audiences->forShop((int) $shop->id)->groups,
                $state,
                $now,
                (string) ($shop->vatId ?? ''),
                $this->isBusiness($shop, $state),
            );
        } catch (ProductGoneFromCatalogue|PriceNotFound) {
            // The catalogue changed between the review step and the click.
            // Same shape as a checksum mismatch — nothing was ordered, and the
            // basket page will say which line is the problem.
            return $last.'&consho_purchase='.PurchaseProblem::ChecksumMismatch->value;
        } catch (CartRuleExhaustedAtPurchase) {
            // Block H (H-4): the last use went to someone else.
            return $last.'&consho_purchase='.PurchaseProblem::DiscountExhausted->value;
        } catch (VoucherChangedAtPurchase) {
            // E4: the transaction rolled back; the page shows the vouchers as they are now.
            return $last.'&consho_purchase='.PurchaseProblem::VoucherChanged->value;
        } catch (StockShortAtPurchase) {
            // Block G (G5): the last piece went to someone else. Nothing was
            // ordered; the basket says how many are left.
            return $last.'&consho_purchase='.PurchaseProblem::StockShort->value;
        } catch (
            PaymentMethodGoneAtPurchase
            |BasketGoneAtPurchase
            |LegalTextGoneAtPurchase
            |TotalDisagreesWithCheckout
            |NumberRangeNotConfigured
            |SubscriptionWithoutAccount
            |SubscriptionRuleGoneAtPurchase
            |SubscriptionRenewalRefused $e
        ) {
            /*
             * 🔴 Logged since C-12 (2026-09-26). The comment below promised
             * it from C-7 on and nothing wrote it: at the acceptance a
             * reverse charge purchase was refused, the buyer read "could not
             * be placed", and the log was empty — the cause had to be found
             * in the code.
             */
            $this->logger->error(\sprintf('Purchase refused (%s): %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()), ['exception' => $e]);

            /*
             * Eight ways the transaction refuses, one message. They differ in
             * what an OPERATOR has to do — and that is in the log with the
             * exception that carries it, not in a sentence a customer reads.
             *
             * NumberRangeNotConfigured is caught although the readiness check
             * now demands the `order` range (F4): the check runs before the
             * element renders, this runs at the click, and a range deleted in
             * between would otherwise be a 500 on a page that had already
             * promised to work.
             */
            return $last.'&consho_purchase='.PurchaseProblem::CouldNotPlace->value;
        }

        $viewUrl = \sprintf('%s?consho_order=%s', $orderPage, $placed->viewToken);

        /*
         * The confirmation (C-9), after the commit and in this request: the
         * view token exists in plaintext only here, and the mail links the
         * order page with it. Never for the double click (7.3 step 3) — that
         * request found an order already placed, and its buyer already has
         * the mail the first request sent.
         *
         * send() never throws. A mail server that is down leaves
         * confirmationSentAt empty for the back end to warn about; the buyer
         * still lands on the order page.
         */
        if ($placed->wasAlreadyPlaced) {
            return $viewUrl;
        }

        // The MAIL's token, not the redirect's (C-10): only a click that
        // came through the mail proves the address.
        $this->confirmations->send($placed->orderId, (int) $page->rootId, \sprintf('%s?consho_order=%s', $orderPage, $placed->mailToken), $now);

        /*
         * Digital lines due at once (F-4) — after the confirmation, which
         * records the consent on a durable medium before anything is supplied
         * (§ 7 Abs. 3 FAGG, § 312f Abs. 2 BGB). A failure here costs the
         * customer nothing but time: the cron releases what is due.
         */
        try {
            $this->releases?->releaseDue($placed->orderId, $now);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Digital lines of order %d could not be released: %s', $placed->orderId, $e->getMessage()), ['exception' => $e]);
        }

        /*
         * start() of the handler, last (7.3 step 4, C-11) — after the mail,
         * so a provider that is slow or down cannot keep the confirmation
         * from going out. Never for the double click: the first request
         * started the payment, a second attempt could take the money twice.
         *
         * `redirect` → the provider; `failed` → the order page saying so,
         * "try again" right there; `completed` → the order page as before.
         */
        $start = $this->payments->start($placed->orderId, $now);

        if (PaymentStartOutcome::Redirect === $start?->outcome) {
            return $start->url;
        }

        if (PaymentStartOutcome::Failed === $start?->outcome) {
            return $viewUrl.'&'.http_build_query([OrderController::NOTICE_PARAM => 'paymentFailed']);
        }

        return $viewUrl;
    }

    /**
     * Where a submit lands: back to the block a problem points at, otherwise
     * the next step — and the last step stays put, because what follows it is
     * the buy click (C-6), not another step.
     *
     * @param list<list<CheckoutBlock>> $steps
     */
    private function nextAddress(Request $request, Cart $cart, object $shop, string $currency, array $steps): string
    {
        $page = $request->attributes->get('pageModel');
        $language = $page instanceof PageModel ? (string) $page->language : '';
        $shopId = (int) $shop->id;
        $fallback = $this->languages->fallbackOf($shopId);
        $state = $this->stateOf($cart, $shop, $currency, '' === $language ? $fallback : $language, $fallback);

        $current = self::stepOf($request, \count($steps));
        $target = $current;
        $blocked = $state->blockedAt();

        if (null !== $blocked) {
            foreach ($steps as $index => $blocks) {
                if (\in_array($blocked, $blocks, true)) {
                    $target = $index + 1;

                    break;
                }
            }
        } elseif ($current < \count($steps)) {
            ++$target;
        }

        $url = strtok($request->getUri(), '?');

        return \sprintf('%s?consho_step=%d', false === $url ? $request->getUri() : $url, $target);
    }

    private function stateOf(Cart|null $cart, object $shop, string $currency, string $language, string $fallback): CheckoutState
    {
        $shopId = (int) $shop->id;
        $items = null === $cart ? [] : $this->repository->itemsOf($cart->id);
        $data = null === $cart ? new CheckoutData() : $this->repository->checkoutDataOf($cart->id);

        /*
         * What the member record already says, for an address nobody has
         * touched yet — and HERE, because this method serves both the
         * rendering and the buy click. The click therefore recalculates
         * exactly what the form showed, and CheckoutFingerprint agrees without
         * anything being written on a GET (C-6 built the calculator to be free
         * of side effects; this keeps that promise).
         */
        $data = $this->memberDefaults->applyTo($data, $this->carts->memberId());

        $audience = $this->audiences->forShop($shopId);

        return $this->calculator->calculate(
            $shopId,
            $items,
            $currency,
            (string) $shop->country,
            $this->deliveryCountriesOf($shop),
            $audience->groups,
            $data,
            time(),
            $this->ageLimits->of(array_map(static fn (CartItemRow $item): int => $item->articleId, $items)),
            MethodSettings::selectedIds($shop->ageVerifiedGroups ?? null),
            $language,
            $fallback,
            (string) ($shop->vatId ?? ''),
            // "Business" for a sameTerm plan (Abo-2, P11) — with a verified
            // VAT ID, which the calculator reads off the data itself.
            $audience->showsNet,
            // Block H (H-4): the code typed in the basket (W1), and who buys (W5).
            null === $cart ? '' : $this->repository->discountCodeOf($cart->id),
            $this->carts->memberId(),
            // Block Wertgutscheine (E3): the vouchers typed in the basket.
            null === $cart ? [] : $this->repository->vouchersOf($cart->id),
            // Block R (O1): where a distance sale within the EU is taxed.
            (bool) ($shop->taxAtDestination ?? false),
        );
    }

    /**
     * Die Länder zur Auswahl: die Lieferländer des Shops, sonst alle, die
     * Contao kennt.
     *
     * ⚠️ Eine AUSWAHL, kein Freitextfeld. Im Review vom 2026-09-22 gemessen:
     * „Österreich" statt „AT" ergibt keinen Steuersatz, damit keinen Preis,
     * und der Besucher liest „Für einen Artikel gibt es derzeit keinen Preis.
     * Bitte entfernen Sie ihn." — eine Meldung über den Warenkorb, für einen
     * Tippfehler in der Adresse.
     *
     * @return array<string, string> ISO-Code => übersetzter Name
     */
    private function countriesFor(object $shop, string $language, bool $byShipping = true): array
    {
        $all = $this->countries->all($language);
        $allowed = $this->deliveryCountriesOf($shop);

        /*
         * Und davon nur, wohin eine Versandart liefert (E-5, V3; Spec A 707).
         * Nicht still: Ein Lieferland ohne Versandart nennt die Startseite
         * (ShippingCountryCoverage).
         */
        $served = $byShipping ? $this->shippingCountries?->servedBy((int) $shop->id) : null;

        if (null !== $served) {
            $allowed = [] === $allowed ? $served : array_values(array_intersect($allowed, $served));

            // Kein Land übrig: nicht „alle" zeigen, sondern keins — die
            // Versandarten liefern nirgends hin, wohin der Shop liefert.
            return array_intersect_key($all, array_flip($allowed));
        }

        if ([] === $allowed) {
            return $all;
        }

        return array_intersect_key($all, array_flip($allowed));
    }

    /**
     * @return list<string>
     */
    private function deliveryCountriesOf(object $shop): array
    {
        $stored = $shop->deliveryCountries ?? null;
        $countries = \is_string($stored) ? (unserialize($stored, ['allowed_classes' => false]) ?: []) : $stored;

        if (!\is_array($countries)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $code): string => strtoupper(trim((string) $code)), $countries));
    }
}
